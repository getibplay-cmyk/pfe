<?php

namespace App\Actions\Platform;

use App\Enums\TenantStatus;
use App\Models\Agency;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use App\Support\Auth\TemporaryPassword;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class ProvisionTenant
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly AuditRecorder $audit,
    ) {}

    /** @return array{tenant: Tenant, temporary_password: string} */
    public function handle(array $data, int $actorId): array
    {
        return DB::transaction(function () use ($data, $actorId): array {
            $ownerRole = Role::query()->whereNull('tenant_id')->where('slug', 'tenant-owner')->firstOrFail();
            $temporaryPassword = TemporaryPassword::generate();

            $tenant = Tenant::create([
                'name' => $data['name'],
                'slug' => $data['slug'],
                'legal_name' => $data['legal_name'] ?? null,
                'email' => $data['email'] ?? null,
                'phone' => $data['phone'] ?? null,
                'status' => TenantStatus::Active,
                'settings' => [
                    'address' => $data['address'] ?? null,
                    'currency' => $data['currency'],
                    'timezone' => $data['timezone'],
                ],
            ]);

            $this->context->run($tenant, function () use ($tenant, $data, $ownerRole, $temporaryPassword): void {
                Agency::create([
                    'code' => $data['agency_code'],
                    'name' => $data['agency_name'],
                    'email' => $data['agency_email'] ?? null,
                    'phone' => $data['agency_phone'] ?? null,
                    'address' => $data['agency_address'] ?? null,
                    'is_active' => true,
                ]);

                User::forceCreate([
                    'tenant_id' => $tenant->id,
                    'agency_id' => null,
                    'role_id' => $ownerRole->id,
                    'name' => $data['owner_name'],
                    'email' => $data['owner_email'],
                    'email_verified_at' => now(),
                    'password' => Hash::make($temporaryPassword),
                    'is_active' => true,
                    'must_change_password' => true,
                    'is_platform_admin' => false,
                ]);
            });

            $this->audit->record('platform.tenant.provisioned', $tenant, [], [
                'status' => TenantStatus::Active->value,
                'initial_agency_code' => $data['agency_code'],
                'owner_email' => $data['owner_email'],
                'actor_id' => $actorId,
            ]);

            return ['tenant' => $tenant, 'temporary_password' => $temporaryPassword];
        });
    }
}
