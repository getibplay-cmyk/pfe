<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        foreach ([
            'prediction.view' => 'Consulter les fonctions Intelligence',
            'prediction.export' => 'Exporter le dataset Intelligence anonymisé',
        ] as $slug => $name) {
            DB::table('permissions')->insertOrIgnore([
                'slug' => $slug,
                'name' => $name,
                'group' => 'prediction',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            DB::table('permissions')->where('slug', $slug)->update([
                'name' => $name,
                'group' => 'prediction',
                'updated_at' => now(),
            ]);
        }

        $permissionIds = DB::table('permissions')
            ->whereIn('slug', ['prediction.view', 'prediction.export'])
            ->pluck('id', 'slug');
        $roles = DB::table('roles')
            ->whereNull('tenant_id')
            ->whereIn('slug', ['tenant-owner', 'agency-manager', 'rental-agent', 'fleet-manager', 'accountant', 'viewer-auditor'])
            ->pluck('id', 'slug');

        foreach (['tenant-owner', 'agency-manager'] as $slug) {
            if (! $roles->has($slug)) {
                continue;
            }

            foreach (['prediction.view', 'prediction.export'] as $permission) {
                DB::table('permission_role')->insertOrIgnore([
                    'role_id' => $roles[$slug],
                    'permission_id' => $permissionIds[$permission],
                ]);
            }
        }

        foreach (['fleet-manager', 'viewer-auditor'] as $slug) {
            if (! $roles->has($slug)) {
                continue;
            }

            DB::table('permission_role')->insertOrIgnore([
                'role_id' => $roles[$slug],
                'permission_id' => $permissionIds['prediction.view'],
            ]);
        }

        $exportDeniedRoleIds = $roles->only(['rental-agent', 'fleet-manager', 'accountant', 'viewer-auditor'])->values();
        if ($exportDeniedRoleIds->isNotEmpty()) {
            DB::table('permission_role')
                ->whereIn('role_id', $exportDeniedRoleIds)
                ->where('permission_id', $permissionIds['prediction.export'])
                ->delete();
        }

        $viewDeniedRoleIds = $roles->only(['rental-agent', 'accountant'])->values();
        if ($viewDeniedRoleIds->isNotEmpty()) {
            DB::table('permission_role')
                ->whereIn('role_id', $viewDeniedRoleIds)
                ->where('permission_id', $permissionIds['prediction.view'])
                ->delete();
        }

        DB::statement("
            CREATE INDEX rental_contracts_intelligence_export_idx
            ON rental_contracts (tenant_id, agency_id, actual_return_at, id)
            WHERE deleted_at IS NULL AND status IN ('returned', 'closed')
        ");
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS rental_contracts_intelligence_export_idx');

        // Conservative rollback: permission rows and grants are retained because
        // they may have existed before this migration or been delegated later.
    }
};
