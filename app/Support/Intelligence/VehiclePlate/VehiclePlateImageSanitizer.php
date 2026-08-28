<?php

namespace App\Support\Intelligence\VehiclePlate;

use App\Exceptions\VehiclePlateRuntimeUnavailableException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Process;
use Throwable;

class VehiclePlateImageSanitizer
{
    private const SCHEMA_VERSION = '1.0.0';

    private const MAX_MANIFEST_BYTES = 4096;

    public function sanitize(UploadedFile $image): SanitizedVehiclePlateImage
    {
        $source = $image->getRealPath();
        $sourceMime = (string) $image->getMimeType();
        if (! in_array($sourceMime, ['image/jpeg', 'image/png', 'image/webp'], true)) {
            throw new VehiclePlateRuntimeUnavailableException;
        }
        $binary = (string) config('intelligence.vehicle_plate_hybrid_review.python_binary');
        $script = (string) config('intelligence.vehicle_plate_hybrid_review.image_sanitizer_script');
        $timeout = (int) config('intelligence.vehicle_plate_hybrid_review.image_sanitizer_timeout_seconds');
        $maxBytes = (int) config('intelligence.vehicle_plate_hybrid_review.max_upload_kilobytes') * 1024;
        $maxDimension = (int) config('intelligence.vehicle_plate_hybrid_review.max_image_dimension');
        $outputMaxDimension = (int) config(
            'intelligence.vehicle_plate_hybrid_review.max_stored_image_dimension',
        );
        if (! is_string($source)
            || ! is_file($source)
            || is_link($source)
            || $binary === ''
            || $script === ''
            || ! is_file($script)
            || $timeout < 1
            || $timeout > 15
            || $maxBytes < 1
            || $maxBytes > 8_388_608
            || $maxDimension < 1
            || $maxDimension > 8_000
            || $outputMaxDimension < 256
            || $outputMaxDimension > 4_096) {
            throw new VehiclePlateRuntimeUnavailableException;
        }

        $temporary = tempnam(sys_get_temp_dir(), 'rentfleet-plate-sanitize-');
        if (! is_string($temporary) || ! chmod($temporary, 0600)) {
            if (is_string($temporary)) {
                @unlink($temporary);
            }

            throw new VehiclePlateRuntimeUnavailableException;
        }

        try {
            $result = Process::path(sys_get_temp_dir())
                ->timeout($timeout)
                ->env($this->closedEnvironment())
                ->run([
                    $binary,
                    $script,
                    '--input',
                    $source,
                    '--output',
                    $temporary,
                    '--expected-mime',
                    $sourceMime,
                    '--max-bytes',
                    (string) $maxBytes,
                    '--max-dimension',
                    (string) $maxDimension,
                    '--output-max-dimension',
                    (string) $outputMaxDimension,
                ]);
            $output = $result->output();
            if ($result->failed() || $output === '' || strlen($output) > self::MAX_MANIFEST_BYTES) {
                throw new VehiclePlateRuntimeUnavailableException;
            }

            $manifest = json_decode($output, true, flags: JSON_THROW_ON_ERROR);
            $this->assertManifest(
                $manifest,
                $temporary,
                $sourceMime,
                $maxBytes,
                $outputMaxDimension,
            );
            $contents = file_get_contents($temporary);
            if (! is_string($contents)) {
                throw new VehiclePlateRuntimeUnavailableException;
            }

            return new SanitizedVehiclePlateImage(
                contents: $contents,
                mime: $manifest['mime'],
                extension: $manifest['extension'],
                bytes: strlen($contents),
                sha256: hash('sha256', $contents),
                width: $manifest['width'],
                height: $manifest['height'],
            );
        } catch (VehiclePlateRuntimeUnavailableException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw new VehiclePlateRuntimeUnavailableException;
        } finally {
            @unlink($temporary);
        }
    }

    private function assertManifest(
        mixed $manifest,
        string $path,
        string $sourceMime,
        int $maxBytes,
        int $outputMaxDimension,
    ): void {
        if (! is_array($manifest)) {
            throw new VehiclePlateRuntimeUnavailableException;
        }
        $expectedKeys = [
            'schema_version',
            'source_mime',
            'mime',
            'extension',
            'bytes',
            'sha256',
            'width',
            'height',
            'metadata_removed',
        ];
        $actualKeys = array_keys($manifest);
        sort($expectedKeys);
        sort($actualKeys);
        $bytes = is_file($path) ? filesize($path) : false;
        $sha256 = is_file($path) ? hash_file('sha256', $path) : false;
        $dimensions = is_file($path) ? @getimagesize($path) : false;
        $detectedMime = is_file($path) ? (new \finfo(FILEINFO_MIME_TYPE))->file($path) : false;
        if ($actualKeys !== $expectedKeys
            || $manifest['schema_version'] !== self::SCHEMA_VERSION
            || $manifest['source_mime'] !== $sourceMime
            || $manifest['mime'] !== 'image/jpeg'
            || $manifest['extension'] !== 'jpg'
            || $manifest['metadata_removed'] !== true
            || ! is_int($manifest['bytes'])
            || ! is_int($manifest['width'])
            || ! is_int($manifest['height'])
            || $bytes !== $manifest['bytes']
            || $bytes < 1
            || $bytes > $maxBytes
            || ! is_string($sha256)
            || ! is_string($manifest['sha256'])
            || ! hash_equals($sha256, $manifest['sha256'])
            || ! is_array($dimensions)
            || ($dimensions[0] ?? null) !== $manifest['width']
            || ($dimensions[1] ?? null) !== $manifest['height']
            || $manifest['width'] < 1
            || $manifest['height'] < 1
            || $manifest['width'] > $outputMaxDimension
            || $manifest['height'] > $outputMaxDimension
            || $detectedMime !== $manifest['mime']) {
            throw new VehiclePlateRuntimeUnavailableException;
        }
    }

    /** @return array<string, string|false> */
    private function closedEnvironment(): array
    {
        return [
            'PYTHONDONTWRITEBYTECODE' => '1',
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
