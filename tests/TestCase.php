<?php

namespace Tests;

use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Testing\TestDatabaseGuard;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    public function createApplication(): Application
    {
        $app = parent::createApplication();
        TestDatabaseGuard::assertSafe($app);

        return $app;
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
