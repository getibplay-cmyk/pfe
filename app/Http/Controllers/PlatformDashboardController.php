<?php

namespace App\Http\Controllers;

use App\Enums\TenantStatus;
use App\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class PlatformDashboardController extends Controller
{
    public function __invoke(): View
    {
        $ownerRoleId = DB::table('roles')->where('slug', 'tenant-owner')->whereNull('tenant_id')->value('id');
        $alerts = Tenant::query()
            ->where('status', TenantStatus::Active->value)
            ->orderBy('name')
            ->get()
            ->map(function (Tenant $tenant) use ($ownerRoleId): array {
                $hasOwner = DB::table('users')->where('tenant_id', $tenant->id)->where('role_id', $ownerRoleId)->where('is_active', true)->exists();
                $hasAgency = DB::table('agencies')->where('tenant_id', $tenant->id)->where('is_active', true)->whereNull('deleted_at')->exists();

                return ['tenant' => $tenant, 'missing_owner' => ! $hasOwner, 'missing_agency' => ! $hasAgency];
            })
            ->filter(fn (array $alert): bool => $alert['missing_owner'] || $alert['missing_agency']);

        return view('platform.dashboard', [
            'metrics' => [
                'Tenants' => Tenant::query()->count(),
                'Tenants actifs' => Tenant::query()->where('status', TenantStatus::Active->value)->count(),
                'Tenants suspendus' => Tenant::query()->where('status', TenantStatus::Suspended->value)->count(),
                'Agences' => DB::table('agencies')->whereNull('deleted_at')->count(),
                'Utilisateurs actifs' => DB::table('users')->whereNotNull('tenant_id')->where('is_active', true)->count(),
            ],
            'latestTenants' => Tenant::query()->latest()->limit(8)->get(),
            'alerts' => $alerts,
        ]);
    }
}
