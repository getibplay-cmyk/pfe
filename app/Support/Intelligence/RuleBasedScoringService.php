<?php

namespace App\Support\Intelligence;

use App\Support\Ui\UiText;

final class RuleBasedScoringService implements PredictionScoringService
{
    public function score(PredictionInput $input): PredictionResult
    {
        $definitions = [
            'late_hours' => ['label' => UiText::t('Retard au retour'), 'value' => $input->lateHours],
            'km_per_day' => ['label' => UiText::t('Kilométrage journalier'), 'value' => $input->kmPerDay],
            'fuel_drop_pct' => ['label' => UiText::t('Baisse du niveau de carburant'), 'value' => $input->fuelDropPct],
        ];
        $factors = [];
        $maximumRatio = 0.0;

        foreach ($definitions as $name => $definition) {
            $threshold = $this->threshold($name);
            $value = max(0.0, (float) $definition['value']);
            $ratio = $threshold > 0.0 ? $value / $threshold : 0.0;
            $maximumRatio = max($maximumRatio, $ratio);
            $factors[] = [
                'name' => $name,
                'label' => $definition['label'],
                'value' => number_format($value, 6, '.', ''),
                'threshold' => number_format($threshold, 6, '.', ''),
                'impact' => $ratio >= 1.0 ? UiText::t('à examiner') : 'dans le seuil',
            ];
        }

        $score = min(1.0, $maximumRatio / 2.0);
        $label = match (true) {
            $maximumRatio >= 2.0 => UiText::t('priorité élevée'),
            $maximumRatio >= 1.0 => UiText::t('revue recommandée'),
            default => 'niveau habituel',
        };
        $recommendation = $maximumRatio >= 1.0
            ? UiText::t('Une revue humaine est recommandée avant toute décision.')
            : UiText::t('Aucune priorité particulière ; une revue humaine reste possible.');

        return new PredictionResult(
            schemaVersion: '1.0',
            externalId: 'rule_'.substr(hash('sha256', $input->rowId.'|'.config('intelligence.rule_baseline.version')), 0, 32),
            entityType: 'rental_contract',
            entityKey: $input->contractKey,
            source: 'rule',
            modelName: (string) config('intelligence.rule_baseline.name'),
            modelVersion: (string) config('intelligence.rule_baseline.version'),
            score: number_format($score, 6, '.', ''),
            label: $label,
            factors: $factors,
            recommendation: $recommendation,
            predictedAt: $input->eventAt,
        );
    }

    private function threshold(string $name): float
    {
        $configured = config('intelligence.rule_baseline.thresholds.'.$name);

        return is_numeric($configured) && (float) $configured > 0.0
            ? (float) $configured
            : 1.0;
    }
}
