<?php

namespace App\Support\Intelligence\RentalUsageAnomaly;

use App\Exceptions\RentalUsageAnomalyExecutionException;
use App\Models\RentalUsageAnomalyRun;
use JsonException;

final class RentalUsageAnomalyOutputValidator
{
    public function validate(
        string $json,
        RentalUsageAnomalyRun $run,
        RentalUsageAnomalySnapshotInspection $snapshot,
    ): ValidatedRentalUsageAnomalyOutput {
        try {
            $payload = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new RentalUsageAnomalyExecutionException('ANOMALY_OUTPUT_JSON_INVALID');
        }
        $this->object($payload, ['schema_version', 'run_id', 'source', 'execution', 'safety', 'budgets', 'rows']);
        if ($payload['schema_version'] !== RentalUsageAnomalyContract::SCHEMA_VERSION
            || $payload['run_id'] !== $run->run_id) {
            $this->fail();
        }

        $this->object($payload['source'], ['schema_version', 'dataset_version', 'sha256', 'byte_size', 'row_count']);
        if ($payload['source']['schema_version'] !== $run->exportRun->schema_version
            || $payload['source']['dataset_version'] !== $run->exportRun->dataset_version
            || $payload['source']['sha256'] !== $run->exportRun->content_sha256
            || $payload['source']['byte_size'] !== $run->exportRun->byte_size
            || $payload['source']['row_count'] !== $run->source_row_count) {
            $this->fail();
        }

        $executionStatus = is_array($payload['execution'] ?? null)
            ? ($payload['execution']['status'] ?? null)
            : null;
        $executionKeys = [
            'compute', 'primary', 'challenger', 'random_state', 'minimum_rows',
            'runtime_sha256', 'default_budget_basis_points', 'status',
        ];
        if ($executionStatus === 'insufficient_data') {
            $executionKeys[] = 'reason';
        }
        $this->object($payload['execution'], $executionKeys);
        $this->object($payload['execution']['primary'] ?? null, ['name', 'version']);
        $this->object($payload['execution']['challenger'] ?? null, ['name', 'version']);
        if ($payload['execution']['compute'] !== 'CPU'
            || $payload['execution']['primary'] !== ['name' => $run->primary_model, 'version' => $run->primary_version]
            || $payload['execution']['challenger'] !== ['name' => $run->challenger_model, 'version' => $run->challenger_version]
            || $payload['execution']['random_state'] !== $run->random_state
            || $payload['execution']['runtime_sha256'] !== $run->runtime_sha256
            || $payload['execution']['minimum_rows'] !== $run->minimum_rows
            || $payload['execution']['default_budget_basis_points'] !== $run->default_budget_basis_points) {
            $this->fail();
        }

        $this->object($payload['safety'], [
            'human_review_required', 'automatic_actions_allowed', 'operational_effect', 'forbidden_actions',
        ]);
        if ($payload['safety']['human_review_required'] !== true
            || $payload['safety']['automatic_actions_allowed'] !== false
            || $payload['safety']['operational_effect'] !== RentalUsageAnomalyContract::OPERATIONAL_EFFECT
            || $payload['safety']['forbidden_actions'] !== ['SANCTION', 'FEE_OR_CHARGE', 'FRAUD_ACCUSATION', 'CONTRACT_MUTATION']
            || ! is_array($payload['budgets'])
            || ! is_array($payload['rows'])) {
            $this->fail();
        }

        if ($payload['execution']['status'] === 'insufficient_data') {
            if ($run->source_row_count >= $run->minimum_rows
                || ($payload['execution']['reason'] ?? null) !== 'MINIMUM_HISTORY_NOT_REACHED'
                || $payload['budgets'] !== []
                || $payload['rows'] !== []) {
                $this->fail();
            }

            return new ValidatedRentalUsageAnomalyOutput('insufficient_data', [], []);
        }
        if ($payload['execution']['status'] !== 'usable' || $run->source_row_count < $run->minimum_rows) {
            $this->fail();
        }

        $budgets = $this->budgets($payload['budgets'], $run->source_row_count);
        $rows = $this->rows($payload['rows'], $run->source_row_count, $snapshot);
        $this->assertBudgetSets($budgets, $rows);

        return new ValidatedRentalUsageAnomalyOutput('usable', $budgets, $rows);
    }

