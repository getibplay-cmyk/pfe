<?php

namespace App\Support\Intelligence\VehiclePlate;

use RuntimeException;
use Throwable;

final class VehiclePlateTemporaryPathCleaner
{
    public static function removeFile(string $path): void
    {
        if (! file_exists($path) && ! is_link($path)) {
            return;
        }
        if (! unlink($path)) {
            throw new RuntimeException('PLATE_TEMPORARY_CLEANUP_FAILED');
        }
    }

    public static function removeDirectory(string $path): void
    {
        if (! file_exists($path) && ! is_link($path)) {
            return;
        }
        if (is_link($path)) {
            self::removeFile($path);

            return;
        }
        if (! is_dir($path) || ! rmdir($path)) {
            throw new RuntimeException('PLATE_TEMPORARY_CLEANUP_FAILED');
        }
    }

    public static function reportFailure(Throwable $exception): void
    {
        try {
            report(new RuntimeException(
                'PLATE_TEMPORARY_CLEANUP_FAILED ['.$exception::class.']',
            ));
        } catch (Throwable) {
            // Reporting must never replace the primary runtime failure.
        }
    }
}
