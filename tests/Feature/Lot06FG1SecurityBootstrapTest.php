<?php

namespace Tests\Feature;

use App\Enums\TenantStatus;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

class Lot06FG1SecurityBootstrapTest extends TestCase
{
    use RefreshDatabase;

    public function test_first_platform_admin_is_bootstrapped_transactionally_without_a_seeder(): void
    {
        $password = Str::password(24, true, true, true, false);

        $this->bootstrapPlatformAdmin($password)->assertSuccessful();

        $role = Role::query()->whereNull('tenant_id')->where('slug', 'platform-admin')->firstOrFail();
        $user = User::query()->where('email', 'platform.initial@rentfleet.test')->firstOrFail();

        $this->assertTrue($role->is_system);
        $this->assertTrue($role->is_active);
        $this->assertNull($user->tenant_id);
        $this->assertNull($user->agency_id);
        $this->assertSame($role->id, $user->role_id);
        $this->assertTrue($user->is_platform_admin);
        $this->assertTrue($user->is_active);
        $this->assertNotNull($user->email_verified_at);
        $this->assertFalse($user->must_change_password);
        $this->assertSame('bcrypt', Hash::info($user->getAuthPassword())['algoName']);
        $this->assertTrue(Hash::check($password, $user->getAuthPassword()));

        $audit = DB::table('audit_logs')
            ->where('action', 'platform.admin.bootstrapped')
            ->where('auditable_id', $user->id)
            ->first();
        $this->assertNotNull($audit);
        $this->assertStringNotContainsString($password, json_encode($audit, JSON_THROW_ON_ERROR));
        $this->assertStringNotContainsString($user->getAuthPassword(), json_encode($audit, JSON_THROW_ON_ERROR));
    }

