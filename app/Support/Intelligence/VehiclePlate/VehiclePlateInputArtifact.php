<?php

namespace App\Support\Intelligence\VehiclePlate;

use App\Models\VehiclePlatePredictionRun;
use Illuminate\Support\Facades\Storage;
use Throwable;

class VehiclePlateInputArtifact
{
    public function valid(VehiclePlatePredictionRun $run): bool
    {
        $extension = (string) $run->input_extension;
        if (! in_array($extension, ['jpg', 'png', 'webp'], true)) {
            return false;
        }

        $expectedPath = sprintf(
            'intelligence/plate-hybrid/inputs/%d/%s.%s',
            $run->tenant_id,
            $run->run_id,
            $extension,
        );
        if (! hash_equals($expectedPath, (string) $run->input_stored_path)) {
            return false;
        }

        $disk = Storage::disk((string) config('intelligence.vehicle_plate_hybrid_review.disk'));

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
