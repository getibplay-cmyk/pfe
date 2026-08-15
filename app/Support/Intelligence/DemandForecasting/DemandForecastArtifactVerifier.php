<?php

namespace App\Support\Intelligence\DemandForecasting;

use App\Models\DemandForecastRun;
use App\Models\DemandHistoryExportRun;
use Illuminate\Support\Facades\Storage;
use Throwable;

final class DemandForecastArtifactVerifier
{
    public function validHistory(DemandHistoryExportRun $run): bool
    {
        return $this->valid(
            $run->stored_path,
            (int) $run->byte_size,
            $run->content_sha256,
        );
    }

    public function validForecast(DemandForecastRun $run): bool
    {
        return $this->valid(
            $run->stored_path,
            (int) $run->byte_size,
            $run->content_sha256,
        );
    }

    private function valid(string $path, int $expectedBytes, string $expectedDigest): bool
    {
        $disk = Storage::disk((string) config('intelligence.demand_forecasting.disk'));
        $stream = null;

        try {
            $stream = $disk->readStream($path);
            if (! is_resource($stream)) {
                return false;
            }

            $hash = hash_init('sha256');
            $bytes = hash_update_stream($hash, $stream);
            $digest = hash_final($hash);

            return $bytes === $expectedBytes && hash_equals($expectedDigest, $digest);
        } catch (Throwable) {
            return false;
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
    }
}
