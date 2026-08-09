<?php

namespace App\Support\Intelligence\J11;

use App\Enums\J11AdvisoryModule;
use DateTimeImmutable;
use Throwable;

final class J11SyntheticFixtureValidator
{
    private const TOP_LEVEL_KEYS = [
        'advisory',
        'audit_event',
        'contract_id',
        'created_at',
        'human_decision',
        'idempotency',
        'module_id',
        'record_id',
        'research_status',
        'schema_version',
        'scope',
    ];

    private const FORBIDDEN_KEYS = [
        'vin',
        'cin',
        'permis',
        'customer_id',
        'customer_name',
        'email',
        'phone',
        'latitude',
        'longitude',
        'gps',
    ];

    public function __construct(private readonly J11CanonicalPayload $canonicalPayload) {}

    /** @param array<string, mixed> $record */
    public function validate(J11AdvisoryModule $module, array $record): J11FixtureValidation
    {
        $research = $this->object($record['research_status'] ?? null);
        $scope = $this->object($record['scope'] ?? null);
        $feature = $this->object($scope['feature_flag'] ?? null);
        $idempotency = $this->object($record['idempotency'] ?? null);
        $decision = $this->object($record['human_decision'] ?? null);
        $audit = $this->object($record['audit_event'] ?? null);
        $auditActor = $this->object($audit['who'] ?? null);
        $auditWhat = $this->object($audit['what'] ?? null);
        $advisory = $this->object($record['advisory'] ?? null);
        $digest = $this->canonicalPayload->digest($record);
        $topLevelKeys = array_keys($record);
        sort($topLevelKeys, SORT_STRING);

        $checks = [
            'COMMON_TOP_LEVEL_CLOSED' => $topLevelKeys === self::TOP_LEVEL_KEYS,
            'COMMON_SCHEMA_VERSION' => ($record['schema_version'] ?? null) === '1.0.0',
            'COMMON_CONTRACT_ID' => ($record['contract_id'] ?? null) === $module->contractId(),
            'COMMON_MODULE_ID' => ($record['module_id'] ?? null) === $module->value,
            'COMMON_RECORD_UUID' => $this->uuid($record['record_id'] ?? null),
            'COMMON_CREATED_AT' => $this->dateTime($record['created_at'] ?? null),
            'COMMON_GATE_PRESERVED' => ($research['gate_decision'] ?? null) === $module->gateDecision(),
            'COMMON_AUDIT_SCORE_PRESERVED' => ($research['audit_score'] ?? null) === $module->auditScore(),
            'COMMON_NO_PUBLIC_OUTPUT_IMPORT' => ($research['historical_public_output_import_allowed'] ?? null) === false,
            'COMMON_NOT_READY_FOR_SAAS' => ($research['ready_for_saas'] ?? null) === false,
            'COMMON_NOT_PRODUCTION' => ($research['production_allowed'] ?? null) === false,
            'COMMON_OFFLINE_DEMO' => ($scope['environment'] ?? null) === 'offline_contract_demo',
            'COMMON_SYNTHETIC_FIXTURE' => ($scope['synthetic_fixture'] ?? null) === true,
            'COMMON_SYNTHETIC_TENANT' => $this->matches($scope['tenant_ref'] ?? null, '/^SYNTH-TENANT-[0-9]{3}$/'),
            'COMMON_SYNTHETIC_AGENCY' => $this->matches($scope['agency_ref'] ?? null, '/^SYNTH-AGENCY-[0-9]{3}$/'),
            'COMMON_FEATURE_KEY' => ($feature['key'] ?? null) === $module->featureKey(),
            'COMMON_FEATURE_FLAG_DISABLED' => ($feature['enabled'] ?? null) === false,
            'COMMON_FEATURE_DEFAULT_DISABLED' => ($feature['default_state'] ?? null) === 'disabled',
            'COMMON_NO_AUTOMATIC_ACTION' => ($scope['automatic_action_allowed'] ?? null) === false,
            'COMMON_HUMAN_REQUIRED' => ($scope['human_decision_required'] ?? null) === true,
            'COMMON_NO_REAL_CUSTOMER_DATA' => ($scope['contains_real_customer_data'] ?? null) === false,
            'COMMON_NO_COORDINATES' => ($scope['contains_coordinates'] ?? null) === false,
            'COMMON_IDEMPOTENCY_UUID' => $this->uuid($idempotency['key'] ?? null),
            'COMMON_IDEMPOTENCY_POLICY' => ($idempotency['policy'] ?? null) === 'SAME_KEY_SAME_PAYLOAD_ONLY',
            'COMMON_IDEMPOTENCY_DIGEST' => ($idempotency['canonical_payload_sha256'] ?? null) === $digest,
            'COMMON_AUDIT_DIGEST' => ($audit['canonical_payload_sha256'] ?? null) === $digest,
            'COMMON_AUDIT_NO_SENSITIVE_DATA' => ($audit['contains_sensitive_data'] ?? null) === false,
            'COMMON_DECISION_NO_OPERATIONAL_EFFECT' => ($decision['effect'] ?? null) === 'NO_OPERATIONAL_ACTION',
            'COMMON_COMPUTATION_NOT_RUN' => ($advisory['computation_status'] ?? null) === 'not_run_synthetic_contract_fixture',
            'COMMON_NO_FORBIDDEN_KEYS' => ! $this->containsForbiddenKey($record),
        ];

        $status = $decision['status'] ?? null;
        $checks['COMMON_DECISION_STATUS'] = in_array($status, ['pending', 'accepted_for_demo_review', 'rejected'], true);
        if ($status === 'pending') {
            $checks['COMMON_PENDING_SYSTEM_ACTOR'] = ($auditActor['actor_type'] ?? null) === 'system_demo';
            $checks['COMMON_PENDING_OUTCOME'] = ($auditWhat['outcome'] ?? null) === 'pending_review';
        } else {
            $checks['COMMON_DECIDED_HUMAN_ACTOR'] = ($auditActor['actor_type'] ?? null) === 'human_demo';
            $checks['COMMON_DECIDED_ACTOR_MATCH'] = ($auditActor['actor_ref'] ?? null) === ($decision['human_actor_ref'] ?? null);
            $checks['COMMON_DECIDED_TIME'] = ($audit['when'] ?? null) === ($decision['decided_at'] ?? null);
            $checks['COMMON_DECIDED_OUTCOME'] = ($auditWhat['outcome'] ?? null) === ($status === 'rejected' ? 'human_rejected_demo' : 'human_accepted_demo');
        }

        return new J11FixtureValidation([
            ...$checks,
            ...$this->moduleChecks($module, $advisory),
        ]);
    }

