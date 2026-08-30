<?php

namespace App\Support\Fleet;

use App\Actions\Reservations\SearchAvailableVehicles;
use App\Enums\DemandForecastExecutionStatus;
use App\Exceptions\FleetReallocationPlanningException;
use App\Models\Agency;
use App\Models\DemandForecastExecutionRun;
use App\Support\Intelligence\DemandForecasting\DemandForecastContract;
use App\Support\Intelligence\DemandForecasting\DemandForecastPlanningUnits;
use App\Support\Intelligence\FleetReallocation\DemandForecastCoverageValidator;
use App\Support\Intelligence\FleetReallocation\FleetReallocationContract;
use App\Support\Intelligence\FleetReallocation\FleetReallocationReadiness;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use JsonException;

class BuildOperationalFleetReallocationSnapshot
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly FleetReallocationReadiness $readiness,
        private readonly AgencyDistanceMatrixBuilder $matrixBuilder,
        private readonly DemandForecastCoverageValidator $forecastCoverage,
        private readonly DemandForecastPlanningUnits $planningUnits,
        private readonly SearchAvailableVehicles $availability,
    ) {}

    public function build(): OperationalFleetReallocationSnapshot
    {
        $agencies = Agency::query()
            ->where('is_active', true)
            ->orderBy('id')
            ->sharedLock()
            ->get();
        if ($agencies->count() < 2 || $agencies->count() > 4) {
            throw new FleetReallocationPlanningException('AGENCY_COUNT_UNSUPPORTED');
        }

        if (! $this->readiness->evaluate($agencies)->ready()) {
            throw new FleetReallocationPlanningException('READINESS_NOT_READY');
        }

        $matrix = $this->matrixBuilder->build($agencies);
        if (! $matrix->complete() || $matrix->fingerprint === null) {
            throw new FleetReallocationPlanningException('DISTANCE_MATRIX_INVALID');
        }

        [$referenceDate, $forecastByAgency] = $this->forecasts($agencies);
        $asOf = CarbonImmutable::parse($referenceDate, DemandForecastContract::TIMEZONE)->startOfDay();
        $agencyRows = [];
        foreach ($agencies as $position => $agency) {
            $agencyRows[] = [
                'agency_id' => (int) $agency->getKey(),
                'node_ref' => sprintf('NODE-%03d', $position + 1),
                'name' => (string) $agency->name,
                'forecast_execution_run_id' => (int) $forecastByAgency[$agency->getKey()]->getKey(),
                'forecast_run_id' => (int) $forecastByAgency[$agency->getKey()]->forecastRun->getKey(),
            ];
        }

        $days = [];
        foreach (range(1, 7) as $horizon) {
            $date = $asOf->addDays($horizon);
            $nodes = [];
            foreach ($agencyRows as $agencyRow) {
                $execution = $forecastByAgency[$agencyRow['agency_id']];
                $forecast = $execution->forecastRun->forecasts
                    ->sole(fn ($row): bool => $row->horizon === $horizon);
                $conditionalMean = (string) $forecast->conditional_mean;
                $planningUnits = $this->planningUnits->convert($conditionalMean)->planningVehicleUnits;
                $availableUnits = $this->availability
                    ->query($agencyRow['agency_id'], $date, $date->addDay())
                    ->count();

                $nodes[] = [
                    'agency_id' => $agencyRow['agency_id'],
                    'node_ref' => $agencyRow['node_ref'],
                    'forecast_id' => (int) $forecast->getKey(),
                    'conditional_mean' => $conditionalMean,
                    'planning_vehicle_units' => $planningUnits,
                    'available_vehicle_units' => $availableUnits,
                    'transferable_surplus' => max(0, $availableUnits - $planningUnits),
                    'uncovered_need' => max(0, $planningUnits - $availableUnits),
                ];
            }

            $days[] = [
                'horizon' => $horizon,
                'date' => $date->toDateString(),
                'nodes' => $nodes,
            ];
        }

        $lanes = [];
        foreach ($agencyRows as $origin) {
            foreach ($agencyRows as $destination) {
                if ($origin['agency_id'] === $destination['agency_id']) {
                    continue;
                }
                $distance = $matrix->matrix[$origin['agency_id']][$destination['agency_id']] ?? null;
                if (! is_string($distance)) {
                    throw new FleetReallocationPlanningException('DISTANCE_MATRIX_INVALID');
                }
                $lanes[] = [
                    'from_agency_id' => $origin['agency_id'],
                    'from_node_ref' => $origin['node_ref'],
                    'to_agency_id' => $destination['agency_id'],
                    'to_node_ref' => $destination['node_ref'],
                    'distance_km' => $distance,
                    'unit_cost_centimes' => $this->distanceCostCentimes($distance),
                ];
            }
        }

        $runtimeSha256 = $this->runtimeSha256();
        $generatedAt = now('UTC')->format('Y-m-d\TH:i:s\Z');
        $core = [
            'schema_version' => '1.0.0',
            'source_kind' => 'rentfleet_operational',
            'tenant_id' => $this->context->tenantId(),
            'reference_date' => $referenceDate,
            'generated_at' => $generatedAt,
            'solver' => [
                'name' => FleetReallocationContract::SOLVER_NAME,
                'version' => FleetReallocationContract::SOLVER_VERSION,
                'runtime_sha256' => $runtimeSha256,
            ],
            'agencies' => $agencyRows,
            'days' => $days,
            'lanes' => $lanes,
            'distance_matrix_fingerprint' => $matrix->fingerprint,
        ];
        $fingerprintPayload = $core;
        unset($fingerprintPayload['generated_at']);
        $inputFingerprint = hash('sha256', $this->canonicalJson($fingerprintPayload));
        $core['input_fingerprint'] = $inputFingerprint;

        return new OperationalFleetReallocationSnapshot(
            payload: $core,
            inputFingerprint: $inputFingerprint,
            distanceMatrixFingerprint: $matrix->fingerprint,
            runtimeSha256: $runtimeSha256,
            referenceDate: $referenceDate,
        );
    }

    /**
     * @param  Collection<int, Agency>  $agencies
     * @return array{string, array<int, DemandForecastExecutionRun>}
     */
    private function forecasts(Collection $agencies): array
    {
        $referenceDate = null;
        $executions = [];
        foreach ($agencies as $agency) {
            $execution = DemandForecastExecutionRun::query()
                ->with('forecastRun.forecasts')
                ->where('agency_id', $agency->getKey())
                ->where('status', DemandForecastExecutionStatus::Succeeded->value)
                ->latest('finished_at')
                ->latest('id')
                ->sharedLock()
                ->first();
            if ($execution === null || ! $this->forecastCoverage->compatible($execution)) {
                throw new FleetReallocationPlanningException('FORECAST_COVERAGE_INVALID');
            }

            $candidate = $execution->forecastRun->as_of_date?->toDateString();
            if ($candidate === null || ($referenceDate !== null && $referenceDate !== $candidate)) {
                throw new FleetReallocationPlanningException('FORECAST_REFERENCE_DATE_MISMATCH');
            }
            $referenceDate = $candidate;
            $executions[(int) $agency->getKey()] = $execution;
        }

        if ($referenceDate === null) {
            throw new FleetReallocationPlanningException('FORECAST_COVERAGE_INVALID');
        }

        return [$referenceDate, $executions];
    }

    public function distanceCostCentimes(string $distance): int
    {
        if (preg_match('/^(?:0|[1-9][0-9]{0,4})\.[0-9]{3}$/D', $distance) !== 1) {
            throw new FleetReallocationPlanningException('DISTANCE_VALUE_INVALID');
        }
        [$whole, $fraction] = explode('.', $distance);
        $milliKilometres = ((int) $whole * 1000) + (int) $fraction;
        if ($milliKilometres < 1) {
            throw new FleetReallocationPlanningException('DISTANCE_VALUE_INVALID');
        }

        return intdiv(
            ($milliKilometres * FleetReallocationContract::RELOCATION_COST_CENTIMES_PER_KM) + 500,
            1000,
        );
    }

    private function runtimeSha256(): string
    {
        $files = [
            (string) config('intelligence.fleet_reallocation.runtime_script'),
            base_path('scripts/intelligence/qualify_fleet_reallocation.py'),
        ];
        $hashes = [];
        foreach ($files as $file) {
            $hash = is_file($file) ? hash_file('sha256', $file) : false;
            if (! is_string($hash)) {
                throw new FleetReallocationPlanningException('RUNTIME_CONFIGURATION_INVALID');
            }
            $hashes[] = $hash;
        }

        return hash('sha256', implode('|', $hashes));
    }

    /** @param array<string, mixed> $value */
    private function canonicalJson(array $value): string
    {
        $sort = function (mixed $item) use (&$sort): mixed {
            if (! is_array($item)) {
                return $item;
            }
            if (! array_is_list($item)) {
                ksort($item, SORT_STRING);
            }
            foreach ($item as $key => $child) {
                $item[$key] = $sort($child);
            }

            return $item;
        };

        try {
            return json_encode($sort($value), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        } catch (JsonException) {
            throw new FleetReallocationPlanningException('SNAPSHOT_ENCODING_FAILED');
        }
    }
}
