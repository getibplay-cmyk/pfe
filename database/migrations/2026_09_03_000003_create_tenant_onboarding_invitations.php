<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_onboarding_invitations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('email');
            $table->char('secret_hash', 64);
            $table->foreignId('saas_plan_id')->constrained('saas_plans')->restrictOnDelete();
            $table->string('status', 20)->default('pending');
            $table->unsignedSmallInteger('trial_days');
            $table->timestampTz('expires_at');
            $table->timestampTz('accepted_at')->nullable();
            $table->timestampTz('revoked_at')->nullable();
            $table->foreignId('accepted_tenant_id')->nullable()->constrained('tenants')->restrictOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestampsTz();

            $table->index(['status', 'expires_at'], 'tenant_onboarding_invitations_status_idx');
            $table->index(['saas_plan_id', 'created_at'], 'tenant_onboarding_invitations_plan_idx');
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE tenant_onboarding_invitations
                ADD CONSTRAINT tenant_onboarding_invitations_email_check
                    CHECK (email = lower(btrim(email)) AND btrim(email) <> ''),
                ADD CONSTRAINT tenant_onboarding_invitations_secret_hash_check
                    CHECK (secret_hash ~ '^[a-f0-9]{64}$'),
                ADD CONSTRAINT tenant_onboarding_invitations_status_check
                    CHECK (status IN ('pending', 'accepted', 'revoked', 'expired')),
                ADD CONSTRAINT tenant_onboarding_invitations_trial_days_check
                    CHECK (trial_days BETWEEN 1 AND 90),
                ADD CONSTRAINT tenant_onboarding_invitations_expiry_check
                    CHECK (expires_at > created_at),
                ADD CONSTRAINT tenant_onboarding_invitations_terminal_dates_check CHECK (
                    (accepted_at IS NULL OR (accepted_at >= created_at AND accepted_at <= expires_at))
                    AND (revoked_at IS NULL OR revoked_at >= created_at)
                ),
                ADD CONSTRAINT tenant_onboarding_invitations_state_shape_check CHECK (
                    (status = 'pending' AND accepted_at IS NULL AND revoked_at IS NULL AND accepted_tenant_id IS NULL)
                    OR (status = 'accepted' AND accepted_at IS NOT NULL AND revoked_at IS NULL AND accepted_tenant_id IS NOT NULL)
                    OR (status = 'revoked' AND accepted_at IS NULL AND revoked_at IS NOT NULL AND accepted_tenant_id IS NULL)
                    OR (status = 'expired' AND accepted_at IS NULL AND revoked_at IS NULL AND accepted_tenant_id IS NULL)
                );

            CREATE UNIQUE INDEX tenant_onboarding_invitations_pending_email_unique
                ON tenant_onboarding_invitations (lower(email))
                WHERE status = 'pending';

            CREATE OR REPLACE FUNCTION belkhir_guard_tenant_onboarding_invitation() RETURNS trigger AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    RAISE EXCEPTION 'Tenant onboarding invitations cannot be deleted' USING ERRCODE = '23514';
                END IF;

                IF NEW.id IS DISTINCT FROM OLD.id
                   OR NEW.email IS DISTINCT FROM OLD.email
                   OR NEW.secret_hash IS DISTINCT FROM OLD.secret_hash
                   OR NEW.saas_plan_id IS DISTINCT FROM OLD.saas_plan_id
                   OR NEW.trial_days IS DISTINCT FROM OLD.trial_days
                   OR NEW.expires_at IS DISTINCT FROM OLD.expires_at
                   OR NEW.created_by IS DISTINCT FROM OLD.created_by
                   OR NEW.created_at IS DISTINCT FROM OLD.created_at THEN
                    RAISE EXCEPTION 'Tenant onboarding invitation identity is immutable' USING ERRCODE = '23514';
                END IF;

                IF OLD.status <> 'pending' THEN
                    RAISE EXCEPTION 'Terminal tenant onboarding invitations are immutable' USING ERRCODE = '23514';
                END IF;

                IF NEW.status IS DISTINCT FROM OLD.status
                   AND NEW.status NOT IN ('accepted', 'revoked', 'expired') THEN
                    RAISE EXCEPTION 'Invalid tenant onboarding invitation transition' USING ERRCODE = '23514';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER tenant_onboarding_invitations_guard
                BEFORE UPDATE OR DELETE ON tenant_onboarding_invitations
                FOR EACH ROW EXECUTE FUNCTION belkhir_guard_tenant_onboarding_invitation();
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS tenant_onboarding_invitations_guard ON tenant_onboarding_invitations;
            DROP FUNCTION IF EXISTS belkhir_guard_tenant_onboarding_invitation();
        SQL);

        Schema::dropIfExists('tenant_onboarding_invitations');
    }
};