    /**
     * @param  array<string, mixed>  $advisory
     * @return array<string, bool>
     */
    private function moduleChecks(J11AdvisoryModule $module, array $advisory): array
    {
        return match ($module) {
            J11AdvisoryModule::DemandForecast => [
                'DEMAND_BUCKET_ORDER' => $this->before($advisory['time_bucket_start'] ?? null, $advisory['time_bucket_end'] ?? null),
                'DEMAND_QUANTILES_ORDER' => is_numeric($advisory['demand_p50_demo'] ?? null)
                    && is_numeric($advisory['demand_p90_demo'] ?? null)
                    && (float) $advisory['demand_p90_demo'] >= (float) $advisory['demand_p50_demo'],
                'DEMAND_NO_CONFIDENCE_PERCENTAGE' => ($this->object($advisory['explanation'] ?? null)['confidence_percentage_claimed'] ?? null) === false,
            ],
            J11AdvisoryModule::FleetOptimization => [
                'FLEET_WINDOW_ORDER' => $this->before($advisory['plan_window_start'] ?? null, $advisory['plan_window_end'] ?? null),
                'FLEET_SOLVER_NOT_EXECUTED' => ($advisory['solver_executed'] ?? null) === false,
                'FLEET_EXECUTION_DISABLED' => ($advisory['execution_allowed'] ?? null) === false,
                'FLEET_UNIQUE_VEHICLES' => $this->uniqueMoveVehicles($advisory['moves'] ?? null),
                'FLEET_DISTINCT_AGENCIES' => $this->distinctMoveAgencies($advisory['moves'] ?? null),
                'FLEET_MOVES_WITHIN_WINDOW' => $this->movesWithinWindow(
                    $advisory['moves'] ?? null,
                    $advisory['plan_window_start'] ?? null,
                    $advisory['plan_window_end'] ?? null,
                ),
            ],
            J11AdvisoryModule::PredictiveMaintenance => [
                'MAINTENANCE_WINDOW_ORDER' => $this->before($advisory['assessment_window_start'] ?? null, $advisory['assessment_window_end'] ?? null),
                'MAINTENANCE_NOT_FAILURE_PROBABILITY' => ($advisory['failure_probability_claimed'] ?? null) === false,
                'MAINTENANCE_NO_AUTOMATIC_WORK_ORDER' => ($advisory['automatic_work_order_allowed'] ?? null) === false,
                'MAINTENANCE_MANUAL_INSPECTION_ONLY' => ($advisory['recommended_human_action'] ?? null) === 'manual_inspection_only',
                'MAINTENANCE_NOT_PROBABILITY_SCORE' => ($advisory['score_kind'] ?? null) === 'synthetic_rank_not_failure_probability',
            ],
            J11AdvisoryModule::RentalUsageAnomaly => [
                'ANOMALY_ATYPICAL_NOT_FRAUD' => ($advisory['semantic_label'] ?? null) === 'atypical_usage_for_human_review_not_fraud',
                'ANOMALY_NOT_PROBABILITY' => ($advisory['score_kind'] ?? null) === 'synthetic_rank_not_probability',
                'ANOMALY_NO_FRAUD_CLAIM' => ($advisory['fraud_claimed'] ?? null) === false,
                'ANOMALY_NO_AUTOMATIC_SANCTION' => ($advisory['automatic_sanction_allowed'] ?? null) === false,
                'ANOMALY_APPROVED_REVIEW_BUDGET' => in_array($advisory['review_budget_percent_demo'] ?? null, [0.5, 1, 2], true),
            ],
        };
    }

