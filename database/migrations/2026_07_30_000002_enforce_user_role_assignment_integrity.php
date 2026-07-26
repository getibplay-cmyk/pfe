<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $checks = [
            'rôle personnalisé affecté hors de son entreprise' => <<<'SQL'
                SELECT count(*)
                FROM users u
                JOIN roles r ON r.id = u.role_id
                WHERE r.tenant_id IS NOT NULL
                  AND u.tenant_id IS DISTINCT FROM r.tenant_id
                SQL,
            'rôle plateforme affecté à un utilisateur d’entreprise' => <<<'SQL'
                SELECT count(*)
                FROM users u
                JOIN roles r ON r.id = u.role_id
                WHERE r.slug = 'platform-admin'
                  AND (u.tenant_id IS NOT NULL OR u.agency_id IS NOT NULL OR u.is_platform_admin = false)
                SQL,
            'rôle d’entreprise affecté à un administrateur plateforme' => <<<'SQL'
                SELECT count(*)
                FROM users u
                JOIN roles r ON r.id = u.role_id
                WHERE u.is_platform_admin = true
                  AND r.slug <> 'platform-admin'
                SQL,
            'rôle inactif encore affecté' => <<<'SQL'
                SELECT count(*)
                FROM users u
                JOIN roles r ON r.id = u.role_id
                WHERE r.is_active = false
                SQL,
            'utilisateur d’entreprise sans rôle' => <<<'SQL'
                SELECT count(*)
                FROM users
                WHERE is_platform_admin = false
                  AND tenant_id IS NOT NULL
                  AND role_id IS NULL
                SQL,
            'administrateur d’entreprise rattaché à une agence' => <<<'SQL'
                SELECT count(*)
                FROM users u
                JOIN roles r ON r.id = u.role_id
                WHERE r.slug = 'tenant-owner'
                  AND u.agency_id IS NOT NULL
                SQL,
            'rôle d’agence sans agence' => <<<'SQL'
                SELECT count(*)
                FROM users u
                JOIN roles r ON r.id = u.role_id
                WHERE u.is_platform_admin = false
                  AND r.slug NOT IN ('tenant-owner', 'platform-admin')
                  AND u.agency_id IS NULL
                SQL,
            'administrateur plateforme dans un périmètre d’entreprise' => <<<'SQL'
                SELECT count(*)
                FROM users
                WHERE is_platform_admin = true
                  AND (tenant_id IS NOT NULL OR agency_id IS NOT NULL)
                SQL,
            'délégation incohérente' => <<<'SQL'
                SELECT count(*)
                FROM role_agency_delegations d
                LEFT JOIN agencies a ON a.id = d.agency_id
                LEFT JOIN roles r ON r.id = d.role_id
                LEFT JOIN users u ON u.id = d.delegated_by
                WHERE a.id IS NULL
                   OR a.tenant_id <> d.tenant_id
                   OR a.deleted_at IS NOT NULL
                   OR r.id IS NULL
                   OR r.is_active = false
                   OR (r.tenant_id IS NOT NULL AND r.tenant_id <> d.tenant_id)
                   OR r.slug IN ('tenant-owner', 'platform-admin')
                   OR (u.id IS NOT NULL AND (u.tenant_id <> d.tenant_id OR u.is_active = false))
                SQL,
        ];

        $violations = [];

        foreach ($checks as $label => $sql) {
            $count = (int) DB::scalar($sql);

            if ($count > 0) {
                $violations[] = $label.': '.$count;
            }
        }

        if ($violations !== []) {
            throw new RuntimeException(
                "Intégrité utilisateurs/rôles non conforme. Aucune correction automatique n'a été appliquée. "
                .implode('; ', $violations)
            );
        }

        DB::statement('CREATE INDEX users_role_assignment_idx ON users (role_id, tenant_id, agency_id)');

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION enforce_user_role_assignment_integrity() RETURNS trigger
            LANGUAGE plpgsql AS $$
            DECLARE
                assigned_role roles%ROWTYPE;
            BEGIN
                IF NEW.is_platform_admin = true THEN
                    IF NEW.tenant_id IS NOT NULL OR NEW.agency_id IS NOT NULL THEN
                        RAISE EXCEPTION 'platform administrator cannot belong to a tenant or agency' USING ERRCODE = '23514';
                    END IF;

                    IF NEW.role_id IS NOT NULL THEN
                        SELECT * INTO assigned_role FROM roles WHERE id = NEW.role_id FOR KEY SHARE;

                        IF NOT FOUND OR assigned_role.is_active = false
                            OR assigned_role.tenant_id IS NOT NULL
                            OR assigned_role.slug <> 'platform-admin' THEN
                            RAISE EXCEPTION 'platform administrator role mismatch' USING ERRCODE = '23514';
                        END IF;
                    END IF;

                    RETURN NEW;
                END IF;

                IF NEW.tenant_id IS NULL AND NEW.agency_id IS NULL AND NEW.role_id IS NULL THEN
                    RETURN NEW;
                END IF;

                IF NEW.tenant_id IS NULL OR NEW.role_id IS NULL THEN
                    RAISE EXCEPTION 'tenant user requires a tenant and an active role' USING ERRCODE = '23514';
                END IF;

                SELECT * INTO assigned_role FROM roles WHERE id = NEW.role_id FOR KEY SHARE;

                IF NOT FOUND OR assigned_role.is_active = false THEN
                    RAISE EXCEPTION 'assigned role must be active' USING ERRCODE = '23514';
                END IF;

                IF assigned_role.slug = 'platform-admin'
                    OR (assigned_role.tenant_id IS NOT NULL AND assigned_role.tenant_id <> NEW.tenant_id) THEN
                    RAISE EXCEPTION 'assigned role tenant scope mismatch' USING ERRCODE = '23514';
                END IF;

                IF assigned_role.slug = 'tenant-owner' THEN
                    IF NEW.agency_id IS NOT NULL THEN
                        RAISE EXCEPTION 'tenant owner cannot belong to an agency' USING ERRCODE = '23514';
                    END IF;
                ELSE
                    IF NEW.agency_id IS NULL OR NOT EXISTS (
                        SELECT 1
                        FROM agencies
                        WHERE id = NEW.agency_id
                          AND tenant_id = NEW.tenant_id
                          AND is_active = true
                          AND deleted_at IS NULL
                    ) THEN
                        RAISE EXCEPTION 'tenant role requires an active agency in the same tenant' USING ERRCODE = '23514';
                    END IF;
                END IF;

                RETURN NEW;
            END;
            $$;

            CREATE TRIGGER users_role_assignment_integrity_guard
            BEFORE INSERT OR UPDATE OF tenant_id, agency_id, role_id, is_platform_admin ON users
            FOR EACH ROW EXECUTE FUNCTION enforce_user_role_assignment_integrity();

            CREATE OR REPLACE FUNCTION prevent_assigned_role_deactivation() RETURNS trigger
            LANGUAGE plpgsql AS $$
            BEGIN
                IF OLD.is_active = true AND NEW.is_active = false
                    AND (
                        EXISTS (SELECT 1 FROM users WHERE role_id = OLD.id)
                        OR EXISTS (SELECT 1 FROM role_agency_delegations WHERE role_id = OLD.id)
                    ) THEN
                    RAISE EXCEPTION 'assigned or delegated role cannot be deactivated' USING ERRCODE = '23514';
                END IF;

                RETURN NEW;
            END;
            $$;

            CREATE TRIGGER roles_active_assignment_guard
            BEFORE UPDATE OF is_active ON roles
            FOR EACH ROW EXECUTE FUNCTION prevent_assigned_role_deactivation();

            CREATE OR REPLACE FUNCTION enforce_role_delegation_scope() RETURNS trigger
            LANGUAGE plpgsql AS $$
            DECLARE
                delegated_role roles%ROWTYPE;
                actor_tenant_id bigint;
                actor_active boolean;
            BEGIN
                IF NOT EXISTS (
                    SELECT 1 FROM agencies
                    WHERE id = NEW.agency_id
                      AND tenant_id = NEW.tenant_id
                      AND is_active = true
                      AND deleted_at IS NULL
                ) THEN
                    RAISE EXCEPTION 'role delegation agency scope mismatch' USING ERRCODE = '23514';
                END IF;

                SELECT * INTO delegated_role FROM roles WHERE id = NEW.role_id FOR KEY SHARE;

                IF NOT FOUND OR delegated_role.is_active = false
                    OR (delegated_role.tenant_id IS NOT NULL AND delegated_role.tenant_id <> NEW.tenant_id)
                    OR delegated_role.slug IN ('tenant-owner', 'platform-admin') THEN
                    RAISE EXCEPTION 'role delegation role scope mismatch' USING ERRCODE = '23514';
                END IF;

                IF NEW.delegated_by IS NOT NULL THEN
                    SELECT tenant_id, is_active
                    INTO actor_tenant_id, actor_active
                    FROM users
                    WHERE id = NEW.delegated_by;

                    IF NOT FOUND OR actor_active = false OR actor_tenant_id <> NEW.tenant_id THEN
                        RAISE EXCEPTION 'role delegation actor scope mismatch' USING ERRCODE = '23514';
                    END IF;
                END IF;

                RETURN NEW;
            END;
            $$;

            DROP TRIGGER IF EXISTS role_agency_delegations_scope_guard ON role_agency_delegations;
            CREATE TRIGGER role_agency_delegations_scope_guard
            BEFORE INSERT OR UPDATE OF tenant_id, agency_id, role_id, delegated_by ON role_agency_delegations
            FOR EACH ROW EXECUTE FUNCTION enforce_role_delegation_scope();
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS role_agency_delegations_scope_guard ON role_agency_delegations;

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

            DROP TRIGGER IF EXISTS roles_active_assignment_guard ON roles;
            DROP FUNCTION IF EXISTS prevent_assigned_role_deactivation();
            DROP TRIGGER IF EXISTS users_role_assignment_integrity_guard ON users;
            DROP FUNCTION IF EXISTS enforce_user_role_assignment_integrity();
        SQL);

        DB::statement('DROP INDEX IF EXISTS users_role_assignment_idx');
    }
};
