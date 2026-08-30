<?php

namespace Tests\Feature;

use App\Support\Intelligence\IntelligencePrivateStorage;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

class IntelligencePrivateStorageTest extends TestCase
{
    private const MODULE_DISK_KEYS = [
        'intelligence.vehicle_color_v8.disk',
        'intelligence.vehicle_damage_v1.disk',
        'intelligence.vehicle_plate_hybrid_review.disk',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake(IntelligencePrivateStorage::DISK);
    }

    public function test_disk_configuration_is_private_local_and_outside_the_webroot(): void
    {
        $configuration = config('filesystems.disks.'.IntelligencePrivateStorage::DISK);

        $this->assertIsArray($configuration);
        $this->assertSame('local', $configuration['driver'] ?? null);
        $this->assertFalse($configuration['serve'] ?? null);
        $this->assertSame('private', $configuration['visibility'] ?? null);
        $this->assertSame('private', $configuration['directory_visibility'] ?? null);
        $this->assertSame(0600, data_get($configuration, 'permissions.file.private'));
        $this->assertSame(0700, data_get($configuration, 'permissions.dir.private'));
        $this->assertTrue($configuration['throw'] ?? null);
        $this->assertFalse(array_key_exists('url', $configuration));
        $this->assertFalse(array_key_exists('temporary_url', $configuration));

        $privateRoot = $this->normalizedRealPath($configuration['root'] ?? null);
        $publicRoot = $this->normalizedRealPath(public_path());
        $this->assertTrue(
            is_string($privateRoot)
                && is_string($publicRoot)
                && ! $this->within($privateRoot, $publicRoot),
        );

        foreach (self::MODULE_DISK_KEYS as $configKey) {
            $this->assertSame(IntelligencePrivateStorage::DISK, config($configKey));
            $this->assertTrue(IntelligencePrivateStorage::configured($configKey));
        }
    }

    public function test_private_write_is_canonically_contained_and_modes_are_posix_only(): void
    {
        $storedPath = 'intelligence/storage-contract/private.bin';
        $disk = IntelligencePrivateStorage::disk(self::MODULE_DISK_KEYS[0]);

        $this->assertTrue($disk->put($storedPath, 'private-fixture', ['visibility' => 'private']));
        $resolved = IntelligencePrivateStorage::path(self::MODULE_DISK_KEYS[0], $storedPath);
        $root = $this->normalizedRealPath($disk->path(''));
        $file = $this->normalizedRealPath($resolved);

        $this->assertTrue(
            is_string($root)
                && is_string($file)
                && $this->within($file, $root)
                && is_file($resolved)
                && ! is_link($resolved),
        );

        $stream = IntelligencePrivateStorage::readStream(
            self::MODULE_DISK_KEYS[0],
            $storedPath,
        );
        $this->assertIsResource($stream);
        $this->assertSame('private-fixture', stream_get_contents($stream));
        fclose($stream);

        if (DIRECTORY_SEPARATOR !== '\\') {
            $this->assertSame(0600, fileperms($resolved) & 0777);
            $this->assertSame(0700, fileperms(dirname($resolved)) & 0777);
        }
    }

    public function test_traversal_absolute_paths_and_public_disk_selection_are_refused(): void
    {
        foreach ([
            '../private.bin',
            'intelligence/../private.bin',
            'intelligence\\..\\private.bin',
            '/absolute/private.bin',
            'C:/absolute/private.bin',
        ] as $unsafePath) {
            try {
                IntelligencePrivateStorage::path(self::MODULE_DISK_KEYS[0], $unsafePath);
                $this->fail('An unsafe Intelligence path was accepted.');
            } catch (RuntimeException $exception) {
                $this->assertSame(
                    'INTELLIGENCE_PRIVATE_STORAGE_PATH_INVALID',
                    $exception->getMessage(),
                );
            }
        }

        config([self::MODULE_DISK_KEYS[0] => 'public']);
        $this->assertFalse(IntelligencePrivateStorage::configured(self::MODULE_DISK_KEYS[0]));
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('INTELLIGENCE_PRIVATE_STORAGE_INVALID');
        IntelligencePrivateStorage::disk(self::MODULE_DISK_KEYS[0]);
    }

