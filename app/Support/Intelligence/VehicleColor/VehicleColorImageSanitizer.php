<?php

namespace App\Support\Intelligence\VehicleColor;

use App\Exceptions\VehicleColorRuntimeUnavailableException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Process;
use Throwable;

class VehicleColorImageSanitizer
{
    private const SCHEMA_VERSION = '1.0.0';

    private const MAX_MANIFEST_BYTES = 4096;

    public function sanitize(UploadedFile $image): SanitizedVehicleColorImage
    {
        $source = $image->getRealPath();
        $mime = (string) $image->getMimeType();
        $extension = match ($mime) {
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            default => throw new VehicleColorRuntimeUnavailableException,
        };
        $binary = (string) config('intelligence.vehicle_color_v8.python_binary');
        $script = (string) config('intelligence.vehicle_color_v8.image_sanitizer_script');
        $timeout = (int) config('intelligence.vehicle_color_v8.image_sanitizer_timeout_seconds');
        $maxBytes = (int) config('intelligence.vehicle_color_v8.max_upload_kilobytes') * 1024;
        $maxDimension = (int) config('intelligence.vehicle_color_v8.max_image_dimension');
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
            || $maxDimension > 8_000) {
            throw new VehicleColorRuntimeUnavailableException;
        }

        $temporary = tempnam(sys_get_temp_dir(), 'rentfleet-color-v8-sanitize-');
        if (! is_string($temporary) || ! chmod($temporary, 0600)) {
            if (is_string($temporary)) {
                @unlink($temporary);
            }

            throw new VehicleColorRuntimeUnavailableException;
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
                    $mime,
                    '--max-bytes',
                    (string) $maxBytes,
                    '--max-dimension',
                    (string) $maxDimension,
                ]);
            $output = $result->output();
            if ($result->failed() || $output === '' || strlen($output) > self::MAX_MANIFEST_BYTES) {
                throw new VehicleColorRuntimeUnavailableException;
            }

            $manifest = json_decode($output, true, flags: JSON_THROW_ON_ERROR);
            $this->assertManifest($manifest, $temporary, $mime, $extension, $maxBytes, $maxDimension);
            $contents = file_get_contents($temporary);
            if (! is_string($contents)) {
                throw new VehicleColorRuntimeUnavailableException;
            }

            return new SanitizedVehicleColorImage(
                contents: $contents,
                mime: $mime,
                extension: $extension,
                bytes: strlen($contents),
                sha256: hash('sha256', $contents),
                width: $manifest['width'],
                height: $manifest['height'],
            );
        } catch (VehicleColorRuntimeUnavailableException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw new VehicleColorRuntimeUnavailableException;
        } finally {
            @unlink($temporary);
        }
    }

    private function assertManifest(
        mixed $manifest,
        string $path,
        string $mime,
        string $extension,
        int $maxBytes,
        int $maxDimension,
    ): void {
        if (! is_array($manifest)) {
            throw new VehicleColorRuntimeUnavailableException;
        }
        $expectedKeys = [
            'schema_version',
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
            || $manifest['mime'] !== $mime
            || $manifest['extension'] !== $extension
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
            || $manifest['width'] > $maxDimension
            || $manifest['height'] > $maxDimension
            || $detectedMime !== $mime) {
            throw new VehicleColorRuntimeUnavailableException;
        }
    }

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
        ];
    }
}
