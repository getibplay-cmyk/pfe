<?php

namespace App\Support\Intelligence\RentalUsageAnomaly;

use App\Support\Ui\UiText;

final class RentalUsageAnomalyContract
{
    public const CHALLENGER_MODEL = 'isolation_forest';

    public const CHALLENGER_VERSION = '1.0.0';

    public const DEFAULT_BUDGET_BASIS_POINTS = 100;

    public const MINIMUM_ROWS = 200;

    public const OPERATIONAL_EFFECT = 'NO_OPERATIONAL_ACTION';

    public const PRIMARY_MODEL = 'robust_mad_top2';

    public const PRIMARY_VERSION = '1.0.0';

    public const RANDOM_STATE = 20260824;

    public const SCHEMA_VERSION = '1.0.0';

    /** @var list<int> */
    public const BUDGETS = [50, 100, 200];

    /** @var list<string> */
    public const FEATURES = ['late_hours', 'km_per_day', 'fuel_drop_pct'];

    public static function featureLabel(string $feature): string
    {
        return match ($feature) {
            'late_hours' => UiText::t('Retard au retour'),
            'km_per_day' => UiText::t('Kilomètres par jour'),
            'fuel_drop_pct' => UiText::t('Baisse de carburant'),
            default => UiText::t('Facteur non documenté'),
        };
    }

    public static function featureUnit(string $feature): string
    {
        return match ($feature) {
            'late_hours' => 'h',
            'km_per_day' => 'km/jour',
            'fuel_drop_pct' => 'points',
            default => '',
        };
    }
}