    public function test_object_driver_is_refused_while_no_object_adapter_contract_exists(): void
    {
        config(['filesystems.disks.'.IntelligencePrivateStorage::DISK.'.driver' => 's3']);

        foreach (self::MODULE_DISK_KEYS as $configKey) {
            $this->assertFalse(IntelligencePrivateStorage::configured($configKey));
        }
    }

    public function test_public_root_public_link_and_unavailable_root_fail_closed(): void
    {
        $diskRootKey = 'filesystems.disks.'.IntelligencePrivateStorage::DISK.'.root';
        $privateRoot = config($diskRootKey);

        config([$diskRootKey => public_path()]);
        $this->assertFalse(IntelligencePrivateStorage::configured(self::MODULE_DISK_KEYS[0]));

        config([
            $diskRootKey => $privateRoot,
            'filesystems.links' => [public_path('intelligence') => $privateRoot],
        ]);
        $this->assertFalse(IntelligencePrivateStorage::configured(self::MODULE_DISK_KEYS[0]));

        config([$diskRootKey => storage_path('app/private-missing-contract-root')]);
        $this->assertFalse(IntelligencePrivateStorage::configured(self::MODULE_DISK_KEYS[0]));
    }

    public function test_no_configured_or_actual_public_link_exposes_the_private_root(): void
    {
        $privateRoot = $this->normalizedRealPath(
            config('filesystems.disks.'.IntelligencePrivateStorage::DISK.'.root'),
        );
        $publicRoot = $this->normalizedRealPath(public_path());
        $this->assertIsString($privateRoot);
        $this->assertIsString($publicRoot);

        foreach ((array) config('filesystems.links', []) as $link => $target) {
            $linkPath = $this->normalizedPath($link);
            $targetPath = $this->normalizedPath($target);
            $exposesPrivateRoot = is_string($linkPath)
                && is_string($targetPath)
                && $this->within($linkPath, $publicRoot)
                && ($this->within($targetPath, $privateRoot)
                    || $this->within($privateRoot, $targetPath));
            $this->assertFalse($exposesPrivateRoot);
        }

        $publicStorage = public_path('storage');
        if (file_exists($publicStorage) || is_link($publicStorage)) {
            $target = $this->normalizedRealPath($publicStorage);
            $this->assertTrue(
                ! is_string($target)
                    || (! $this->within($target, $privateRoot)
                        && ! $this->within($privateRoot, $target)),
            );
        }
    }

    public function test_all_private_download_routes_refuse_anonymous_requests(): void
    {
        $runId = '123e4567-e89b-12d3-a456-426614174000';
        $routes = [
            ['intelligence.vehicle-colors.input', 'colorPrediction'],
            ['intelligence.vehicle-damages.input', 'damagePrediction'],
            ['intelligence.vehicle-plates.input', 'platePrediction'],
            ['intelligence.vehicle-plates.crop', 'platePrediction'],
        ];

        foreach ($routes as [$name, $parameter]) {
            $route = Route::getRoutes()->getByName($name);
            $this->assertNotNull($route);
            $middleware = $route->gatherMiddleware();
            $this->assertContains('auth', $middleware);
            $this->assertContains('tenant', $middleware);
            $this->assertContains('password.changed', $middleware);

            $this->get(route($name, [$parameter => $runId]))
                ->assertRedirect(route('login'));
        }
    }

    private function normalizedRealPath(mixed $path): ?string
    {
        if (! is_string($path)) {
            return null;
        }
        $resolved = realpath($path);
        if (! is_string($resolved)) {
            return null;
        }
        $normalized = rtrim(str_replace('\\', '/', $resolved), '/');

        return DIRECTORY_SEPARATOR === '\\' ? mb_strtolower($normalized) : $normalized;
    }

    private function normalizedPath(mixed $path): ?string
    {
        if (! is_string($path) || trim($path) === '') {
            return null;
        }
        $resolved = realpath($path);
        $normalized = rtrim(str_replace('\\', '/', is_string($resolved) ? $resolved : $path), '/');

        return DIRECTORY_SEPARATOR === '\\' ? mb_strtolower($normalized) : $normalized;
    }

    private function within(string $candidate, string $root): bool
    {
        return $candidate === $root || str_starts_with($candidate, $root.'/');
    }
}
