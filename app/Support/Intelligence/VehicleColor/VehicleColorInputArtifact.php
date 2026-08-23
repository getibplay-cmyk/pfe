<?php

namespace App\Support\Intelligence\VehicleColor;

use App\Models\VehicleColorPredictionRun;
use Illuminate\Support\Facades\Storage;
use Throwable;

class VehicleColorInputArtifact
{
    public function valid(VehicleColorPredictionRun $run): bool
    {
        $disk = Storage::disk((string) config('intelligence.vehicle_color_v8.disk'));

        try {
            if (! $disk->exists($run->input_stored_path)) {
                return false;
            }

            $path = $disk->path($run->input_stored_path);
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
