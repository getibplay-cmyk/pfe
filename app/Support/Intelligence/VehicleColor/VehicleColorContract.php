<?php

namespace App\Support\Intelligence\VehicleColor;

final class VehicleColorContract
{
    public const MODEL_FILENAME = 'S7_COLOR_V8_FINAL.onnx';

    public const METADATA_FILENAME = 'S7_COLOR_V8_FINAL_METADATA.json';

    public const RESULT_SCHEMA_VERSION = '1.0.0';

    public const MODEL_NAME = 'vehicle_color_mobilenet_v3_large';

    public const MODEL_VERSION = 's7-color-v8.0.0';

    public const MODEL_SCHEMA_VERSION = '8.0.0';

    public const MODEL_ARTIFACT_SHA256 = '5ec7757a7bafda0abd45685dd8e1178e5b6b79220ff61b6018398d00f2e86a76';

    public const MODEL_ARTIFACT_BYTES = 16848914;

    public const METADATA_SHA256 = '661b0dcaa9b66fc69a2d8ba55eb21ec806e66c05d86c06ef4b2c5e7ff71901e6';

    public const METADATA_BYTES = 1987;

    public const ACCEPTED_THRESHOLD = 0.977;

    public const OPERATIONAL_EFFECT = 'NO_OPERATIONAL_ACTION';

    public const MODE = 'consultative_only';

    /** @var list<string> */
    public const SUPPORTED_COLORS = [
        'black',
        'blue',
        'gray',
        'green',
        'orange',
        'red',
        'white',
        'yellow',
    ];

    /** @var list<string> */
    public const CLASSES = [
        ...self::SUPPORTED_COLORS,
        '__reject__',
    ];

    public const REJECT_CLASS = '__reject__';

    public static function label(?string $color): string
    {
        return match ($color) {
            'black' => 'Noir',
            'blue' => 'Bleu',
            'gray' => 'Gris',
            'green' => 'Vert',
            'orange' => 'Orange',
            'red' => 'Rouge',
            'white' => 'Blanc',
            'yellow' => 'Jaune',
            default => 'Aucune couleur fiable',
        };
    }
}
