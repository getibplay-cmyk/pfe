<?php

namespace App\Support\Intelligence\VehicleDamage;

final class VehicleDamageContract
{
    public const MODEL_FILENAME = 'model.onnx';

    public const MODEL_CARD_FILENAME = 'model_card.json';

    public const RESULT_SCHEMA_VERSION = '1.0.0';

    public const MODEL_NAME = 'rentfleet_vehicle_damage_efficientnetv2s';

    public const MODEL_VERSION = 's7-damage-efficientnetv2s-v1.1';

    public const MODEL_CARD_ID = 'rentfleet-vehicle-damage-efficientnetv2s-v1';

    public const DECISION_THRESHOLD = 0.495;

    public const OVERLAP_RATIO = 0.35;

    public const MAX_CANDIDATES = 12;

    public const OPERATIONAL_EFFECT = 'NO_OPERATIONAL_ACTION';

    public const MODE = 'consultative_only';

    public const DOMAIN_VALIDATION_STATUS = 'NOT_VALIDATED_ON_RENTFLEET_PHOTOS';

    /** @var list<string> */
    public const QUALITY_STATUSES = ['usable', 'abstained'];

    /** @var list<string> */
    public const QUALITY_REASONS = [
        'TOO_SMALL',
        'TOO_DARK',
        'TOO_BRIGHT',
        'LOW_CONTRAST',
        'POSSIBLY_BLURRED',
    ];

    public static function qualityReasonLabel(string $reason): string
    {
        return match ($reason) {
            'TOO_SMALL' => 'Photo trop petite pour le scan par zones.',
            'TOO_DARK' => 'Photo trop sombre.',
            'TOO_BRIGHT' => 'Photo surexposée.',
            'LOW_CONTRAST' => 'Contraste insuffisant.',
            'POSSIBLY_BLURRED' => 'Photo potentiellement floue.',
            default => 'Qualité insuffisante.',
        };
    }
}
