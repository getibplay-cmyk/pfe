<?php

namespace App\Support\Intelligence\FleetReallocation;

use App\Exceptions\FleetReallocationValidationException;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Str;
use JsonException;

final class FleetReallocationProposalValidator
{
    private const ROOT_KEYS = [
        'schema_version',
        'proposal_id',
        'generated_at',
        'source',
        'planning',
        'summary',
        'safety',
        'idempotency',
    ];

    public function __construct(
        private readonly FleetReallocationCanonicalPayload $canonical,
    ) {}

    public function validate(string $json): ValidatedFleetReallocationProposal
    {
        try {
            $decoded = json_decode($json, true, 128, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
        } catch (JsonException) {
            $this->fail('$', 'JSON UTF-8 invalide');
        }

        $payload = $this->closedObject($decoded, self::ROOT_KEYS, '$');
        $this->same($payload['schema_version'], FleetReallocationContract::SCHEMA_VERSION, 'schema_version');
        $proposalId = $this->uuid($payload['proposal_id'], 'proposal_id');
        $generatedAt = $this->utcDateTime($payload['generated_at'], 'generated_at');
        if ($generatedAt->getTimestamp() > now('UTC')->addMinutes(5)->getTimestamp()) {
            $this->fail('generated_at', 'ne peut pas être situé dans le futur');
        }

        $source = $this->closedObject($payload['source'], [
            'kind',
            'solver_name',
            'solver_version',
            'solver_status',
            'qualification_decision',
            'qualification_commit',
            'evidence_commit',
        ], 'source');
        foreach ([
            'kind' => FleetReallocationContract::SOURCE_KIND,
            'solver_name' => FleetReallocationContract::SOLVER_NAME,
            'solver_version' => FleetReallocationContract::SOLVER_VERSION,
            'solver_status' => FleetReallocationContract::SOLVER_STATUS,
            'qualification_decision' => FleetReallocationContract::QUALIFICATION_DECISION,
            'qualification_commit' => FleetReallocationContract::QUALIFICATION_COMMIT,
            'evidence_commit' => FleetReallocationContract::EVIDENCE_COMMIT,
        ] as $key => $expected) {
            $this->same($source[$key], $expected, 'source.'.$key);
        }

        $planning = $this->closedObject($payload['planning'], [
            'as_of_date',
            'target_date',
            'forecast_horizon',
            'distance_unit',
            'data_status',
            'demand_source',
            'cancellation_risk',
            'nodes',
            'moves',
        ], 'planning');

        $asOfDate = $this->date($planning['as_of_date'], 'planning.as_of_date');
        $targetDate = $this->date($planning['target_date'], 'planning.target_date');
        $horizon = $this->integer($planning['forecast_horizon'], 1, 7, 'planning.forecast_horizon');
        if ($asOfDate->modify('+'.$horizon.' days')->format('Y-m-d') !== $targetDate->format('Y-m-d')) {
            $this->fail('planning.target_date', 'doit correspondre exactement à l’horizon D+1 à D+7');
        }
        $this->same($planning['distance_unit'], FleetReallocationContract::DISTANCE_UNIT, 'planning.distance_unit');
        $this->same($planning['data_status'], FleetReallocationContract::DATA_STATUS, 'planning.data_status');

        $demand = $this->closedObject($planning['demand_source'], [
            'model_name',
            'model_version',
            'forecast_reference_sha256',
            'local_holdout_status',
            'synthetic_demo',
        ], 'planning.demand_source');
        $this->same($demand['model_name'], FleetReallocationContract::FORECAST_MODEL, 'planning.demand_source.model_name');
        $this->same($demand['model_version'], FleetReallocationContract::FORECAST_VERSION, 'planning.demand_source.model_version');
        $this->sha256($demand['forecast_reference_sha256'], 'planning.demand_source.forecast_reference_sha256');
        $this->same($demand['local_holdout_status'], FleetReallocationContract::FORECAST_LOCAL_STATUS, 'planning.demand_source.local_holdout_status');
        $this->same($demand['synthetic_demo'], true, 'planning.demand_source.synthetic_demo');

        $cancellation = $this->closedObject($planning['cancellation_risk'], [
            'model_name',
            'gate_decision',
            'presence_probability',
            'presence_reason',
            'demand_adjustment',
        ], 'planning.cancellation_risk');
        $this->same($cancellation['model_name'], FleetReallocationContract::CANCELLATION_MODEL, 'planning.cancellation_risk.model_name');
        $this->same($cancellation['gate_decision'], FleetReallocationContract::CANCELLATION_DECISION, 'planning.cancellation_risk.gate_decision');
        $this->same($cancellation['presence_probability'], FleetReallocationContract::PRESENCE_PROBABILITY, 'planning.cancellation_risk.presence_probability');
        $this->same($cancellation['presence_reason'], FleetReallocationContract::PRESENCE_REASON, 'planning.cancellation_risk.presence_reason');
        $this->same($cancellation['demand_adjustment'], 'ABSTENTION_NO_DEMAND_REDUCTION', 'planning.cancellation_risk.demand_adjustment');

        $nodes = $this->closedList($planning['nodes'], 'planning.nodes');
        if (count($nodes) < 2 || count($nodes) > 20) {
            $this->fail('planning.nodes', 'doit contenir entre 2 et 20 nœuds synthétiques');
        }
        $nodeRefs = [];
        $totalDemand = 0;
        foreach ($nodes as $position => $value) {
            $path = 'planning.nodes.'.$position;
            $node = $this->closedObject($value, [
                'node_ref',
                'available_vehicles',
                'forecast_demand',
                'effective_demand',
            ], $path);
            $nodeRef = $this->nodeRef($node['node_ref'], $path.'.node_ref');
            if (isset($nodeRefs[$nodeRef])) {
                $this->fail($path.'.node_ref', 'nœud dupliqué');
            }
            $nodeRefs[$nodeRef] = true;
            $this->integer($node['available_vehicles'], 0, 1000, $path.'.available_vehicles');
            $forecastDemand = $this->integer($node['forecast_demand'], 0, 1000, $path.'.forecast_demand');
            $effectiveDemand = $this->integer($node['effective_demand'], 0, 1000, $path.'.effective_demand');
            if ($forecastDemand !== $effectiveDemand) {
                $this->fail($path.'.effective_demand', 'doit rester identique à la prévision lorsque CatBoost s’abstient');
            }
            $totalDemand += $effectiveDemand;
        }
        if ($totalDemand <= 0) {
            $this->fail('planning.nodes', 'la demande synthétique totale doit être strictement positive');
        }

        $moves = $this->closedList($planning['moves'], 'planning.moves');
        if (count($moves) < 1 || count($moves) > 100) {
            $this->fail('planning.moves', 'doit contenir entre 1 et 100 lignes de déplacement');
        }
        $validatedMoves = [];
        $movePairs = [];
        $relocatedVehicles = 0;
        $relocationCost = 0;
        foreach ($moves as $position => $value) {
            $path = 'planning.moves.'.$position;
            $move = $this->closedObject($value, [
                'from_node_ref',
                'to_node_ref',
                'vehicles',
                'distance_km',
                'unit_cost_centimes',
                'total_cost_centimes',
                'reason_code',
                'operational_effect',
            ], $path);
            $from = $this->nodeRef($move['from_node_ref'], $path.'.from_node_ref');
            $to = $this->nodeRef($move['to_node_ref'], $path.'.to_node_ref');
            if (! isset($nodeRefs[$from], $nodeRefs[$to]) || $from === $to) {
                $this->fail($path, 'origine/destination absente ou identique');
            }
            $pair = $from.'>'.$to;
            if (isset($movePairs[$pair])) {
                $this->fail($path, 'une seule ligne est autorisée par paire origine/destination');
            }
            $movePairs[$pair] = true;

            $vehicles = $this->integer($move['vehicles'], 1, 1000, $path.'.vehicles');
            $distanceMilliKm = $this->fixedDecimal($move['distance_km'], 3, 4, false, $path.'.distance_km');
            $expectedUnitCost = intdiv(
                $distanceMilliKm * FleetReallocationContract::RELOCATION_COST_CENTIMES_PER_KM + 500,
                1000,
            );
            $unitCost = $this->integer($move['unit_cost_centimes'], 1, 10_000_000, $path.'.unit_cost_centimes');
            if ($unitCost !== $expectedUnitCost) {
                $this->fail($path.'.unit_cost_centimes', 'coût incompatible avec 5,00 MAD par véhicule-km');
            }
            $totalCost = $this->integer($move['total_cost_centimes'], 1, 10_000_000_000, $path.'.total_cost_centimes');
            if ($totalCost !== $vehicles * $unitCost) {
                $this->fail($path.'.total_cost_centimes', 'doit égaler véhicules × coût unitaire');
            }
            $this->same($move['reason_code'], FleetReallocationContract::MOVE_REASON, $path.'.reason_code');
            $this->same($move['operational_effect'], FleetReallocationContract::OPERATIONAL_EFFECT, $path.'.operational_effect');

            $relocatedVehicles += $vehicles;
            $relocationCost += $totalCost;
            $validatedMoves[] = $move;
        }

        $summary = $this->closedObject($payload['summary'], [
            'node_count',
            'move_line_count',
            'relocated_vehicle_count',
            'total_demand',
            'served_demand',
            'unserved_demand',
            'service_rate',
            'relocation_cost_centimes',
            'decision_cost_centimes',
            'solver_runtime_ms',
        ], 'summary');
        $this->same($summary['node_count'], count($nodes), 'summary.node_count');
        $this->same($summary['move_line_count'], count($moves), 'summary.move_line_count');
        $this->same($summary['relocated_vehicle_count'], $relocatedVehicles, 'summary.relocated_vehicle_count');
        $this->same($summary['total_demand'], $totalDemand, 'summary.total_demand');
        $served = $this->integer($summary['served_demand'], 0, $totalDemand, 'summary.served_demand');
        $unserved = $this->integer($summary['unserved_demand'], 0, $totalDemand, 'summary.unserved_demand');
        if ($served + $unserved !== $totalDemand) {
            $this->fail('summary', 'demande servie et non servie incohérentes');
        }
        $serviceRate = $this->fixedDecimal($summary['service_rate'], 6, 1, true, 'summary.service_rate');
        $expectedRate = intdiv($served * 1_000_000 + intdiv($totalDemand, 2), $totalDemand);
        if ($serviceRate !== $expectedRate || $serviceRate < 800_000) {
            $this->fail('summary.service_rate', 'ratio incohérent ou inférieur au gate de 0,80');
        }
        $this->same($summary['relocation_cost_centimes'], $relocationCost, 'summary.relocation_cost_centimes');
        $decisionCost = $this->integer($summary['decision_cost_centimes'], 0, 10_000_000_000_000, 'summary.decision_cost_centimes');
        if ($decisionCost !== $relocationCost + $unserved * FleetReallocationContract::UNSERVED_PENALTY_CENTIMES) {
            $this->fail('summary.decision_cost_centimes', 'objectif de décision incohérent');
        }
        $runtime = $this->fixedDecimal($summary['solver_runtime_ms'], 6, 4, true, 'summary.solver_runtime_ms');
        if ($runtime > 5_000_000_000) {
            $this->fail('summary.solver_runtime_ms', 'dépasse le gate de 5 secondes');
        }

        $safety = $this->closedObject($payload['safety'], [
            'synthetic_demo',
            'contains_real_customer_data',
            'contains_direct_identifiers',
            'contains_coordinates',
            'human_decision_required',
            'automatic_action_allowed',
            'operational_table_write_allowed',
            'local_validation_status',
            'operational_effect',
        ], 'safety');
        foreach ([
            'synthetic_demo' => true,
            'contains_real_customer_data' => false,
            'contains_direct_identifiers' => false,
            'contains_coordinates' => false,
            'human_decision_required' => true,
            'automatic_action_allowed' => false,
            'operational_table_write_allowed' => false,
            'local_validation_status' => FleetReallocationContract::LOCAL_VALIDATION_STATUS,
            'operational_effect' => FleetReallocationContract::OPERATIONAL_EFFECT,
        ] as $key => $expected) {
            $this->same($safety[$key], $expected, 'safety.'.$key);
        }

        $idempotency = $this->closedObject($payload['idempotency'], [
            'key',
            'policy',
            'canonical_payload_sha256',
        ], 'idempotency');
        $idempotencyKey = $this->uuid($idempotency['key'], 'idempotency.key');
        $this->same($idempotency['policy'], 'SAME_KEY_SAME_PAYLOAD_ONLY', 'idempotency.policy');
        $this->sha256($idempotency['canonical_payload_sha256'], 'idempotency.canonical_payload_sha256');
        $digest = $this->canonical->digest($payload);
        if (! hash_equals($digest, $idempotency['canonical_payload_sha256'])) {
            $this->fail('idempotency.canonical_payload_sha256', 'empreinte canonique incorrecte');
        }

        return new ValidatedFleetReallocationProposal(
            payload: $payload,
            moves: $validatedMoves,
            proposalId: $proposalId,
            idempotencyKey: $idempotencyKey,
            canonicalPayloadSha256: $digest,
            canonicalJson: $this->canonical->encode($payload),
            generatedAt: $generatedAt,
            asOfDate: $asOfDate,
            targetDate: $targetDate,
        );
    }

    /** @param list<string> $expectedKeys @return array<string, mixed> */
    private function closedObject(mixed $value, array $expectedKeys, string $path): array
    {
        if (! is_array($value) || array_is_list($value)) {
            $this->fail($path, 'objet JSON attendu');
        }
        $actualKeys = array_keys($value);
        sort($actualKeys, SORT_STRING);
        sort($expectedKeys, SORT_STRING);
        if ($actualKeys !== $expectedKeys) {
            $this->fail($path, 'clés JSON absentes ou inconnues');
        }

        return $value;
    }

    /** @return list<mixed> */
    private function closedList(mixed $value, string $path): array
    {
        if (! is_array($value) || ! array_is_list($value)) {
            $this->fail($path, 'tableau JSON attendu');
        }

        return $value;
    }

    private function same(mixed $actual, mixed $expected, string $path): void
    {
        if ($actual !== $expected) {
            $this->fail($path, 'valeur contractuelle incorrecte');
        }
    }

    private function integer(mixed $value, int $minimum, int $maximum, string $path): int
    {
        if (! is_int($value) || $value < $minimum || $value > $maximum) {
            $this->fail($path, 'entier hors limites');
        }

        return $value;
    }

    private function fixedDecimal(
        mixed $value,
        int $scale,
        int $maximumWholeDigits,
        bool $allowZero,
        string $path,
    ): int {
        $pattern = '/^(?:0|[1-9][0-9]{0,'.($maximumWholeDigits - 1).'})\.[0-9]{'.$scale.'}$/D';
        if (! is_string($value) || preg_match($pattern, $value) !== 1) {
            $this->fail($path, 'décimal positif à échelle fixe attendu');
        }
        [$whole, $fraction] = explode('.', $value, 2);
        $scaled = (int) $whole * (10 ** $scale) + (int) $fraction;
        if (! $allowZero && $scaled === 0) {
            $this->fail($path, 'doit être strictement positif');
        }

        return $scaled;
    }

    private function uuid(mixed $value, string $path): string
    {
        if (! is_string($value) || ! Str::isUuid($value) || $value !== strtolower($value)) {
            $this->fail($path, 'UUID invalide');
        }

        return strtolower($value);
    }

    private function sha256(mixed $value, string $path): string
    {
        if (! is_string($value) || preg_match('/^[0-9a-f]{64}$/D', $value) !== 1) {
            $this->fail($path, 'empreinte SHA-256 invalide');
        }

        return $value;
    }

    private function nodeRef(mixed $value, string $path): string
    {
        if (! is_string($value) || preg_match('/^SYNTH-NODE-[0-9]{3}$/D', $value) !== 1) {
            $this->fail($path, 'référence de nœud synthétique invalide');
        }

        return $value;
    }

    private function utcDateTime(mixed $value, string $path): DateTimeImmutable
    {
        if (! is_string($value)) {
            $this->fail($path, 'horodatage UTC RFC 3339 attendu');
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s\Z', $value, new DateTimeZone('UTC'));
        $errors = DateTimeImmutable::getLastErrors();
        if ($date === false
            || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))
            || $date->format('Y-m-d\TH:i:s\Z') !== $value) {
            $this->fail($path, 'horodatage UTC RFC 3339 invalide');
        }

        return $date;
    }

    private function date(mixed $value, string $path): DateTimeImmutable
    {
        if (! is_string($value)) {
            $this->fail($path, 'date ISO attendue');
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value, new DateTimeZone('UTC'));
        $errors = DateTimeImmutable::getLastErrors();
        if ($date === false
            || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))
            || $date->format('Y-m-d') !== $value) {
            $this->fail($path, 'date ISO invalide');
        }

        return $date;
    }

    private function fail(string $path, string $message): never
    {
        throw FleetReallocationValidationException::at($path, $message);
    }
}
