<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('roles', function (Blueprint $table) {
            $table->boolean('is_active')->default(true)->after('is_system');
            $table->foreignId('created_by')->nullable()->after('is_active')->constrained('users')->nullOnDelete();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['role_id']);
            $table->foreign('role_id')->references('id')->on('roles')->restrictOnDelete();
        });

        Schema::create('role_agency_delegations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('agency_id')->constrained()->cascadeOnDelete();
            $table->foreignId('role_id')->constrained()->restrictOnDelete();
            $table->foreignId('delegated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();

            $table->unique(['tenant_id', 'agency_id', 'role_id'], 'role_agency_delegations_scope_unique');
            $table->index(['tenant_id', 'role_id'], 'role_agency_delegations_role_idx');
        });

        DB::statement('CREATE UNIQUE INDEX roles_tenant_lower_name_unique ON roles (tenant_id, lower(name)) WHERE tenant_id IS NOT NULL');
        DB::statement('ALTER TABLE roles ADD CONSTRAINT roles_system_scope_check CHECK ((is_system = true AND tenant_id IS NULL) OR (is_system = false AND tenant_id IS NOT NULL))');

        foreach ([
            ['slug' => 'role.view', 'name' => 'Voir les rôles et permissions', 'group' => 'role'],
            ['slug' => 'role.manage', 'name' => 'Gérer les rôles personnalisés', 'group' => 'role'],
            ['slug' => 'role.delegate', 'name' => 'Déléguer les rôles aux agences', 'group' => 'role'],
        ] as $permission) {
            DB::table('permissions')->updateOrInsert(['slug' => $permission['slug']], [
                'name' => $permission['name'],
                'group' => $permission['group'],
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        DB::table('permissions')->where('slug', 'contract.create')->update(['name' => 'Créer un contrat']);
        DB::table('permissions')->where('slug', 'inspection.manage')->update(['name' => 'Gérer les inspections']);
        DB::table('permissions')->where('slug', 'damage.review')->update(['name' => 'Décider la responsabilité']);

        $ownerRoleIds = DB::table('roles')->whereNull('tenant_id')->where('slug', 'tenant-owner')->pluck('id');
        $governancePermissionIds = DB::table('permissions')->whereIn('slug', ['role.view', 'role.manage', 'role.delegate'])->pluck('id');
        foreach ($ownerRoleIds as $roleId) {
            foreach ($governancePermissionIds as $permissionId) {
                DB::table('permission_role')->insertOrIgnore(['role_id' => $roleId, 'permission_id' => $permissionId]);
            }
        }

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION protect_system_roles() RETURNS trigger
            LANGUAGE plpgsql AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    IF OLD.is_system = true THEN
                        RAISE EXCEPTION 'system roles are immutable' USING ERRCODE = '23514';
                    END IF;

                    RETURN OLD;
                END IF;

                IF OLD.is_system = true AND (
                    NEW.slug IS DISTINCT FROM OLD.slug
                    OR NEW.tenant_id IS DISTINCT FROM OLD.tenant_id
                    OR NEW.is_system IS DISTINCT FROM OLD.is_system
                    OR NEW.is_active IS DISTINCT FROM OLD.is_active
                ) THEN
                    RAISE EXCEPTION 'system roles are immutable' USING ERRCODE = '23514';
                END IF;

                RETURN NEW;
            END;
            $$;

            CREATE TRIGGER roles_system_protection_guard
            BEFORE UPDATE OR DELETE ON roles
            FOR EACH ROW EXECUTE FUNCTION protect_system_roles();

            CREATE OR REPLACE FUNCTION enforce_role_delegation_scope() RETURNS trigger
            LANGUAGE plpgsql AS $$
            DECLARE
                delegated_role_tenant_id bigint;
                delegated_role_slug text;
                delegated_role_active boolean;
            BEGIN
                IF NOT EXISTS (
                    SELECT 1 FROM agencies
                    WHERE id = NEW.agency_id AND tenant_id = NEW.tenant_id AND deleted_at IS NULL
                ) THEN
                    RAISE EXCEPTION 'role delegation agency scope mismatch' USING ERRCODE = '23514';
                END IF;

                SELECT tenant_id, slug, is_active
                INTO delegated_role_tenant_id, delegated_role_slug, delegated_role_active
                FROM roles WHERE id = NEW.role_id;

                IF NOT FOUND OR delegated_role_active = false
                    OR (delegated_role_tenant_id IS NOT NULL AND delegated_role_tenant_id <> NEW.tenant_id)
                    OR delegated_role_slug IN ('tenant-owner', 'platform-admin') THEN
                    RAISE EXCEPTION 'role delegation role scope mismatch' USING ERRCODE = '23514';
                END IF;

                RETURN NEW;
            END;
            $$;

            CREATE TRIGGER role_agency_delegations_scope_guard
            BEFORE INSERT OR UPDATE OF tenant_id, agency_id, role_id ON role_agency_delegations
            FOR EACH ROW EXECUTE FUNCTION enforce_role_delegation_scope();

            CREATE OR REPLACE FUNCTION prevent_custom_platform_permissions() RETURNS trigger
            LANGUAGE plpgsql AS $$
            BEGIN
                IF EXISTS (
                    SELECT 1
                    FROM roles r
                    JOIN permissions p ON p.id = NEW.permission_id
                    WHERE r.id = NEW.role_id
                      AND r.tenant_id IS NOT NULL
                      AND (p.group = 'platform' OR p.slug LIKE 'platform.%' OR p.slug IN ('role.manage', 'role.delegate'))
                ) THEN
                    RAISE EXCEPTION 'custom role cannot receive platform or governance permissions' USING ERRCODE = '23514';
                END IF;

                RETURN NEW;
            END;
            $$;

            CREATE TRIGGER permission_role_custom_scope_guard
            BEFORE INSERT OR UPDATE OF role_id, permission_id ON permission_role
            FOR EACH ROW EXECUTE FUNCTION prevent_custom_platform_permissions();
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS permission_role_custom_scope_guard ON permission_role;
            DROP FUNCTION IF EXISTS prevent_custom_platform_permissions();
            DROP TRIGGER IF EXISTS role_agency_delegations_scope_guard ON role_agency_delegations;
            DROP FUNCTION IF EXISTS enforce_role_delegation_scope();
            DROP TRIGGER IF EXISTS roles_system_protection_guard ON roles;
            DROP FUNCTION IF EXISTS protect_system_roles();
        SQL);

        Schema::dropIfExists('role_agency_delegations');
        DB::statement('DROP INDEX IF EXISTS roles_tenant_lower_name_unique');
        DB::statement('ALTER TABLE roles DROP CONSTRAINT IF EXISTS roles_system_scope_check');

        $permissionIds = DB::table('permissions')->whereIn('slug', ['role.view', 'role.manage', 'role.delegate'])->pluck('id');
        DB::table('permission_role')->whereIn('permission_id', $permissionIds)->delete();
        DB::table('permissions')->whereIn('id', $permissionIds)->delete();

        Schema::table('roles', function (Blueprint $table) {
            $table->dropForeign(['created_by']);
            $table->dropColumn(['is_active', 'created_by']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['role_id']);
            $table->foreign('role_id')->references('id')->on('roles')->nullOnDelete();
        });
    }
};
