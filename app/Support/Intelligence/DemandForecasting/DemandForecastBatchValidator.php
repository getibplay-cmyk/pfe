<?php

namespace App\Support\Intelligence\DemandForecasting;

use App\Exceptions\DemandForecastValidationException;
use App\Models\DemandHistoryExportRun;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Str;
use JsonException;

final class DemandForecastBatchValidator
{
    private const ROOT_KEYS = [
        'schema_version',
        'batch_id',
        'generated_at',
        'model',
        'dataset',
        'evaluation',
        'forecasts',
        'safety',
        'idempotency',
    ];

    public function __construct(private readonly DemandForecastCanonicalPayload $canonical) {}

    public function validate(string $json, DemandHistoryExportRun $history): ValidatedDemandForecastBatch
    {
        try {
            $decoded = json_decode($json, true, 128, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
        } catch (JsonException) {
            throw DemandForecastValidationException::at('$', 'JSON UTF-8 invalide');
        }

        $payload = $this->closedObject($decoded, self::ROOT_KEYS, '$');
        $this->same($payload['schema_version'], DemandForecastContract::RESULT_SCHEMA_VERSION, 'schema_version');
        if ($history->observed_departures_count < 1) {
            $this->fail('dataset', 'le snapshot ne contient aucun départ observé');
        }
        $batchId = $this->uuid($payload['batch_id'], 'batch_id');
        $generatedAt = $this->utcDateTime($payload['generated_at'], 'generated_at');
        if ($generatedAt->getTimestamp() < $history->created_at->getTimestamp()) {
            $this->fail('generated_at', 'doit être postérieur ou égal à la création du snapshot');
        }
        if ($generatedAt->getTimestamp() > now('UTC')->addMinutes(5)->getTimestamp()) {
            $this->fail('generated_at', 'ne peut pas être situé dans le futur');
        }

        $model = $this->closedObject($payload['model'], [
            'name',
            'version',
            'artifact_sha256',
            'framework',
            'framework_version',
            'compute',
            'explanation_method',
        ], 'model');
        foreach ([
            'name' => DemandForecastContract::MODEL_NAME,
            'version' => DemandForecastContract::MODEL_VERSION,
            'artifact_sha256' => DemandForecastContract::MODEL_ARTIFACT_SHA256,
            'framework' => DemandForecastContract::FRAMEWORK,
            'framework_version' => DemandForecastContract::FRAMEWORK_VERSION,
            'compute' => 'cpu',
            'explanation_method' => DemandForecastContract::EXPLANATION_METHOD,
        ] as $key => $expected) {
            $this->same($model[$key], $expected, 'model.'.$key);
        }

        $dataset = $this->closedObject($payload['dataset'], [
            'run_id',
            'schema_version',
            'dataset_version',
            'preprocessing_version',
            'content_sha256',
            'row_count',
            'date_from',
            'date_to',
            'timezone',
            'distance_unit',
            'target',
            'vehicle_category',
            'missing_dates',
        ], 'dataset');
        foreach ([
            'run_id' => $history->run_id,
            'schema_version' => $history->schema_version,
            'dataset_version' => $history->dataset_version,
            'preprocessing_version' => $history->preprocessing_version,
            'content_sha256' => $history->content_sha256,
            'row_count' => $history->row_count,
            'date_from' => $history->date_from->toDateString(),
            'date_to' => $history->date_to->toDateString(),
            'timezone' => DemandForecastContract::TIMEZONE,
            'distance_unit' => DemandForecastContract::DISTANCE_UNIT,
            'target' => DemandForecastContract::TARGET,
            'vehicle_category' => DemandForecastContract::VEHICLE_CATEGORY_SCOPE,
            'missing_dates' => 'zero_filled',
        ] as $key => $expected) {
            $this->same($dataset[$key], $expected, 'dataset.'.$key);
        }

        $evaluation = $this->closedObject($payload['evaluation'], [
            'validation_scope',
            'public_wape',
            'public_mase',
            'public_interval_coverage_p05_p95',
            'local_holdout_status',
            'local_wape',
            'local_mase',
            'local_interval_coverage_p05_p95',
            'production_claim_allowed',
        ], 'evaluation');
        foreach ([
            'validation_scope' => 'public_proxy_only_local_shadow',
            'public_wape' => DemandForecastContract::PUBLIC_WAPE,
            'public_mase' => DemandForecastContract::PUBLIC_MASE,
            'public_interval_coverage_p05_p95' => DemandForecastContract::PUBLIC_INTERVAL_COVERAGE,
            'local_holdout_status' => 'not_available_pending_real_history',
            'local_wape' => null,
            'local_mase' => null,
            'local_interval_coverage_p05_p95' => null,
            'production_claim_allowed' => false,
        ] as $key => $expected) {
            $this->same($evaluation[$key], $expected, 'evaluation.'.$key);
        }

        $values = $this->closedList($payload['forecasts'], 'forecasts');
        if (count($values) !== 7) {
            $this->fail('forecasts', 'doit contenir exactement les horizons D+1 à D+7');
        }

        $forecasts = [];
        foreach ($values as $position => $value) {
            $path = 'forecasts.'.$position;
            $row = $this->closedObject($value, [
                'target_date',
                'horizon',
                'vehicle_category',
                'conditional_mean',
                'p05',
                'p50',
                'p90',
                'p95',
                'raw_any_crossing',
                'monotone_adjusted',
                'explanations',
                'demand_semantics',
                'operational_effect',
            ], $path);
            $horizon = $position + 1;
            $this->same($row['horizon'], $horizon, $path.'.horizon');
            $this->same(
                $row['target_date'],
                $history->date_to->addDays($horizon)->toDateString(),
                $path.'.target_date',
            );
            $this->same(
                $row['vehicle_category'],
                DemandForecastContract::VEHICLE_CATEGORY_SCOPE,
                $path.'.vehicle_category',
            );
            $this->same($row['demand_semantics'], DemandForecastContract::TARGET, $path.'.demand_semantics');
            $this->same(
                $row['operational_effect'],
                DemandForecastContract::OPERATIONAL_EFFECT,
                $path.'.operational_effect',
            );

            $numeric = [];
            foreach (['conditional_mean', 'p05', 'p50', 'p90', 'p95'] as $key) {
                $numeric[$key] = $this->unsignedDecimal($row[$key], $path.'.'.$key);
            }
            if ($numeric['p05'] > $numeric['p50']
                || $numeric['p50'] > $numeric['p90']
                || $numeric['p90'] > $numeric['p95']) {
                $this->fail($path, 'les quantiles doivent respecter P05 ≤ P50 ≤ P90 ≤ P95');
            }
            if (! is_bool($row['raw_any_crossing']) || ! is_bool($row['monotone_adjusted'])) {
                $this->fail($path, 'les indicateurs de monotonie doivent être booléens');
            }

            $row['explanations'] = $this->explanations($row['explanations'], $path.'.explanations');
            $forecasts[] = $row;
        }

        $safety = $this->closedObject($payload['safety'], [
            'mode',
            'human_decision_required',
            'automatic_action_allowed',
            'operational_table_write_allowed',
            'ready_for_production',
            'operational_effect',
        ], 'safety');
        foreach ([
            'mode' => 'consultative_shadow',
            'human_decision_required' => true,
            'automatic_action_allowed' => false,
            'operational_table_write_allowed' => false,
            'ready_for_production' => false,
            'operational_effect' => DemandForecastContract::OPERATIONAL_EFFECT,
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
        if (! is_string($idempotency['canonical_payload_sha256'])
            || preg_match('/^[0-9a-f]{64}$/D', $idempotency['canonical_payload_sha256']) !== 1) {
            $this->fail('idempotency.canonical_payload_sha256', 'empreinte SHA-256 invalide');
        }

        $digest = $this->canonical->digest($payload);
        if (! hash_equals($digest, $idempotency['canonical_payload_sha256'])) {
            $this->fail('idempotency.canonical_payload_sha256', 'empreinte canonique incorrecte');
        }

        return new ValidatedDemandForecastBatch(
            payload: $payload,
            forecasts: $forecasts,
            batchId: $batchId,
            idempotencyKey: $idempotencyKey,
            canonicalPayloadSha256: $digest,
            canonicalJson: $this->canonical->encode($payload),
            generatedAt: $generatedAt,
        );
    }

    /** @return list<array{feature: string, direction: string, prediction_delta: string}> */
    private function explanations(mixed $value, string $path): array
    {
        $items = $this->closedList($value, $path);
        if (count($items) !== 3) {
            $this->fail($path, 'doit contenir exactement trois facteurs locaux');
        }

        $allowed = DemandForecastContract::explainableFeatures();
        $seen = [];
        $result = [];
        foreach ($items as $position => $value) {
            $itemPath = $path.'.'.$position;
            $item = $this->closedObject(
                $value,
                ['feature', 'direction', 'prediction_delta'],
                $itemPath,
            );
            if (! is_string($item['feature']) || ! in_array($item['feature'], $allowed, true)) {
                $this->fail($itemPath.'.feature', 'facteur non autorisé');
            }
            if (isset($seen[$item['feature']])) {
                $this->fail($itemPath.'.feature', 'facteur dupliqué');
            }
            $seen[$item['feature']] = true;
            $contribution = $this->signedDecimal(
                $item['prediction_delta'],
                $itemPath.'.prediction_delta',
            );
            $expectedDirection = $contribution > 0 ? 'increase' : ($contribution < 0 ? 'decrease' : 'neutral');
            $this->same($item['direction'], $expectedDirection, $itemPath.'.direction');
            $result[] = $item;
        }

        return $result;
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

    private function unsignedDecimal(mixed $value, string $path): float
    {
        if (! is_string($value) || preg_match('/^(?:0|[1-9][0-9]{0,7})\.[0-9]{6}$/D', $value) !== 1) {
            $this->fail($path, 'décimal positif à six chiffres après la virgule attendu');
        }

        return (float) $value;
    }

    private function signedDecimal(mixed $value, string $path): float
    {
        if (! is_string($value) || preg_match('/^-?(?:0|[1-9][0-9]{0,7})\.[0-9]{6}$/D', $value) !== 1) {
            $this->fail($path, 'contribution signée à six chiffres après la virgule attendue');
        }

        return (float) $value;
    }

    private function same(mixed $actual, mixed $expected, string $path): void
    {
        if ($actual !== $expected) {
            $this->fail($path, 'valeur contractuelle incorrecte');
        }
    }

    private function uuid(mixed $value, string $path): string
    {
        if (! is_string($value) || ! Str::isUuid($value) || $value !== strtolower($value)) {
            $this->fail($path, 'UUID invalide');
        }

        return strtolower($value);
    }

    private function utcDateTime(mixed $value, string $path): DateTimeImmutable
    {
        if (! is_string($value)) {
            $this->fail($path, 'horodatage UTC RFC 3339 attendu');
        }

        $date = DateTimeImmutable::createFromFormat(
            '!Y-m-d\TH:i:s\Z',
            $value,
            new DateTimeZone('UTC'),
        );
        $errors = DateTimeImmutable::getLastErrors();
        if ($date === false
            || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))
            || $date->format('Y-m-d\TH:i:s\Z') !== $value) {
            $this->fail($path, 'horodatage UTC RFC 3339 invalide');
        }

        return $date;
    }

    private function fail(string $path, string $message): never
    {
        throw DemandForecastValidationException::at($path, $message);
    }
}
