<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agency_distances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('from_agency_id');
            $table->unsignedBigInteger('to_agency_id');
            $table->decimal('distance_km', 8, 3);
            $table->string('source_type', 32);
            $table->string('source_reference', 1000)->nullable();
            $table->unsignedBigInteger('verified_by_user_id');
            $table->timestampTz('verified_at');
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->unique(['tenant_id', 'id'], 'agency_distances_tenant_id_unique');
            $table->unique(
                ['tenant_id', 'from_agency_id', 'to_agency_id'],
                'agency_distances_direction_unique',
            );
            $table->index(
                ['tenant_id', 'active', 'from_agency_id', 'to_agency_id'],
                'agency_distances_active_direction_idx',
            );
            $table->foreign(
                ['tenant_id', 'from_agency_id'],
                'agency_distances_from_agency_fk',
            )->references(['tenant_id', 'id'])->on('agencies')->restrictOnDelete();
            $table->foreign(
                ['tenant_id', 'to_agency_id'],
                'agency_distances_to_agency_fk',
            )->references(['tenant_id', 'id'])->on('agencies')->restrictOnDelete();
            $table->foreign(
                ['tenant_id', 'verified_by_user_id'],
                'agency_distances_verifier_fk',
            )->references(['tenant_id', 'id'])->on('users')->restrictOnDelete();
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE agency_distances
                ADD CONSTRAINT agency_distances_distinct_agencies_check
                    CHECK (from_agency_id <> to_agency_id),
                ADD CONSTRAINT agency_distances_distance_check
                    CHECK (distance_km > 0 AND distance_km <= 10000),
                ADD CONSTRAINT agency_distances_source_check
                    CHECK (source_type = 'manual_verified');
        SQL);

        $permissions = [
            [
                'slug' => 'fleet.distance.view',
                'name' => 'Consulter les distances inter-agences',
                'group' => 'fleet',
            ],
            [
                'slug' => 'fleet.distance.manage',
                'name' => 'Gérer les distances inter-agences vérifiées',
                'group' => 'fleet',
            ],
        ];

        foreach ($permissions as $permission) {
            DB::table('permissions')->insertOrIgnore([
                ...$permission,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            DB::table('permissions')->where('slug', $permission['slug'])->update([
                'name' => $permission['name'],
                'group' => $permission['group'],
                'updated_at' => now(),
            ]);
        }

        $viewPermissionId = DB::table('permissions')
            ->where('slug', 'fleet.distance.view')
            ->value('id');
        $managePermissionId = DB::table('permissions')
            ->where('slug', 'fleet.distance.manage')
            ->value('id');

        $ownerRoleId = DB::table('roles')
            ->whereNull('tenant_id')
            ->where('slug', 'tenant-owner')
            ->value('id');
        $fleetManagerRoleId = DB::table('roles')
            ->whereNull('tenant_id')
            ->where('slug', 'fleet-manager')
            ->value('id');

        foreach (array_filter([$ownerRoleId, $fleetManagerRoleId]) as $roleId) {
            DB::table('permission_role')->insertOrIgnore([
                'role_id' => $roleId,
                'permission_id' => $viewPermissionId,
            ]);
        }
        if ($ownerRoleId !== null) {
            DB::table('permission_role')->insertOrIgnore([
                'role_id' => $ownerRoleId,
                'permission_id' => $managePermissionId,
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('agency_distances');

        $permissionIds = DB::table('permissions')
            ->whereIn('slug', ['fleet.distance.view', 'fleet.distance.manage'])
            ->pluck('id');
        DB::table('permission_role')->whereIn('permission_id', $permissionIds)->delete();
        DB::table('permissions')->whereIn('id', $permissionIds)->delete();
    }
};
