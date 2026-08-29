<?php

namespace App\Support\Intelligence\VehicleDamage;

final class VehicleDamageContract
{
    public const BACKEND_RTDETRV2_S = 'rtdetrv2_s';

    public const BACKEND_EFFICIENTNETV2S = 'efficientnetv2s';

    public const MODEL_FILENAME = 'model.onnx';

    public const MODEL_CARD_FILENAME = 'model_card.json';

    public const RESULT_SCHEMA_VERSION = '1.0.0';

    public const MODEL_NAME = 'rentfleet_vehicle_damage_rtdetrv2_s';

    public const MODEL_VERSION = 's7-damage-rtdetrv2-s-soup192429-v1.0';

    public const MODEL_CARD_ID = 'rentfleet-vehicle-damage-rtdetrv2-s-soup-19-24-29-v1';

    public const DECISION_THRESHOLD = 0.8236151338;

    public const NMS_IOU_THRESHOLD = 0.72;

    public const INPUT_SIZE = 640;

    public const SOURCE_CHECKPOINT_SHA256 = '3544b693d9014392b5a9a0d87e6951646455ed268ca1825ee5aa4fe07cd7b92e';

    public const VALIDATION_AP = 0.2967751101;

    public const VALIDATION_AP50 = 0.4775844593;

    public const VALIDATION_AP75 = 0.2862142242;

    public const VALIDATION_PRECISION_IOU50 = 0.9009009009;

    public const VALIDATION_RECALL_IOU50 = 0.2258610954;

    public const LEGACY_MODEL_NAME = 'rentfleet_vehicle_damage_efficientnetv2s';

    public const LEGACY_MODEL_VERSION = 's7-damage-efficientnetv2s-v1.1';

    public const LEGACY_MODEL_CARD_ID = 'rentfleet-vehicle-damage-efficientnetv2s-v1';

    public const LEGACY_DECISION_THRESHOLD = 0.495;

    public const OVERLAP_RATIO = 0.35;

    public const MAX_CANDIDATES = 12;

    public const OPERATIONAL_EFFECT = 'NO_OPERATIONAL_ACTION';

    public const MODE = 'consultative_only';

    public const DOMAIN_VALIDATION_STATUS = 'NOT_VALIDATED_ON_RENTFLEET_PHOTOS';

    public static function backend(): string
    {
        $backend = (string) config(
            'intelligence.vehicle_damage_v1.backend',
            self::BACKEND_RTDETRV2_S,
        );

        return in_array($backend, [self::BACKEND_RTDETRV2_S, self::BACKEND_EFFICIENTNETV2S], true)
            ? $backend
            : self::BACKEND_RTDETRV2_S;
    }

    public static function modelName(): string
    {
        return self::backend() === self::BACKEND_RTDETRV2_S
            ? self::MODEL_NAME
            : self::LEGACY_MODEL_NAME;
    }

    public static function modelVersion(): string
    {
        return self::backend() === self::BACKEND_RTDETRV2_S
            ? self::MODEL_VERSION
            : self::LEGACY_MODEL_VERSION;
    }

    public static function modelCardId(): string
    {
        return self::backend() === self::BACKEND_RTDETRV2_S
            ? self::MODEL_CARD_ID
            : self::LEGACY_MODEL_CARD_ID;
    }

    public static function decisionThreshold(): float
    {
        return self::backend() === self::BACKEND_RTDETRV2_S
            ? self::DECISION_THRESHOLD
            : self::LEGACY_DECISION_THRESHOLD;
    }

    public static function scanMode(): string
    {
        return self::scanModeForBackend(self::backend());
    }

    public static function scanModeForBackend(string $backend): string
    {
        return $backend === self::BACKEND_RTDETRV2_S
            ? 'full_image_rtdetrv2_s'
            : 'coarse_overlapping_patches';
    }

    public static function overlapRatio(): float
    {
        return self::overlapRatioForBackend(self::backend());
    }

    public static function overlapRatioForBackend(string $backend): float
    {
        return $backend === self::BACKEND_RTDETRV2_S ? 0.0 : self::OVERLAP_RATIO;
    }

    public static function backendForModelName(string $modelName): ?string
    {
        return match ($modelName) {
            self::MODEL_NAME => self::BACKEND_RTDETRV2_S,
            self::LEGACY_MODEL_NAME => self::BACKEND_EFFICIENTNETV2S,
            default => null,
        };
    }

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
            'TOO_SMALL' => 'Photo trop petite pour l’analyse.',
            'TOO_DARK' => 'Photo trop sombre.',
            'TOO_BRIGHT' => 'Photo surexposée.',
            'LOW_CONTRAST' => 'Contraste insuffisant.',
            'POSSIBLY_BLURRED' => 'Photo potentiellement floue.',
            default => 'Qualité insuffisante.',
        };
    }
}
