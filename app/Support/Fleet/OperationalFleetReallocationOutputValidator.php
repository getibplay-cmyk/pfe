<?php

namespace App\Support\Fleet;

use App\Exceptions\FleetReallocationPlanningException;
use App\Support\Intelligence\FleetReallocation\FleetReallocationContract;
use JsonException;

class OperationalFleetReallocationOutputValidator
{
    /**
     * @param  array<string, mixed>  $snapshot
     * @return array{payload: array<string, mixed>, recommendations: list<array<string, mixed>>, outcome: string}
     */
    public function validate(string $json, array $snapshot, string $runId): array
    {
        try {
            $payload = json_decode($json, true, 128, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
        } catch (JsonException) {
            throw new FleetReallocationPlanningException('SOLVER_OUTPUT_INVALID');
        }
        $payload = $this->object($payload, [
            'schema_version', 'source_kind', 'run_id', 'generated_at',
            'solver_name', 'solver_version', 'solver_status', 'days',
        ]);
        $this->same($payload['schema_version'], '1.0.0');
        $this->same($payload['source_kind'], 'rentfleet_operational');
        $this->same($payload['run_id'], $runId);
        $this->same($payload['solver_name'], FleetReallocationContract::SOLVER_NAME);
        $this->same($payload['solver_version'], FleetReallocationContract::SOLVER_VERSION);
        $this->same($payload['solver_status'], FleetReallocationContract::SOLVER_STATUS);
        if (! is_string($payload['generated_at'])
            || preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}Z$/D', $payload['generated_at']) !== 1) {
            $this->fail();
        }

        if (! is_array($payload['days']) || count($payload['days']) !== 7 || ! array_is_list($payload['days'])) {
            $this->fail();
        }

        $lanes = [];
        foreach ($snapshot['lanes'] ?? [] as $lane) {
            if (! is_array($lane)) {
                $this->fail();
            }
            $lanes[$lane['from_node_ref'].'|'.$lane['to_node_ref']] = $lane;
        }
        $agencyByNode = [];
        foreach ($snapshot['agencies'] ?? [] as $agency) {
            if (! is_array($agency)) {
                $this->fail();
            }
            $agencyByNode[$agency['node_ref']] = (int) $agency['agency_id'];
        }

        $recommendations = [];
        $totalUncovered = 0;
        foreach ($payload['days'] as $position => $dayValue) {
            $day = $this->object($dayValue, [
                'horizon', 'date', 'solver_status', 'solver_runtime_ms',
                'unserved_need', 'recommendations',
            ]);
            $expected = $snapshot['days'][$position] ?? null;
            if (! is_array($expected)
                || $day['horizon'] !== $position + 1
                || $day['date'] !== $expected['date']
                || $day['solver_status'] !== FleetReallocationContract::SOLVER_STATUS
                || ! is_int($day['unserved_need'])
                || $day['unserved_need'] < 0
                || ! $this->runtimeDecimal($day['solver_runtime_ms'])
                || ! is_array($day['recommendations'])
                || ! array_is_list($day['recommendations'])) {
                $this->fail();
            }

            $nodes = [];
            foreach ($expected['nodes'] as $node) {
                $nodes[$node['node_ref']] = $node;
                $totalUncovered += (int) $node['uncovered_need'];
            }
            $outbound = [];
            $inbound = [];
            $seen = [];
            foreach ($day['recommendations'] as $value) {
                $move = $this->object($value, [
                    'from_node_ref', 'to_node_ref', 'vehicle_units',
                    'distance_km', 'unit_cost_centimes',
                ]);
                $key = $move['from_node_ref'].'|'.$move['to_node_ref'];
                $lane = $lanes[$key] ?? null;
                if (! is_array($lane)
                    || isset($seen[$key])
                    || ! isset($nodes[$move['from_node_ref']], $nodes[$move['to_node_ref']])
                    || ! is_int($move['vehicle_units'])
                    || $move['vehicle_units'] < 1
                    || $move['distance_km'] !== $lane['distance_km']
                    || ! is_int($move['unit_cost_centimes'])
                    || $move['unit_cost_centimes'] !== $lane['unit_cost_centimes']) {
                    $this->fail();
                }
                $seen[$key] = true;
                $outbound[$move['from_node_ref']] = ($outbound[$move['from_node_ref']] ?? 0) + $move['vehicle_units'];
                $inbound[$move['to_node_ref']] = ($inbound[$move['to_node_ref']] ?? 0) + $move['vehicle_units'];
                $recommendations[] = [
                    'horizon' => $day['horizon'],
                    'planning_date' => $day['date'],
                    'from_agency_id' => $agencyByNode[$move['from_node_ref']],
                    'to_agency_id' => $agencyByNode[$move['to_node_ref']],
                    'vehicle_units' => $move['vehicle_units'],
                    'distance_km' => $move['distance_km'],
                ];
            }

            foreach ($nodes as $nodeRef => $node) {
                if (($outbound[$nodeRef] ?? 0) > (int) $node['transferable_surplus']
                    || ($inbound[$nodeRef] ?? 0) > (int) $node['uncovered_need']) {
                    $this->fail();
                }
            }
            $moved = array_sum(array_column($day['recommendations'], 'vehicle_units'));
            $expectedUnserved = array_sum(array_column($expected['nodes'], 'uncovered_need')) - $moved;
            if ($day['unserved_need'] !== $expectedUnserved) {
                $this->fail();
            }
        }

        $outcome = $recommendations !== []
            ? 'transfers_recommended'
            : ($totalUncovered === 0 ? 'balanced_without_transfer' : 'insufficient_transferable_surplus');

        return compact('payload', 'recommendations', 'outcome');
    }

    /** @param mixed $value @param list<string> $keys @return array<string, mixed> */
    private function object(mixed $value, array $keys): array
    {
        if (! is_array($value) || array_is_list($value)) {
            $this->fail();
        }
        $actual = array_keys($value);
        sort($actual);
        sort($keys);
        if ($actual !== $keys) {
            $this->fail();
        }

        return $value;
    }

    private function runtimeDecimal(mixed $value): bool
    {
        if (! is_string($value)
            || preg_match('/^(?:0|[1-9][0-9]{0,3})\.[0-9]{6}$/D', $value) !== 1) {
            return false;
        }
        [$whole, $fraction] = explode('.', $value);

        return (int) $whole < 5000 || ((int) $whole === 5000 && (int) $fraction === 0);
    }

    private function same(mixed $actual, mixed $expected): void
    {
        if ($actual !== $expected) {
            $this->fail();
        }
    }

    private function fail(): never
    {
        throw new FleetReallocationPlanningException('SOLVER_OUTPUT_INVALID');
    }
}
