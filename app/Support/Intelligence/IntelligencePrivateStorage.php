<?php

namespace App\Support\Intelligence;

use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

final class IntelligencePrivateStorage
{
    public const DISK = 'intelligence-private';

    public static function configured(string $moduleDiskConfigKey): bool
    {
        $diskName = config($moduleDiskConfigKey);
        if ($diskName !== self::DISK) {
            return false;
        }

        $configuration = config('filesystems.disks.'.self::DISK);
        if (! is_array($configuration)
            || ($configuration['driver'] ?? null) !== 'local'
            || ($configuration['serve'] ?? null) !== false
            || ($configuration['visibility'] ?? null) !== 'private'
            || ($configuration['directory_visibility'] ?? null) !== 'private'
            || ($configuration['throw'] ?? null) !== true
            || data_get($configuration, 'permissions.file.private') !== 0600
            || data_get($configuration, 'permissions.dir.private') !== 0700
            || self::hasPublicUrl($configuration)) {
            return false;
        }

        $configuredRoot = self::canonicalPath($configuration['root'] ?? null);
        $publicRoot = self::canonicalPath(public_path());
        if (! is_dir((string) ($configuration['root'] ?? ''))
            || $configuredRoot === null
            || $publicRoot === null
            || self::within($configuredRoot, $publicRoot)
            || self::publicLinkExposes($configuredRoot, $publicRoot)) {
            return false;
        }

        try {
            $adapterRootPath = Storage::disk(self::DISK)->path('');
            $adapterRoot = self::canonicalPath($adapterRootPath);

            return is_dir($adapterRootPath)
                && $adapterRoot !== null
                && ! self::within($adapterRoot, $publicRoot)
                && ! self::publicLinkExposes($adapterRoot, $publicRoot);
        } catch (Throwable) {
            return false;
        }
    }

    public static function disk(string $moduleDiskConfigKey): FilesystemAdapter
    {
        if (! self::configured($moduleDiskConfigKey)) {
            throw new RuntimeException('INTELLIGENCE_PRIVATE_STORAGE_INVALID');
        }

        return Storage::disk(self::DISK);
    }

    public static function path(string $moduleDiskConfigKey, string $storedPath): string
    {
        self::assertRelativePath($storedPath);
        $disk = self::disk($moduleDiskConfigKey);
        $root = self::canonicalPath($disk->path(''));

        try {
            $candidate = $disk->path($storedPath);
        } catch (Throwable $exception) {
            throw new RuntimeException(
                'INTELLIGENCE_PRIVATE_STORAGE_PATH_INVALID',
                previous: $exception,
            );
        }

        $resolved = self::canonicalPath($candidate);
        if ($root === null
            || $resolved === null
            || is_link($candidate)
            || ! is_file($resolved)
            || ! self::within($resolved, $root)) {
            throw new RuntimeException('INTELLIGENCE_PRIVATE_STORAGE_PATH_INVALID');
        }

        return $resolved;
    }

    /** @return resource|null */
    public static function readStream(string $moduleDiskConfigKey, string $storedPath): mixed
    {
        try {
            self::path($moduleDiskConfigKey, $storedPath);
            $stream = self::disk($moduleDiskConfigKey)->readStream($storedPath);
            if (! is_resource($stream)) {
                throw new RuntimeException('INTELLIGENCE_PRIVATE_STORAGE_READ_FAILED');
            }

            return $stream;
        } catch (Throwable $exception) {
            self::reportSanitizedFailure('INTELLIGENCE_PRIVATE_STORAGE_READ_FAILED', $exception);

            return null;
        }
    }

    public static function deleteAfterFailure(
        string $moduleDiskConfigKey,
        string $storedPath,
    ): void {
        try {
            self::assertRelativePath($storedPath);
            if (! self::disk($moduleDiskConfigKey)->delete($storedPath)) {
                throw new RuntimeException('INTELLIGENCE_PRIVATE_STORAGE_CLEANUP_FAILED');
            }
        } catch (Throwable $exception) {
            self::reportSanitizedFailure(
                'INTELLIGENCE_PRIVATE_STORAGE_CLEANUP_FAILED',
                $exception,
            );
        }
    }

    private static function assertRelativePath(string $storedPath): void
    {
        if ($storedPath === ''
            || str_contains($storedPath, "\0")
            || str_contains($storedPath, '\\')
            || str_starts_with($storedPath, '/')
            || preg_match('/^[A-Za-z]:\//D', $storedPath) === 1) {
            throw new RuntimeException('INTELLIGENCE_PRIVATE_STORAGE_PATH_INVALID');
        }

        $segments = explode('/', $storedPath);
        if (in_array('', $segments, true)
            || in_array('.', $segments, true)
            || in_array('..', $segments, true)) {
            throw new RuntimeException('INTELLIGENCE_PRIVATE_STORAGE_PATH_INVALID');
        }
    }

    /** @param array<string, mixed> $configuration */
    private static function hasPublicUrl(array $configuration): bool
    {
        return array_key_exists('url', $configuration)
            || array_key_exists('temporary_url', $configuration);
    }

    private static function publicLinkExposes(string $privateRoot, string $publicRoot): bool
    {
        foreach ((array) config('filesystems.links', []) as $link => $target) {
            $linkPath = self::canonicalPath($link);
            $targetPath = self::canonicalPath($target);
            if ($linkPath !== null
                && $targetPath !== null
                && self::within($linkPath, $publicRoot)
                && self::intersects($targetPath, $privateRoot)) {
                return true;
            }
        }

        $publicStorage = public_path('storage');
        if (file_exists($publicStorage) || is_link($publicStorage)) {
            $actualTarget = self::canonicalPath($publicStorage);

            return $actualTarget !== null && self::intersects($actualTarget, $privateRoot);
        }

        return false;
    }

    private static function canonicalPath(mixed $path): ?string
    {
        if (! is_string($path) || trim($path) === '' || str_contains($path, "\0")) {
            return null;
        }

        $resolved = realpath($path);
        $candidate = is_string($resolved) ? $resolved : $path;
        $normalized = rtrim(str_replace('\\', '/', $candidate), '/');
        if (! str_starts_with($normalized, '/')
            && preg_match('/^[A-Za-z]:\//D', $normalized) !== 1) {
            return null;
        }
        if (preg_match('#(^|/)\.\.?(/|$)#', $normalized) === 1) {
            return null;
        }

        return DIRECTORY_SEPARATOR === '\\' ? mb_strtolower($normalized) : $normalized;
    }

    private static function within(string $candidate, string $root): bool
    {
        return $candidate === $root || str_starts_with($candidate, $root.'/');
    }

    private static function intersects(string $left, string $right): bool
    {
        return self::within($left, $right) || self::within($right, $left);
    }

    private static function reportSanitizedFailure(string $code, Throwable $exception): void
    {
        try {
            report(new RuntimeException($code.' ['.$exception::class.']'));
        } catch (Throwable) {
            // Reporting must never replace the storage failure being handled.
        }
    }
}