    /** @return list<array<string, mixed>> */
    private function budgets(mixed $value, int $rowCount): array
    {
        if (! is_array($value) || count($value) !== 3) {
            $this->fail();
        }
        $validated = [];
        foreach (array_values($value) as $index => $budget) {
            $this->object($budget, [
                'basis_points', 'requested_rate', 'selected_count', 'realized_rate',
                'primary_cutoff', 'challenger_cutoff', 'agreement_count', 'union_count', 'jaccard',
            ]);
            $basisPoints = RentalUsageAnomalyContract::BUDGETS[$index];
            $selectedCount = (int) ceil($rowCount * $basisPoints / 10000);
            if ($budget['basis_points'] !== $basisPoints
                || ! $this->near($budget['requested_rate'], $basisPoints / 10000)
                || $budget['selected_count'] !== $selectedCount
                || ! $this->near($budget['realized_rate'], $selectedCount / $rowCount)
                || ! $this->nonNegativeNumber($budget['primary_cutoff'])
                || ! $this->nonNegativeNumber($budget['challenger_cutoff'])
                || ! is_int($budget['agreement_count'])
                || ! is_int($budget['union_count'])
                || $budget['agreement_count'] < 0
                || $budget['agreement_count'] > $selectedCount
                || $budget['union_count'] < $selectedCount
                || $budget['union_count'] > 2 * $selectedCount
                || ! $this->numberBetween($budget['jaccard'], 0, 1)) {
                $this->fail();
            }
            $validated[] = $budget;
        }

        return $validated;
    }

    /** @return list<array<string, mixed>> */
    private function rows(mixed $value, int $rowCount, RentalUsageAnomalySnapshotInspection $snapshot): array
    {
        if (! is_array($value) || count($value) < 1 || count($value) > 400) {
            $this->fail();
        }
        $rows = [];
        $seenRows = [];
        $seenContracts = [];
        $seenPrimaryRanks = [];
        $seenChallengerRanks = [];
        foreach (array_values($value) as $row) {
            $this->object($row, ['row_id', 'agency_key', 'contract_key', 'event_at', 'features', 'primary', 'challenger']);
            $source = is_string($row['row_id']) ? ($snapshot->rowsById[$row['row_id']] ?? null) : null;
            if (! is_array($source)
                || isset($seenRows[$row['row_id']])
                || isset($seenContracts[$row['contract_key'] ?? ''])
                || $row['agency_key'] !== $source['agency_key']
                || $row['contract_key'] !== $source['contract_key']
                || $row['event_at'] !== $source['event_at']) {
                $this->fail();
            }
            $this->object($row['features'], RentalUsageAnomalyContract::FEATURES);
            foreach (RentalUsageAnomalyContract::FEATURES as $feature) {
                if ($row['features'][$feature] !== $source[$feature]) {
                    $this->fail();
                }
            }

            $this->object($row['primary'], ['score', 'rank', 'selected_budgets', 'factors']);
            $this->object($row['challenger'], ['score', 'rank', 'selected_budgets']);
            if (! $this->nonNegativeNumber($row['primary']['score'])
                || ! $this->nonNegativeNumber($row['challenger']['score'])
                || ! is_int($row['primary']['rank'])
                || ! is_int($row['challenger']['rank'])
                || $row['primary']['rank'] < 1 || $row['primary']['rank'] > $rowCount
                || $row['challenger']['rank'] < 1 || $row['challenger']['rank'] > $rowCount
                || isset($seenPrimaryRanks[$row['primary']['rank']])
                || isset($seenChallengerRanks[$row['challenger']['rank']])) {
                $this->fail();
            }
            $primaryBudgets = $this->selectedBudgets($row['primary']['selected_budgets'], $row['primary']['rank'], $rowCount);
            $challengerBudgets = $this->selectedBudgets($row['challenger']['selected_budgets'], $row['challenger']['rank'], $rowCount);
            if (! in_array(200, $primaryBudgets, true) && ! in_array(200, $challengerBudgets, true)) {
                $this->fail();
            }
            $factors = $this->factors($row['primary']['factors'], $source);

            $seenRows[$row['row_id']] = true;
            $seenContracts[$row['contract_key']] = true;
            $seenPrimaryRanks[$row['primary']['rank']] = true;
            $seenChallengerRanks[$row['challenger']['rank']] = true;
            $rows[] = [
                'row_id' => $row['row_id'],
                'agency_key' => $row['agency_key'],
                'contract_key' => $row['contract_key'],
                'event_at' => $row['event_at'],
                'features' => $row['features'],
                'primary_score' => $row['primary']['score'],
                'primary_rank' => $row['primary']['rank'],
                'primary_budgets' => $primaryBudgets,
                'primary_factors' => $factors,
                'challenger_score' => $row['challenger']['score'],
                'challenger_rank' => $row['challenger']['rank'],
                'challenger_budgets' => $challengerBudgets,
            ];
        }

        return $rows;
    }