    public function test_second_bootstrap_is_refused_without_explicit_authorization(): void
    {
        $this->bootstrapPlatformAdmin(Str::password(24, true, true, true, false))
            ->assertSuccessful();

        $this->artisan('rentfleet:bootstrap-platform-admin')
            ->expectsOutputToContain('existe déjà')
            ->assertFailed();

        $this->assertSame(1, User::query()->where('is_platform_admin', true)->count());
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'platform.admin.bootstrapped')->count());
    }

    public function test_explicit_additional_bootstrap_requires_confirmation(): void
    {
        $this->bootstrapPlatformAdmin(Str::password(24, true, true, true, false))
            ->assertSuccessful();

        $this->artisan('rentfleet:bootstrap-platform-admin', ['--allow-additional' => true])
            ->expectsConfirmation(
                'Un administrateur existe déjà. Confirmez-vous la création explicite d’un administrateur supplémentaire ?',
                'no',
            )
            ->assertFailed();

        $this->assertSame(1, User::query()->where('is_platform_admin', true)->count());
    }

    public function test_incompatible_password_hash_returns_generic_validation_and_never_500(): void
    {
        $submittedPassword = Str::password(20, true, true, true, false);
        $storedValue = Str::random(60);
        $user = User::factory()->create([
            'email' => 'hash.incompatible@rentfleet.test',
            'is_platform_admin' => true,
        ]);
        DB::table('users')->where('id', $user->id)->update(['password' => $storedValue]);
        Log::spy();

        $response = $this->from('/login')->post('/login', [
            'email' => $user->email,
            'password' => $submittedPassword,
        ]);

        $response->assertRedirect('/login')->assertSessionHasErrors([
            'email' => trans('auth.failed'),
        ]);
        $this->assertGuest();
        Log::shouldHaveReceived('warning')->once()->withArgs(
            function (string $message, array $context) use ($submittedPassword, $storedValue, $user): bool {
                $encoded = json_encode([$message, $context], JSON_THROW_ON_ERROR);

                return $context['event'] === 'auth.password_hash_incompatible'
                    && $context['user_id'] === $user->id
                    && ! str_contains($encoded, $submittedPassword)
                    && ! str_contains($encoded, $storedValue);
            }
        );
    }

    public function test_unknown_user_and_incompatible_hash_have_the_same_observable_error(): void
    {
        $password = Str::password(20, true, true, true, false);
        $user = User::factory()->create([
            'email' => 'known.incompatible@rentfleet.test',
            'is_platform_admin' => true,
        ]);
        DB::table('users')->where('id', $user->id)->update(['password' => Str::random(60)]);

        $known = $this->from('/login')->post('/login', [
            'email' => $user->email,
            'password' => $password,
        ]);
        $knownError = session('errors')->get('email');
        $this->flushSession();

        $unknown = $this->from('/login')->post('/login', [
            'email' => 'absent@rentfleet.test',
            'password' => $password,
        ]);
        $unknownError = session('errors')->get('email');

        $this->assertSame($known->getStatusCode(), $unknown->getStatusCode());
        $this->assertSame($known->headers->get('Location'), $unknown->headers->get('Location'));
        $this->assertSame($knownError, $unknownError);
    }

    public function test_rate_limiting_still_applies_to_incompatible_hashes(): void
    {
        $password = Str::password(20, true, true, true, false);
        $user = User::factory()->create([
            'email' => 'limited.incompatible@rentfleet.test',
            'is_platform_admin' => true,
        ]);
        DB::table('users')->where('id', $user->id)->update(['password' => Str::random(60)]);
        RateLimiter::clear(Str::transliterate(Str::lower($user->email).'|127.0.0.1'));

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->post('/login', ['email' => $user->email, 'password' => $password]);
        }

        $response = $this->post('/login', ['email' => $user->email, 'password' => $password]);
        $response->assertSessionHasErrors('email');
        $this->assertStringContainsString(
            'Trop de tentatives',
            session('errors')->get('email')[0],
        );
    }

    public function test_hash_audit_counts_without_exposing_values_or_modifying_accounts(): void
    {
        $valid = User::factory()->create(['password' => Hash::make(Str::random(24))]);
        $invalidValue = Str::random(60);
        $invalid = User::factory()->create();
        DB::table('users')->where('id', $invalid->id)->update(['password' => $invalidValue]);

        $this->artisan('rentfleet:audit-password-hashes')
            ->expectsOutputToContain('Pilote attendu : bcrypt')
            ->expectsOutputToContain('Comptes compatibles : 1')
            ->expectsOutputToContain('Comptes incompatibles : 1')
            ->expectsOutputToContain('Comptes contrôlés : 2')
            ->doesntExpectOutput($invalidValue)
            ->doesntExpectOutput($valid->getAuthPassword())
            ->assertFailed();

        $this->assertSame($invalidValue, DB::table('users')->where('id', $invalid->id)->value('password'));
    }

    public function test_administrative_reset_is_scoped_strong_hashed_and_audited(): void
    {
        $tenant = Tenant::create([
            'name' => 'Tenant G1',
            'slug' => 'tenant-g1',
            'status' => TenantStatus::Active,
        ]);
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        $previousHash = $user->getAuthPassword();
        $password = Str::password(24, true, true, true, false);

        $this->artisan('rentfleet:reset-user-password', [
            'email' => $user->email,
            '--tenant' => $tenant->slug,
        ])
            ->expectsQuestion('Nouveau mot de passe', $password)
            ->expectsQuestion('Confirmation du nouveau mot de passe', $password)
            ->assertSuccessful();

        $user->refresh();
        $this->assertNotSame($previousHash, $user->getAuthPassword());
        $this->assertTrue(Hash::check($password, $user->getAuthPassword()));
        $this->assertTrue($user->must_change_password);

        $audit = DB::table('audit_logs')
            ->where('action', 'user.password_reset.administrative')
            ->where('auditable_id', $user->id)
            ->first();
        $this->assertNotNull($audit);
        $this->assertStringNotContainsString($password, json_encode($audit, JSON_THROW_ON_ERROR));
        $this->assertStringNotContainsString($user->getAuthPassword(), json_encode($audit, JSON_THROW_ON_ERROR));
    }

    public function test_administrative_reset_refuses_a_different_tenant_scope(): void
    {
        $tenant = Tenant::create([
            'name' => 'Tenant autorisé',
            'slug' => 'tenant-authorise',
            'status' => TenantStatus::Active,
        ]);
        $other = Tenant::create([
            'name' => 'Tenant différent',
            'slug' => 'tenant-different',
            'status' => TenantStatus::Active,
        ]);
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        $before = $user->getAuthPassword();

        $this->artisan('rentfleet:reset-user-password', [
            'email' => $user->email,
            '--tenant' => $other->slug,
        ])->assertFailed();

        $this->assertSame($before, $user->refresh()->getAuthPassword());
        $this->assertDatabaseMissing('audit_logs', [
            'action' => 'user.password_reset.administrative',
            'auditable_id' => $user->id,
        ]);
    }

    public function test_destructive_console_event_stops_before_command_execution_on_unsafe_database(): void
    {
        $this->assertSame('rentfleet_test', config('database.connections.pgsql.database'));
        config(['database.connections.pgsql.database' => 'rentfleet']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Exécution destructive refusée');

        event(new CommandStarting(
            'migrate:fresh',
            new ArrayInput([]),
            new BufferedOutput,
        ));
    }

    public function test_demo_password_examples_and_documentation_have_no_fixed_value(): void
    {
        foreach (['.env.example', '.env.production.example'] as $file) {
            $source = file_get_contents(base_path($file));
            $this->assertMatchesRegularExpression('/^DEMO_PASSWORD=\s*$/m', $source, $file);
        }

        foreach ([
            base_path('README.md'),
            base_path('docs/demo/runbook.md'),
            base_path('docs/deployment/production-checklist.md'),
        ] as $path) {
            $source = file_get_contents($path);
            $this->assertDoesNotMatchRegularExpression('/DEMO_PASSWORD\s*=\s*\S+/', $source, $path);
        }
    }

    private function bootstrapPlatformAdmin(string $password)
    {
        return $this->artisan('rentfleet:bootstrap-platform-admin')
            ->expectsQuestion('Nom de l’administrateur', 'Administrateur Initial')
            ->expectsQuestion('Adresse e-mail', 'platform.initial@rentfleet.test')
            ->expectsQuestion('Mot de passe', $password)
            ->expectsQuestion('Confirmation du mot de passe', $password);
    }
}
