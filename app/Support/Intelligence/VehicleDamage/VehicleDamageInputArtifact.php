<?php

namespace App\Support\Intelligence\VehicleDamage;

use App\Models\VehicleDamagePredictionRun;
use Illuminate\Support\Facades\Storage;
use Throwable;

class VehicleDamageInputArtifact
{
    public function valid(VehicleDamagePredictionRun $run): bool
    {
        $expected = 'intelligence/vehicle-damage/inputs/'
            .$run->tenant_id.'/'.$run->run_id.'.jpg';
        if ($run->input_stored_path !== $expected
            || $run->input_mime !== 'image/jpeg'
            || $run->input_extension !== 'jpg'
            || ! is_numeric($run->input_bytes)
            || (int) $run->input_bytes < 1
            || (int) $run->input_bytes > 8_388_608
            || preg_match('/^[a-f0-9]{64}$/D', (string) $run->input_sha256) !== 1) {
            return false;
        }

        try {
            $disk = Storage::disk((string) config('intelligence.vehicle_damage_v1.disk'));
            if (! $disk->exists($expected)) {
                return false;
            }
            $path = $disk->path($expected);
            $bytes = filesize($path);
            $sha256 = hash_file('sha256', $path);
            $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($path);
            $dimensions = @getimagesize($path);

            return ! is_link($path)
                && $bytes === (int) $run->input_bytes
                && is_string($sha256)
                && hash_equals((string) $run->input_sha256, $sha256)
                && $mime === 'image/jpeg'
                && is_array($dimensions)
                && ($dimensions[0] ?? null) === (int) $run->input_width
                && ($dimensions[1] ?? null) === (int) $run->input_height;
        } catch (Throwable) {
            return false;
        }
    }
}