    /** @return list<int> */
    private function selectedBudgets(mixed $value, int $rank, int $rowCount): array
    {
        if (! is_array($value) || array_values($value) !== $value) {
            $this->fail();
        }
        foreach ($value as $budget) {
            if (! is_int($budget) || ! in_array($budget, RentalUsageAnomalyContract::BUDGETS, true)) {
                $this->fail();
            }
        }
        $selected = array_values(array_unique($value));
        sort($selected);
        if ($selected !== $value) {
            $this->fail();
        }
        foreach (RentalUsageAnomalyContract::BUDGETS as $budget) {
            $expected = $rank <= (int) ceil($rowCount * $budget / 10000);
            if (in_array($budget, $selected, true) !== $expected) {
                $this->fail();
            }
        }

        return $selected;
    }

    /** @return list<array<string, mixed>> */
    private function factors(mixed $value, array $source): array
    {
        if (! is_array($value) || count($value) !== 2 || array_values($value) !== $value) {
            $this->fail();
        }
        $seen = [];
        foreach ($value as $factor) {
            $this->object($factor, ['feature', 'value', 'median', 'mad', 'positive_robust_deviation']);
            if (! is_string($factor['feature'])
                || ! in_array($factor['feature'], RentalUsageAnomalyContract::FEATURES, true)
                || isset($seen[$factor['feature']])
                || $factor['value'] !== $source[$factor['feature']]
                || ! $this->nonNegativeNumber($factor['median'])
                || ! $this->nonNegativeNumber($factor['mad'])
                || ! $this->nonNegativeNumber($factor['positive_robust_deviation'])) {
                $this->fail();
            }
            $seen[$factor['feature']] = true;
        }

        return $value;
    }

    /**
     * @param  list<array<string, mixed>>  $budgets
     * @param  list<array<string, mixed>>  $rows
     */
    private function assertBudgetSets(array $budgets, array $rows): void
    {
        foreach ($budgets as $index => $budget) {
            $basisPoints = RentalUsageAnomalyContract::BUDGETS[$index];
            $primary = [];
            $challenger = [];
            foreach ($rows as $row) {
                if (in_array($basisPoints, $row['primary_budgets'], true)) {
                    $primary[$row['row_id']] = true;
                }
                if (in_array($basisPoints, $row['challenger_budgets'], true)) {
                    $challenger[$row['row_id']] = true;
                }
            }
            $intersection = array_intersect_key($primary, $challenger);
            $union = $primary + $challenger;
            if (count($primary) !== $budget['selected_count']
                || count($challenger) !== $budget['selected_count']
                || count($intersection) !== $budget['agreement_count']
                || count($union) !== $budget['union_count']
                || ! $this->near($budget['jaccard'], count($intersection) / count($union))) {
                $this->fail();
            }
        }
    }

    /** @param list<string> $keys */
    private function object(mixed $value, array $keys): void
    {
        if (! is_array($value)) {
            $this->fail();
        }
        $actual = array_keys($value);
        sort($actual);
        sort($keys);
        if ($actual !== $keys) {
            $this->fail();
        }
    }

    private function nonNegativeNumber(mixed $value): bool
    {
        return $this->numberBetween($value, 0, 1.0E+20);
    }

    private function numberBetween(mixed $value, float|int $minimum, float|int $maximum): bool
    {
        return (is_float($value) || is_int($value))
            && is_finite((float) $value)
            && $value >= $minimum
            && $value <= $maximum;
    }

    private function near(mixed $value, float $expected): bool
    {
        return $this->numberBetween($value, 0, 1.0E+20)
            && abs((float) $value - $expected) <= 0.00000002;
    }

    private function fail(): never
    {
        throw new RentalUsageAnomalyExecutionException('ANOMALY_OUTPUT_CONTRACT_INVALID');
    }
}
