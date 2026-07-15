<?php

namespace Database\Seeders;

use App\Enums\TenantStatus;
use App\Models\Agency;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\Concerns\PreventsDemoSeedingInProduction;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class DemoTenancySeeder extends Seeder
{
    use PreventsDemoSeedingInProduction;

    public function run(): void
    {
        $this->ensureDemoSeedingIsAllowed();

        $primary = Tenant::create([
            'name' => 'Atlas Location Démo',
            'slug' => 'atlas-location-demo',
            'legal_name' => 'Atlas Location Démo SARL',
            'email' => 'contact@atlas-demo.test',
            'status' => TenantStatus::Active,
        ]);
        $secondary = Tenant::create([
            'name' => 'Rif Mobilité Démo',
            'slug' => 'rif-mobilite-demo',
            'legal_name' => 'Rif Mobilité Démo SARL',
            'email' => 'contact@rif-demo.test',
            'status' => TenantStatus::Active,
        ]);

        $context = app(TenantContext::class);
        $primaryAgencies = $context->run($primary, fn () => collect([
            Agency::create(['code' => 'CASA', 'name' => 'Casablanca Centre', 'is_active' => true]),
            Agency::create(['code' => 'RABAT', 'name' => 'Rabat Agdal', 'is_active' => true]),
        ]));
        $secondaryAgency = $context->run($secondary, fn () => Agency::create([
            'code' => 'TANGER', 'name' => 'Tanger Centre', 'is_active' => true,
        ]));

        $password = Hash::make((string) (env('DEMO_PASSWORD') ?: Str::password(24)));
        $roles = Role::whereNull('tenant_id')->get()->keyBy('slug');

        foreach ($roles as $slug => $role) {
            User::forceCreate([
                'tenant_id' => $primary->id,
                'agency_id' => $slug === 'tenant-owner' ? null : $primaryAgencies->first()->id,
                'role_id' => $role->id,
                'name' => $role->name.' Démo',
                'email' => $slug.'@atlas-demo.test',
                'email_verified_at' => now(),
                'password' => $password,
                'is_active' => true,
            ]);
        }

        User::forceCreate([
            'tenant_id' => $secondary->id,
            'agency_id' => null,
            'role_id' => $roles['tenant-owner']->id,
            'name' => 'Tenant Owner Rif Démo',
            'email' => 'owner@rif-demo.test',
            'email_verified_at' => now(),
            'password' => $password,
            'is_active' => true,
        ]);

        User::forceCreate([
            'tenant_id' => $secondary->id,
            'agency_id' => $secondaryAgency->id,
            'role_id' => $roles['agency-manager']->id,
            'name' => 'Agency Manager Rif Démo',
            'email' => 'manager@rif-demo.test',
            'email_verified_at' => now(),
            'password' => $password,
            'is_active' => true,
        ]);

        User::forceCreate([
            'name' => 'Platform Admin Démo',
            'email' => 'platform@rentfleet.test',
            'email_verified_at' => now(),
            'password' => $password,
            'is_platform_admin' => true,
            'is_active' => true,
        ]);
    }
}
