<?php

namespace App\Support\Intelligence\VehiclePlate;

final class VehiclePlateHybridContract
{
    public const RESULT_SCHEMA_VERSION = '1.0.0';

    public const FALLBACK_VERSION = '1.0.0';

    public const SUGGESTION_SCHEMA_VERSION = '1.0.0';

    public const MODEL_NAME = 'arabic_PP-OCRv5_mobile_rec';

    public const MODE = 'consultative_review_only';

    public const OPERATIONAL_EFFECT = 'NO_OPERATIONAL_ACTION';

    public const CONFIDENCE_SEMANTICS = 'uncalibrated_evidence_score';

    /** @var list<string> */
    public const STATUSES = [
        'complete_primary_suggestion',
        'complete_segmented_suggestion',
        'ambiguous_segmented_suggestion',
        'partial_segmented_suggestion',
        'empty_suggestion',
    ];

    /** @var list<string> */
    public const COMPLETE_STATUSES = [
        'complete_primary_suggestion',
        'complete_segmented_suggestion',
        'ambiguous_segmented_suggestion',
    ];

    /** @var list<string> */
    public const COMPONENT_ROLES = ['serial', 'series', 'region'];

    /** @var list<string> */
    public const OFFICIAL_ARABIC_SERIES = [
        'أ', 'ب', 'د', 'ه', 'و', 'ط', 'ي', 'ك', 'ل', 'م', 'ن', 'ص', 'ف', 'ر', 'س',
    ];

    public static function isCanonical(string $value): bool
    {
        $series = implode('', self::OFFICIAL_ARABIC_SERIES);

        return preg_match('/\A[1-9][0-9]{0,4}\|['.$series.']\|[1-9][0-9]?\z/u', $value) === 1;
    }
}
