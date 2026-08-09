<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('permissions')->insertOrIgnore([
            'slug' => 'prediction.demo.review',
            'name' => 'Revoir les contrats Intelligence synthétiques de démonstration',
            'group' => 'prediction',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('permissions')->where('slug', 'prediction.demo.review')->update([
            'name' => 'Revoir les contrats Intelligence synthétiques de démonstration',
            'group' => 'prediction',
            'updated_at' => now(),
        ]);

        $permissionId = DB::table('permissions')->where('slug', 'prediction.demo.review')->value('id');
        $roleIds = DB::table('roles')
            ->whereNull('tenant_id')
            ->whereIn('slug', ['tenant-owner', 'fleet-manager'])
            ->pluck('id');

        foreach ($roleIds as $roleId) {
            DB::table('permission_role')->insertOrIgnore([
                'role_id' => $roleId,
                'permission_id' => $permissionId,
            ]);
        }

        $deniedRoleIds = DB::table('roles')
            ->whereNull('tenant_id')
            ->whereIn('slug', ['agency-manager', 'rental-agent', 'accountant', 'viewer-auditor'])
            ->pluck('id');

        DB::table('permission_role')
            ->whereIn('role_id', $deniedRoleIds)
            ->where('permission_id', $permissionId)
            ->delete();
    }

    public function down(): void
    {
        // Conservative rollback: the permission and any later delegation remain.
    }
};
