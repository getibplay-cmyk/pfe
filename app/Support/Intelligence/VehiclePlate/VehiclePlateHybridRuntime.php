<?php

namespace App\Support\Intelligence\VehiclePlate;

use App\Exceptions\VehiclePlateHybridExecutionException;
use App\Models\VehiclePlatePredictionRun;
use Illuminate\Process\Exceptions\ProcessTimedOutException;
use Illuminate\Support\Facades\Process;
use JsonException;
use Throwable;

class VehiclePlateHybridRuntime
{
    private const MAX_OUTPUT_BYTES = 262_144;

    public function configured(): bool
    {
        $binary = (string) config('intelligence.vehicle_plate_hybrid_review.python_binary');
        $script = (string) config('intelligence.vehicle_plate_hybrid_review.runtime_script');
        $device = (string) config('intelligence.vehicle_plate_hybrid_review.device');
        $timeout = (int) config('intelligence.vehicle_plate_hybrid_review.runtime_timeout_seconds');

        return $binary !== ''
            && $script !== ''
            && is_file($script)
            && in_array($device, ['cpu', 'gpu:0'], true)
            && $timeout >= 1
            && $timeout <= 300;
    }

    public function execute(VehiclePlatePredictionRun $run, string $inputPath): string
    {
        if (! $this->configured()
            || ! is_file($inputPath)
            || is_link($inputPath)) {
            throw new VehiclePlateHybridExecutionException('RUNTIME_CONFIGURATION_INVALID');
        }

        $temporaryDirectory = sys_get_temp_dir()
            .DIRECTORY_SEPARATOR
            .'rentfleet-plate-hybrid-'
            .$run->run_id;
        if (file_exists($temporaryDirectory)
            || ! mkdir($temporaryDirectory, 0700)
            || ! chmod($temporaryDirectory, 0700)) {
            throw new VehiclePlateHybridExecutionException('PLATE_PROCESS_START_FAILED');
        }

        $manifestPath = $temporaryDirectory.DIRECTORY_SEPARATOR.'manifest.json';
        $outputPath = $temporaryDirectory.DIRECTORY_SEPARATOR.'result.json';
        try {
            $this->writeManifest($manifestPath, $run, $inputPath);
            $result = Process::path($temporaryDirectory)
                ->timeout((int) config('intelligence.vehicle_plate_hybrid_review.runtime_timeout_seconds'))
                ->env($this->closedEnvironment())
                ->run([
                    (string) config('intelligence.vehicle_plate_hybrid_review.python_binary'),
                    (string) config('intelligence.vehicle_plate_hybrid_review.runtime_script'),
                    '--manifest',
                    $manifestPath,
                    '--crop-root',
                    dirname($inputPath),
                    '--output',
                    $outputPath,
                    '--device',
                    (string) config('intelligence.vehicle_plate_hybrid_review.device'),
                ]);
            if ($result->failed()) {
                throw new VehiclePlateHybridExecutionException('PLATE_PROCESS_FAILED');
            }
            if (! is_file($outputPath)
                || is_link($outputPath)
                || filesize($outputPath) < 1
                || filesize($outputPath) > self::MAX_OUTPUT_BYTES) {
                throw new VehiclePlateHybridExecutionException('PLATE_OUTPUT_INVALID');
            }
            $output = file_get_contents($outputPath);
            if (! is_string($output) || $output === '') {
                throw new VehiclePlateHybridExecutionException('PLATE_OUTPUT_INVALID');
            }

            return $output;
        } catch (ProcessTimedOutException) {
            throw new VehiclePlateHybridExecutionException('PLATE_PROCESS_TIMEOUT');
        } catch (VehiclePlateHybridExecutionException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw new VehiclePlateHybridExecutionException('PLATE_PROCESS_START_FAILED');
        } finally {
            @unlink($outputPath);
            @unlink($manifestPath);
            @rmdir($temporaryDirectory);
        }
    }

    /** @throws JsonException */
    private function writeManifest(
        string $manifestPath,
        VehiclePlatePredictionRun $run,
        string $inputPath,
    ): void {
        $manifest = json_encode([
            'schema_version' => VehiclePlateHybridContract::RESULT_SCHEMA_VERSION,
            'model_name' => VehiclePlateHybridContract::MODEL_NAME,
            'batch_size' => 1,
            'crops' => [[
                'crop_id' => $run->run_id,
                'image_path' => basename($inputPath),
            ]],
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (file_put_contents($manifestPath, $manifest, LOCK_EX) !== strlen($manifest)
            || ! chmod($manifestPath, 0600)) {
            throw new VehiclePlateHybridExecutionException('PLATE_PROCESS_START_FAILED');
        }
    }

    /** @return array<string, string|false> */
    private function closedEnvironment(): array
    {
        return [
            'PYTHONDONTWRITEBYTECODE' => '1',
            'PYTHONHASHSEED' => '20260828',
            'APP_KEY' => false,
            'DATABASE_URL' => false,
            'DB_URL' => false,
            'DB_USERNAME' => false,
            'DB_PASSWORD' => false,
            'REDIS_PASSWORD' => false,
            'MAIL_USERNAME' => false,
            'MAIL_PASSWORD' => false,
            'AWS_ACCESS_KEY_ID' => false,
            'AWS_SECRET_ACCESS_KEY' => false,
            'AWS_SESSION_TOKEN' => false,
            'INTELLIGENCE_EXPORT_HMAC_KEY' => false,
            'DEMO_PASSWORD' => false,
            'OPENAI_API_KEY' => false,
            'STRIPE_SECRET' => false,
            'PGPASSWORD' => false,
            'PLATE_RECOGNIZER_TOKEN' => false,
            'GOOGLE_APPLICATION_CREDENTIALS' => false,
        ];
    }
}
