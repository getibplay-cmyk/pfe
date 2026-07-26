<?php

namespace Tests;

use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Testing\TestDatabaseGuard;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;

abstract class TestCase extends BaseTestCase
{
    public function createApplication(): Application
    {
        $app = parent::createApplication();
        TestDatabaseGuard::assertSafe($app);

        return $app;
    }

    protected function assertUsesAuthorizedPostgreSqlTestDatabase(): string
    {
        TestDatabaseGuard::assertSafe($this->app);

        $this->assertSame('testing', app()->environment());
        $this->assertSame(TestDatabaseGuard::REQUIRED_CONNECTION, DB::connection()->getDriverName());

        $database = DB::connection()->getDatabaseName();
        $this->assertContains($database, [
            TestDatabaseGuard::REQUIRED_DATABASE,
            TestDatabaseGuard::ACCEPTANCE_DATABASE,
        ]);

        if ($database === TestDatabaseGuard::ACCEPTANCE_DATABASE) {
            $this->assertSame('1', env(TestDatabaseGuard::ACCEPTANCE_MODE_VARIABLE));
        }

        return $database;
    }

    /** @param array<string, mixed> $attributes */
    protected function createTenantOwner(array $attributes = []): User
    {
        $tenant = Tenant::factory()->create();
        $role = Role::query()
            ->whereNull('tenant_id')
            ->where('slug', 'tenant-owner')
            ->first()
            ?? Role::query()->forceCreate([
                'tenant_id' => null,
                'name' => 'Administrateur de l’entreprise',
                'slug' => 'tenant-owner',
                'is_system' => true,
                'is_active' => true,
            ]);

        return User::factory()->create([
            'tenant_id' => $tenant->id,
            'agency_id' => null,
            'role_id' => $role->id,
            ...$attributes,
        ]);
    }
}
