<?php

namespace Tests;

use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Testing\TestDatabaseGuard;
use Illuminate\Contracts\Auth\Authenticatable;
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

    public function actingAs(Authenticatable $user, $guard = null)
    {
        // Switching actors in a test represents a different browser login. Do not
        // carry the previous actor's account/MFA proof into that synthetic login.
        $proof = $this->app['session.store']->get('account_session');
        if (is_array($proof) && ($proof['user_id'] ?? null) !== $user->getAuthIdentifier()) {
            $this->app['session.store']->invalidate();
        }

        return parent::actingAs($user, $guard);
    }

    public function json($method, $uri, array $data = [], array $headers = [], $options = 0)
    {
        // The application accepts JSON objects. Laravel otherwise encodes the
        // helper's empty default as [], including getJson requests with no fields.
        return parent::json($method, $uri, $data, $headers, $data === [] ? $options | JSON_FORCE_OBJECT : $options);
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