    private function before(mixed $start, mixed $end): bool
    {
        if (! $this->dateTime($start) || ! $this->dateTime($end)) {
            return false;
        }

        return new DateTimeImmutable($start) < new DateTimeImmutable($end);
    }

    private function dateTime(mixed $value): bool
    {
        if (! is_string($value)) {
            return false;
        }

        try {
            new DateTimeImmutable($value);

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    private function uuid(mixed $value): bool
    {
        return $this->matches($value, '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i');
    }

    private function matches(mixed $value, string $pattern): bool
    {
        return is_string($value) && preg_match($pattern, $value) === 1;
    }

    /** @return array<string, mixed> */
    private function object(mixed $value): array
    {
        return is_array($value) && ! array_is_list($value) ? $value : [];
    }

    private function containsForbiddenKey(mixed $value): bool
    {
        if (! is_array($value)) {
            return false;
        }

        foreach ($value as $key => $child) {
            if (is_string($key) && in_array(strtolower($key), self::FORBIDDEN_KEYS, true)) {
                return true;
            }

            if ($this->containsForbiddenKey($child)) {
                return true;
            }
        }

        return false;
    }

    private function uniqueMoveVehicles(mixed $moves): bool
    {
        if (! is_array($moves) || ! array_is_list($moves)) {
            return false;
        }

        $references = array_map(fn (mixed $move): mixed => is_array($move) ? ($move['vehicle_ref'] ?? null) : null, $moves);

        return ! in_array(null, $references, true) && count($references) === count(array_unique($references));
    }

    private function distinctMoveAgencies(mixed $moves): bool
    {
        if (! is_array($moves) || ! array_is_list($moves)) {
            return false;
        }

        foreach ($moves as $move) {
            if (! is_array($move)
                || ! isset($move['from_agency_ref'], $move['to_agency_ref'])
                || $move['from_agency_ref'] === $move['to_agency_ref']) {
                return false;
            }
        }

        return true;
    }

    private function movesWithinWindow(mixed $moves, mixed $start, mixed $end): bool
    {
        if (! is_array($moves) || ! array_is_list($moves) || ! $this->dateTime($start) || ! $this->dateTime($end)) {
            return false;
        }

        $startAt = new DateTimeImmutable($start);
        $endAt = new DateTimeImmutable($end);

        foreach ($moves as $move) {
            if (! is_array($move) || ! $this->dateTime($move['planned_at'] ?? null)) {
                return false;
            }

            $plannedAt = new DateTimeImmutable($move['planned_at']);

            if ($plannedAt < $startAt || $plannedAt > $endAt) {
                return false;
            }
        }

        return true;
    }
}
