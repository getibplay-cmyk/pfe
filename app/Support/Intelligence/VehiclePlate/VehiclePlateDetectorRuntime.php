<?php

namespace App\Support\Intelligence\VehiclePlate;

use App\Exceptions\VehiclePlateHybridExecutionException;
use App\Models\VehiclePlatePredictionRun;
use Illuminate\Process\Exceptions\ProcessTimedOutException;
use Illuminate\Support\Facades\Process;
use Throwable;

class VehiclePlateDetectorRuntime
{
    private const MAX_OUTPUT_BYTES = 65_536;

    public function __construct(
        private readonly VehiclePlateDetectorArtifact $artifact,
        private readonly VehiclePlateDetectorResultValidator $validator,
    ) {}

    public function configured(): bool
    {
        $config = (array) config('intelligence.vehicle_plate_hybrid_review.detector', []);
        $sha256 = mb_strtolower((string) ($config['model_sha256'] ?? ''));
        $threshold = $config['threshold'] ?? null;
        $padding = $config['crop_padding_ratio'] ?? null;

        return (string) ($config['python_binary'] ?? '') !== ''
            && is_file((string) ($config['runtime_script'] ?? ''))
            && $this->artifact->configuredPathIsPrivate()
            && preg_match('/^[a-f0-9]{64}$/D', $sha256) === 1
            && in_array($config['device'] ?? null, ['cpu', 'gpu:0'], true)
            && is_int($config['timeout_seconds'] ?? null)
            && $config['timeout_seconds'] >= 1
            && $config['timeout_seconds'] <= 300
            && (is_int($threshold) || is_float($threshold))
            && (float) $threshold >= 0.001
            && (float) $threshold < 1
            && (is_int($padding) || is_float($padding))
            && (float) $padding >= 0
            && (float) $padding <= 0.25;
    }

    public function ready(): bool
    {
        return $this->configured() && $this->artifact->configuredIsValid();
    }

    public function execute(
        VehiclePlatePredictionRun $run,
        string $inputPath,
    ): ValidatedVehiclePlateDetection {
        if (! $this->configured()
            || ! $this->artifact->configuredIsValid()
            || ! is_file($inputPath)
            || is_link($inputPath)
            || $run->detector_model_name !== VehiclePlateDetectorContract::MODEL_NAME
            || ! is_string($run->detector_checkpoint_sha256)
            || ! hash_equals($run->detector_checkpoint_sha256, $this->artifact->configuredSha256())
            || abs((float) $run->detector_threshold - (float) config(
                'intelligence.vehicle_plate_hybrid_review.detector.threshold',
            )) > 0.0000001
            || abs((float) $run->detector_padding_ratio - (float) config(
                'intelligence.vehicle_plate_hybrid_review.detector.crop_padding_ratio',
            )) > 0.0000001) {
            throw new VehiclePlateHybridExecutionException('DETECTOR_RUNTIME_CONFIGURATION_INVALID');
        }

        $temporaryDirectory = sys_get_temp_dir()
            .DIRECTORY_SEPARATOR
            .'rentfleet-plate-detector-'
            .$run->run_id;
        if (file_exists($temporaryDirectory)
            || ! mkdir($temporaryDirectory, 0700)
            || ! chmod($temporaryDirectory, 0700)) {
            throw new VehiclePlateHybridExecutionException('DETECTOR_PROCESS_START_FAILED');
        }
        $outputPath = $temporaryDirectory.DIRECTORY_SEPARATOR.'result.json';
        $cropPath = $temporaryDirectory.DIRECTORY_SEPARATOR.'crop.jpg';
        $completed = false;

        try {
            $result = Process::path($temporaryDirectory)
                ->timeout((int) config(
                    'intelligence.vehicle_plate_hybrid_review.detector.timeout_seconds',
                ))
                ->env($this->closedEnvironment())
                ->run([
                    (string) config(
                        'intelligence.vehicle_plate_hybrid_review.detector.python_binary',
                    ),
                    (string) config(
                        'intelligence.vehicle_plate_hybrid_review.detector.runtime_script',
                    ),
                    '--run-id',
                    $run->run_id,
                    '--image-root',
                    dirname($inputPath),
                    '--image-name',
                    basename($inputPath),
                    '--output-root',
                    $temporaryDirectory,
                    '--checkpoint',
                    $this->artifact->configuredPath(),
                    '--expected-sha256',
                    $run->detector_checkpoint_sha256,
                    '--threshold',
                    (string) $run->detector_threshold,
                    '--padding-ratio',
                    (string) $run->detector_padding_ratio,
                    '--device',
                    (string) config('intelligence.vehicle_plate_hybrid_review.detector.device'),
                ]);
            if ($result->failed()) {
                throw new VehiclePlateHybridExecutionException('DETECTOR_PROCESS_FAILED');
            }
            if (! is_file($outputPath)
                || is_link($outputPath)
                || filesize($outputPath) < 1
                || filesize($outputPath) > self::MAX_OUTPUT_BYTES) {
                throw new VehiclePlateHybridExecutionException('DETECTOR_OUTPUT_INVALID');
            }
            $output = file_get_contents($outputPath);
            if (! is_string($output) || $output === '') {
                throw new VehiclePlateHybridExecutionException('DETECTOR_OUTPUT_INVALID');
            }

            $validated = $this->validator->validate($output, $run, $cropPath);
            $completed = true;

            return $validated;
        } catch (ProcessTimedOutException) {
            throw new VehiclePlateHybridExecutionException('DETECTOR_PROCESS_TIMEOUT');
        } catch (VehiclePlateHybridExecutionException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw new VehiclePlateHybridExecutionException('DETECTOR_PROCESS_START_FAILED');
        } finally {
            try {
                VehiclePlateTemporaryPathCleaner::removeFile($cropPath);
                VehiclePlateTemporaryPathCleaner::removeFile($outputPath);
                VehiclePlateTemporaryPathCleaner::removeDirectory($temporaryDirectory);
            } catch (Throwable $cleanupException) {
                VehiclePlateTemporaryPathCleaner::reportFailure($cleanupException);
                if ($completed) {
                    throw new VehiclePlateHybridExecutionException(
                        'PLATE_TEMPORARY_CLEANUP_FAILED',
                    );
                }
            }
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
            'PLATE_DETECTOR_MODEL_PATH' => false,
            'PLATE_DETECTOR_MODEL_SHA256' => false,
        ];
    }
}
