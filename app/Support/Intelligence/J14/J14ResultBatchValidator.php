<?php

namespace App\Support\Intelligence\J14;

use App\Exceptions\J14ResultBatchValidationException;
use App\Models\IntelligenceDatasetExportRun;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Str;
use JsonException;

final class J14ResultBatchValidator
{
    private const ROOT_KEYS = [
        'schema_version',
        'batch_id',
        'generated_at',
        'source',
        'export',
        'results',
        'human_review',
        'safety',
        'idempotency',
    ];

    private const FACTORS = ['late_hours', 'km_per_day', 'fuel_drop_pct'];

    public function __construct(
        private readonly J14CanonicalPayload $canonical,
        private readonly J14DatasetSnapshotInspector $snapshotInspector,
    ) {}

    public function validate(string $json, IntelligenceDatasetExportRun $run): J14ValidatedResultBatch
    {
        try {
            $decoded = json_decode($json, true, 128, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
        } catch (JsonException) {
            throw J14ResultBatchValidationException::at('$', 'JSON UTF-8 invalide');
        }

        $payload = $this->closedObject($decoded, self::ROOT_KEYS, '$');
        $this->same($payload['schema_version'], '1.0.0', 'schema_version');

        $batchId = $this->uuid($payload['batch_id'], 'batch_id');
        $generatedAt = $this->utcDateTime($payload['generated_at'], 'generated_at');
        if ($generatedAt->getTimestamp() < $run->created_at->getTimestamp()) {
            $this->fail('generated_at', 'doit être postérieur ou égal à la création de l’export');
        }
        if ($generatedAt->getTimestamp() > now('UTC')->addMinutes(5)->getTimestamp()) {
            $this->fail('generated_at', 'ne peut pas être situé dans le futur');
        }

        $source = $this->closedObject($payload['source'], [
            'kind',
            'computation_status',
            'producer_name',
            'producer_version',
            'environment',
        ], 'source');
        $this->same($source['kind'], 'synthetic_fixture', 'source.kind');
        $this->same(
            $source['computation_status'],
            'not_run_synthetic_contract_fixture',
            'source.computation_status',
        );
        $this->same($source['producer_name'], 'rentfleet-j14-synthetic-fixture', 'source.producer_name');
        $this->same($source['producer_version'], '1.0.0', 'source.producer_version');
        $this->same($source['environment'], 'offline_contract_demo', 'source.environment');

        $export = $this->closedObject($payload['export'], [
            'run_id',
            'schema_version',
            'dataset_version',
            'row_count',
            'content_sha256',
        ], 'export');
        $this->same($export['run_id'], $run->run_id, 'export.run_id');
        $this->same($export['schema_version'], $run->schema_version, 'export.schema_version');
        $this->same($export['dataset_version'], $run->dataset_version, 'export.dataset_version');
        $this->same($export['row_count'], $run->row_count, 'export.row_count');
        $this->same($export['content_sha256'], $run->content_sha256, 'export.content_sha256');

        $inspection = $this->snapshotInspector->inspect($run);
        $results = $this->closedList($payload['results'], 'results');
        if (count($results) !== count($inspection->rowKeys)) {
            $this->fail('results', 'doit contenir exactement une sortie pour chaque ligne exportée');
        }

        $validatedRows = [];
        foreach ($results as $position => $value) {
            $path = 'results.'.$position;
            $row = $this->closedObject($value, [
                'row_id',
                'advisory_kind',
                'priority',
                'summary_code',
                'factors',
                'operational_effect',
            ], $path);
            $this->same($row['row_id'], $inspection->rowKeys[$position], $path.'.row_id');
            $this->same($row['advisory_kind'], 'rental_usage_review', $path.'.advisory_kind');
            $this->same($row['summary_code'], 'SYNTHETIC_REVIEW_ONLY', $path.'.summary_code');
            $this->same($row['operational_effect'], 'NO_OPERATIONAL_ACTION', $path.'.operational_effect');

            $factors = $this->closedList($row['factors'], $path.'.factors');
            if (count($factors) !== count(self::FACTORS)) {
                $this->fail($path.'.factors', 'doit contenir les trois facteurs contractuels');
            }

            $elevated = 0;
            $validatedFactors = [];
            foreach (self::FACTORS as $factorPosition => $factorName) {
                $factorPath = $path.'.factors.'.$factorPosition;
                $factor = $this->closedObject($factors[$factorPosition], ['name', 'level'], $factorPath);
                $this->same($factor['name'], $factorName, $factorPath.'.name');
                if (! in_array($factor['level'], ['normal', 'elevated'], true)) {
                    $this->fail($factorPath.'.level', 'valeur qualitative non autorisée');
                }
                $elevated += $factor['level'] === 'elevated' ? 1 : 0;
                $validatedFactors[] = $factor;
            }

            $expectedPriority = match ($elevated) {
                0 => 'low',
                1 => 'medium',
                default => 'high',
            };
            $this->same($row['priority'], $expectedPriority, $path.'.priority');
            $row['factors'] = $validatedFactors;
            $validatedRows[] = $row;
        }

        $humanReview = $this->closedObject($payload['human_review'], [
            'required',
            'initial_status',
            'effect',
        ], 'human_review');
        $this->same($humanReview['required'], true, 'human_review.required');
        $this->same($humanReview['initial_status'], 'pending', 'human_review.initial_status');
        $this->same($humanReview['effect'], 'NO_OPERATIONAL_ACTION', 'human_review.effect');

        $safety = $this->closedObject($payload['safety'], [
            'synthetic_only',
            'contains_real_customer_data',
            'contains_direct_identifiers',
            'contains_coordinates',
            'automatic_action_allowed',
            'ready_for_saas',
            'production_allowed',
        ], 'safety');
        foreach ([
            'synthetic_only' => true,
            'contains_real_customer_data' => false,
            'contains_direct_identifiers' => false,
            'contains_coordinates' => false,
            'automatic_action_allowed' => false,
            'ready_for_saas' => false,
            'production_allowed' => false,
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

        return new J14ValidatedResultBatch(
            payload: $payload,
            rows: $validatedRows,
            batchId: $batchId,
            idempotencyKey: $idempotencyKey,
            canonicalPayloadSha256: $digest,
            canonicalJson: $this->canonical->encode($payload),
            generatedAt: $generatedAt,
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
        throw J14ResultBatchValidationException::at($path, $message);
    }
}
