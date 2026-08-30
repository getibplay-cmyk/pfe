<?php

namespace App\Support\Intelligence\VehicleColor;

use App\Models\VehicleColorPredictionRun;
use App\Support\Intelligence\IntelligencePrivateStorage;
use Throwable;

class VehicleColorInputArtifact
{
    public function valid(VehicleColorPredictionRun $run): bool
    {
        $extension = (string) $run->input_extension;
        if (! in_array($extension, ['jpg', 'png', 'webp'], true)) {
            return false;
        }

        $expectedPath = sprintf(
            'intelligence/color-v8/inputs/%d/%s.%s',
            $run->tenant_id,
            $run->run_id,
            $extension,
        );
        if (! hash_equals($expectedPath, (string) $run->input_stored_path)) {
            return false;
        }

        try {
            $path = IntelligencePrivateStorage::path(
                'intelligence.vehicle_color_v8.disk',
                (string) $run->input_stored_path,
            );
            if (! is_file($path) || is_link($path)) {
                return false;
            }

            $bytes = filesize($path);
            $sha256 = hash_file('sha256', $path);

            return $bytes === $run->input_bytes
                && is_string($sha256)
                && hash_equals($run->input_sha256, $sha256);
        } catch (Throwable) {
            return false;
        }
    }
}
