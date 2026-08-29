<?php

namespace App\Support\Intelligence\VehiclePlate;

final class VehiclePlateDetectorContract
{
    public const RESULT_SCHEMA_VERSION = '1.0.0';

    public const MODEL_NAME = 'fasterrcnn_resnet50_fpn_v2_e32_selected_private';

    public const ARCHITECTURE = 'fasterrcnn_resnet50_fpn_v2';

    public const FULL_IMAGE = 'full_vehicle_image';

    public const PLATE_CROP = 'plate_crop';

    /** @var list<string> */
    public const INPUT_KINDS = [self::FULL_IMAGE, self::PLATE_CROP];

    /** @var list<string> */
    public const STATUSES = ['detected', 'no_detection', 'ambiguous'];

    public static function isInputKind(string $value): bool
    {
        return in_array($value, self::INPUT_KINDS, true);
    }
}
