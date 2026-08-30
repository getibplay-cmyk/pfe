<?php

namespace App\Support\Intelligence\VehiclePlate;

use App\Models\VehiclePlatePredictionRun;
use App\Support\Intelligence\IntelligencePrivateStorage;
use Throwable;

class VehiclePlateInputArtifact
{
    public function valid(VehiclePlatePredictionRun $run): bool
    {
        $extension = (string) $run->input_extension;
        if ($extension !== 'jpg') {
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

        try {
            $path = IntelligencePrivateStorage::path(
                'intelligence.vehicle_plate_hybrid_review.disk',
                (string) $run->input_stored_path,
            );
            if (! is_file($path) || is_link($path)) {
                return false;
            }

            return $this->matches(
                $path,
                (int) $run->input_bytes,
                (string) $run->input_sha256,
                (int) $run->input_width,
                (int) $run->input_height,
            );
        } catch (Throwable) {
            return false;
        }
    }

    public function validReviewCrop(VehiclePlatePredictionRun $run): bool
    {
        if (! $run->usesDetector()) {
            return $this->valid($run);
        }
        if (! $run->hasDetectedCrop()
            || $run->crop_mime !== 'image/jpeg'
            || $run->crop_extension !== 'jpg') {
            return false;
        }
        $expectedPath = sprintf(
            'intelligence/plate-hybrid/crops/%d/%s.jpg',
            $run->tenant_id,
            $run->run_id,
        );
        if (! hash_equals($expectedPath, (string) $run->crop_stored_path)) {
            return false;
        }

        try {
            return $this->matches(
                IntelligencePrivateStorage::path(
                    'intelligence.vehicle_plate_hybrid_review.disk',
                    (string) $run->crop_stored_path,
                ),
                (int) $run->crop_bytes,
                (string) $run->crop_sha256,
                (int) $run->crop_width,
                (int) $run->crop_height,
            );
        } catch (Throwable) {
            return false;
        }
    }

    public function reviewCropStoredPath(VehiclePlatePredictionRun $run): string
    {
        return $run->usesDetector()
            ? (string) $run->crop_stored_path
            : (string) $run->input_stored_path;
    }

    public function reviewCropBytes(VehiclePlatePredictionRun $run): int
    {
        return $run->usesDetector() ? (int) $run->crop_bytes : (int) $run->input_bytes;
    }

    private function matches(
        string $path,
        int $expectedBytes,
        string $expectedSha256,
        int $expectedWidth,
        int $expectedHeight,
    ): bool {
        if (! is_file($path) || is_link($path)) {
            return false;
        }
        $bytes = filesize($path);
        $sha256 = hash_file('sha256', $path);
        $dimensions = getimagesize($path);

        return $bytes === $expectedBytes
            && is_string($sha256)
            && hash_equals($expectedSha256, $sha256)
            && is_array($dimensions)
            && ($dimensions['mime'] ?? null) === 'image/jpeg'
            && ($dimensions[0] ?? null) === $expectedWidth
            && ($dimensions[1] ?? null) === $expectedHeight;
    }
}
