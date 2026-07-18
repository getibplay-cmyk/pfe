<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('insurance_companies', function (Blueprint $table): void {
            $table->timestampTz('deactivated_at')->nullable();
            $table->foreignId('deactivated_by')->nullable()->constrained('users')->nullOnDelete();
        });
        Schema::table('insurance_policies', function (Blueprint $table): void {
            $table->timestampTz('activated_at')->nullable();
            $table->foreignId('activated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('cancelled_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('cancellation_reason')->nullable();
            $table->unsignedBigInteger('renewed_from_id')->nullable();
            $table->index(['tenant_id', 'agency_id', 'renewed_from_id'], 'insurance_policies_renewed_from_idx');
        });
        Schema::table('insurance_policy_coverages', function (Blueprint $table): void {
            $table->softDeletes();
            $table->foreignId('archived_by')->nullable()->constrained('users')->nullOnDelete();
        });
        Schema::table('insurance_claims', function (Blueprint $table): void {
            $table->timestampTz('incident_at')->nullable()->after('status');
        });

        Schema::create('insurance_policy_status_histories', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('agency_id');
            $table->unsignedBigInteger('insurance_policy_id');
            $table->string('from_status')->nullable();
            $table->string('to_status');
            $table->text('reason')->nullable();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('changed_at')->useCurrent();
            $table->index(['tenant_id', 'agency_id', 'insurance_policy_id'], 'insurance_policy_histories_scope_idx');
            $table->foreign(['tenant_id', 'agency_id', 'insurance_policy_id'], 'insurance_policy_histories_policy_scope_fk')
                ->references(['tenant_id', 'agency_id', 'id'])->on('insurance_policies')->restrictOnDelete();
        });

        DB::unprepared(<<<'SQL'
            DO $$
            BEGIN
                IF EXISTS (SELECT 1 FROM insurance_policies WHERE ends_at < starts_at) THEN
                    RAISE EXCEPTION 'Lot 06F-C2 blocked: invalid insurance policy periods.' USING ERRCODE = '23514';
                END IF;
                IF EXISTS (
                    SELECT 1 FROM insurance_policies p JOIN insurance_companies c
                      ON c.tenant_id = p.tenant_id AND c.id = p.insurance_company_id
                    WHERE NOT c.is_active AND p.status IN ('draft', 'active')
                ) THEN
                    RAISE EXCEPTION 'Lot 06F-C2 blocked: inactive companies own open policies.' USING ERRCODE = '23514';
                END IF;
                IF EXISTS (
                    SELECT 1 FROM insurance_policies p
                    WHERE p.status = 'active'
                      AND (p.document_id IS NULL OR NOT EXISTS (
                          SELECT 1 FROM documents d JOIN document_versions v ON v.id = d.current_version_id
                          WHERE d.id = p.document_id AND d.tenant_id = p.tenant_id
                            AND d.agency_id = p.agency_id AND d.deleted_at IS NULL
                            AND v.tenant_id = d.tenant_id AND v.document_id = d.id
                      ))
                ) THEN
                    RAISE EXCEPTION 'Lot 06F-C2 blocked: active policies require a current private document.' USING ERRCODE = '23514';
                END IF;
                IF EXISTS (
                    SELECT 1 FROM insurance_policies p WHERE p.status = 'active'
                      AND NOT EXISTS (SELECT 1 FROM insurance_policy_coverages c WHERE c.tenant_id = p.tenant_id AND c.insurance_policy_id = p.id)
                ) THEN
                    RAISE EXCEPTION 'Lot 06F-C2 blocked: active policies require a coverage.' USING ERRCODE = '23514';
                END IF;
                IF EXISTS (
                    SELECT 1 FROM insurance_policies p1 JOIN insurance_policies p2
                      ON p2.tenant_id = p1.tenant_id AND p2.vehicle_id = p1.vehicle_id
                     AND p2.policy_type = p1.policy_type AND p2.id > p1.id
                    WHERE p1.status = 'active' AND p2.status = 'active'
                      AND daterange(p1.starts_at, p1.ends_at, '[]') && daterange(p2.starts_at, p2.ends_at, '[]')
                ) THEN
                    RAISE EXCEPTION 'Lot 06F-C2 blocked: overlapping active policies require review.' USING ERRCODE = '23514';
                END IF;
            END
            $$;

            ALTER TABLE insurance_companies
                ADD CONSTRAINT insurance_companies_deactivation_coherence_check
                CHECK ((is_active AND deactivated_at IS NULL AND deactivated_by IS NULL)
                    OR (NOT is_active AND deactivated_at IS NOT NULL AND deactivated_by IS NOT NULL));

            ALTER TABLE insurance_policies
                ADD CONSTRAINT insurance_policies_activation_pair_check
                CHECK ((activated_at IS NULL) = (activated_by IS NULL)),
                ADD CONSTRAINT insurance_policies_cancellation_fields_check
                CHECK ((status = 'cancelled' AND cancelled_at IS NOT NULL AND cancelled_by IS NOT NULL AND length(trim(cancellation_reason)) > 0)
                    OR (status <> 'cancelled' AND cancelled_at IS NULL AND cancelled_by IS NULL AND cancellation_reason IS NULL)),
                ADD CONSTRAINT insurance_policies_draft_cycle_check
                CHECK (status <> 'draft' OR activated_at IS NULL),
                ADD CONSTRAINT insurance_policies_renewed_from_not_self_check
                CHECK (renewed_from_id IS NULL OR renewed_from_id <> id);

            ALTER TABLE insurance_policy_status_histories
                ADD CONSTRAINT insurance_policy_histories_from_status_check CHECK (from_status IS NULL OR from_status IN ('draft','active','expired','cancelled')),
                ADD CONSTRAINT insurance_policy_histories_to_status_check CHECK (to_status IN ('draft','active','expired','cancelled'));

            ALTER TABLE insurance_claims
                ADD CONSTRAINT insurance_claims_incident_reported_check CHECK (incident_at IS NULL OR incident_at <= reported_at);

            ALTER TABLE documents ADD CONSTRAINT documents_insurance_scope_unique UNIQUE (tenant_id, agency_id, id);
            ALTER TABLE insurance_policies DROP CONSTRAINT insurance_policies_document_id_foreign;
            ALTER TABLE insurance_policies
                ADD CONSTRAINT insurance_policies_document_scope_fk
                FOREIGN KEY (tenant_id, agency_id, document_id)
                REFERENCES documents (tenant_id, agency_id, id) ON DELETE RESTRICT;
            ALTER TABLE insurance_policies
                ADD CONSTRAINT insurance_policies_renewed_from_scope_fk
                FOREIGN KEY (tenant_id, agency_id, renewed_from_id)
                REFERENCES insurance_policies (tenant_id, agency_id, id) ON DELETE RESTRICT;

            INSERT INTO insurance_policy_status_histories
                (tenant_id, agency_id, insurance_policy_id, from_status, to_status, reason, actor_id, changed_at)
            SELECT tenant_id, agency_id, id, NULL, status, 'Reprise de l''état existant au Lot 06F-C2', NULL, created_at
            FROM insurance_policies;

            ALTER TABLE insurance_policies
                ADD CONSTRAINT insurance_policies_no_active_overlap_excl
                EXCLUDE USING gist (
                    tenant_id WITH =,
                    vehicle_id WITH =,
                    policy_type WITH =,
                    daterange(starts_at, ends_at, '[]') WITH &&
                ) WHERE (status = 'active');

            CREATE OR REPLACE FUNCTION rentfleet_protect_insurance_company() RETURNS trigger AS $$
            DECLARE expected text;
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    RAISE EXCEPTION 'Insurance companies cannot be physically deleted.' USING ERRCODE = '23514';
                END IF;
                IF NEW.tenant_id IS DISTINCT FROM OLD.tenant_id THEN
                    RAISE EXCEPTION 'Insurance company tenant is immutable.' USING ERRCODE = '23514';
                END IF;
                IF NEW.is_active IS DISTINCT FROM OLD.is_active THEN
                    expected := CASE WHEN OLD.is_active THEN 'active->inactive' ELSE 'inactive->active' END;
                    IF COALESCE(current_setting('rentfleet.insurance_company_transition', true), '') <> expected THEN
                        RAISE EXCEPTION 'Insurance company state changes require a domain action.' USING ERRCODE = '23514';
                    END IF;
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;
            CREATE TRIGGER insurance_companies_lifecycle
            BEFORE UPDATE OR DELETE ON insurance_companies
            FOR EACH ROW EXECUTE FUNCTION rentfleet_protect_insurance_company();

            CREATE OR REPLACE FUNCTION rentfleet_protect_insurance_policy() RETURNS trigger AS $$
            DECLARE expected text;
            BEGIN
                IF TG_OP = 'INSERT' THEN
                    IF NEW.status <> 'draft' OR NEW.activated_at IS NOT NULL OR NEW.cancelled_at IS NOT NULL THEN
                        RAISE EXCEPTION 'Insurance policies must start as draft.' USING ERRCODE = '23514';
                    END IF;
                    RETURN NEW;
                END IF;
                IF TG_OP = 'DELETE' THEN
                    RAISE EXCEPTION 'Insurance policies cannot be physically deleted.' USING ERRCODE = '23514';
                END IF;
                IF OLD.status IN ('expired', 'cancelled') THEN
                    RAISE EXCEPTION 'Terminal insurance policies are immutable.' USING ERRCODE = '23514';
                END IF;
                IF NEW.tenant_id IS DISTINCT FROM OLD.tenant_id OR NEW.agency_id IS DISTINCT FROM OLD.agency_id THEN
                    RAISE EXCEPTION 'Insurance policy tenant and agency are immutable.' USING ERRCODE = '23514';
                END IF;
                IF NEW.status IS DISTINCT FROM OLD.status THEN
                    expected := OLD.status || '->' || NEW.status;
                    IF expected NOT IN ('draft->active','draft->cancelled','active->expired','active->cancelled')
                       OR COALESCE(current_setting('rentfleet.insurance_policy_transition', true), '') <> expected THEN
                        RAISE EXCEPTION 'Insurance policy status transitions require a domain action.' USING ERRCODE = '23514';
                    END IF;
                    IF expected = 'draft->active'
                       AND (to_jsonb(NEW) - ARRAY['status','activated_at','activated_by','document_id','updated_at'])
                           IS DISTINCT FROM (to_jsonb(OLD) - ARRAY['status','activated_at','activated_by','document_id','updated_at']) THEN
                        RAISE EXCEPTION 'Unexpected fields changed during policy activation.' USING ERRCODE = '23514';
                    ELSIF expected IN ('draft->cancelled','active->cancelled')
                       AND (to_jsonb(NEW) - ARRAY['status','cancelled_at','cancelled_by','cancellation_reason','updated_at'])
                           IS DISTINCT FROM (to_jsonb(OLD) - ARRAY['status','cancelled_at','cancelled_by','cancellation_reason','updated_at']) THEN
                        RAISE EXCEPTION 'Unexpected fields changed during policy cancellation.' USING ERRCODE = '23514';
                    ELSIF expected = 'active->expired'
                       AND (to_jsonb(NEW) - ARRAY['status','updated_at'])
                           IS DISTINCT FROM (to_jsonb(OLD) - ARRAY['status','updated_at']) THEN
                        RAISE EXCEPTION 'Unexpected fields changed during policy expiration.' USING ERRCODE = '23514';
                    END IF;
                ELSIF OLD.status = 'active'
                   AND (to_jsonb(NEW) - ARRAY['updated_at']) IS DISTINCT FROM (to_jsonb(OLD) - ARRAY['updated_at']) THEN
                    RAISE EXCEPTION 'Active insurance policy business fields are immutable.' USING ERRCODE = '23514';
                ELSIF OLD.status = 'draft'
                   AND (NEW.activated_at IS DISTINCT FROM OLD.activated_at
                     OR NEW.activated_by IS DISTINCT FROM OLD.activated_by
                     OR NEW.cancelled_at IS DISTINCT FROM OLD.cancelled_at
                     OR NEW.cancelled_by IS DISTINCT FROM OLD.cancelled_by
                     OR NEW.cancellation_reason IS DISTINCT FROM OLD.cancellation_reason) THEN
                    RAISE EXCEPTION 'Protected policy cycle fields cannot be edited.' USING ERRCODE = '23514';
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;
            CREATE TRIGGER insurance_policies_cycle_immutability
            BEFORE INSERT OR UPDATE OR DELETE ON insurance_policies
            FOR EACH ROW EXECUTE FUNCTION rentfleet_protect_insurance_policy();

            CREATE OR REPLACE FUNCTION rentfleet_immutable_insurance_policy_history() RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION 'Insurance policy histories are append-only.' USING ERRCODE = '23514';
            END;
            $$ LANGUAGE plpgsql;
            CREATE TRIGGER insurance_policy_histories_append_only
            BEFORE UPDATE OR DELETE ON insurance_policy_status_histories
            FOR EACH ROW EXECUTE FUNCTION rentfleet_immutable_insurance_policy_history();

            CREATE OR REPLACE FUNCTION rentfleet_protect_insurance_coverage() RETURNS trigger AS $$
            DECLARE policy_status text;
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    RAISE EXCEPTION 'Insurance coverages cannot be physically deleted.' USING ERRCODE = '23514';
                END IF;
                SELECT status INTO policy_status FROM insurance_policies
                WHERE tenant_id = NEW.tenant_id AND id = NEW.insurance_policy_id;
                IF policy_status IS DISTINCT FROM 'draft' THEN
                    RAISE EXCEPTION 'Insurance coverages are mutable only while policy is draft.' USING ERRCODE = '23514';
                END IF;
                IF TG_OP = 'UPDATE' AND (NEW.tenant_id IS DISTINCT FROM OLD.tenant_id OR NEW.insurance_policy_id IS DISTINCT FROM OLD.insurance_policy_id) THEN
                    RAISE EXCEPTION 'Insurance coverage scope is immutable.' USING ERRCODE = '23514';
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;
            CREATE TRIGGER insurance_coverages_draft_only
            BEFORE INSERT OR UPDATE OR DELETE ON insurance_policy_coverages
            FOR EACH ROW EXECUTE FUNCTION rentfleet_protect_insurance_coverage();

            CREATE OR REPLACE FUNCTION rentfleet_validate_insurance_claim_incident() RETURNS trigger AS $$
            DECLARE policy_row insurance_policies%ROWTYPE;
            BEGIN
                IF TG_OP = 'INSERT' AND NEW.incident_at IS NULL THEN
                    RAISE EXCEPTION 'New insurance claims require an incident date.' USING ERRCODE = '23514';
                END IF;
                IF TG_OP = 'UPDATE' AND OLD.incident_at IS NOT NULL AND NEW.incident_at IS DISTINCT FROM OLD.incident_at THEN
                    RAISE EXCEPTION 'Insurance claim incident date is immutable.' USING ERRCODE = '23514';
                END IF;
                IF NEW.incident_at IS NULL THEN
                    RETURN NEW;
                END IF;
                SELECT * INTO policy_row FROM insurance_policies
                WHERE tenant_id = NEW.tenant_id AND agency_id = NEW.agency_id AND id = NEW.insurance_policy_id;
                IF NOT FOUND OR policy_row.status = 'draft'
                   OR NEW.incident_at::date < policy_row.starts_at OR NEW.incident_at::date > policy_row.ends_at
                   OR NEW.incident_at > NEW.reported_at
                   OR (policy_row.status = 'cancelled' AND NEW.incident_at > policy_row.cancelled_at) THEN
                    RAISE EXCEPTION 'Insurance claim incident is incompatible with policy coverage.' USING ERRCODE = '23514';
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;
            CREATE TRIGGER insurance_claims_incident_integrity
            BEFORE INSERT OR UPDATE ON insurance_claims
            FOR EACH ROW EXECUTE FUNCTION rentfleet_validate_insurance_claim_incident();
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS insurance_claims_incident_integrity ON insurance_claims;
            DROP FUNCTION IF EXISTS rentfleet_validate_insurance_claim_incident();
            DROP TRIGGER IF EXISTS insurance_coverages_draft_only ON insurance_policy_coverages;
            DROP FUNCTION IF EXISTS rentfleet_protect_insurance_coverage();
            DROP TRIGGER IF EXISTS insurance_policy_histories_append_only ON insurance_policy_status_histories;
            DROP FUNCTION IF EXISTS rentfleet_immutable_insurance_policy_history();
            DROP TRIGGER IF EXISTS insurance_policies_cycle_immutability ON insurance_policies;
            DROP FUNCTION IF EXISTS rentfleet_protect_insurance_policy();
            DROP TRIGGER IF EXISTS insurance_companies_lifecycle ON insurance_companies;
            DROP FUNCTION IF EXISTS rentfleet_protect_insurance_company();
            ALTER TABLE insurance_policies DROP CONSTRAINT IF EXISTS insurance_policies_no_active_overlap_excl;
            ALTER TABLE insurance_policies DROP CONSTRAINT IF EXISTS insurance_policies_renewed_from_scope_fk;
            ALTER TABLE insurance_policies DROP CONSTRAINT IF EXISTS insurance_policies_document_scope_fk;
            ALTER TABLE insurance_policies ADD CONSTRAINT insurance_policies_document_id_foreign FOREIGN KEY (document_id) REFERENCES documents (id) ON DELETE SET NULL;
            ALTER TABLE documents DROP CONSTRAINT IF EXISTS documents_insurance_scope_unique;
            ALTER TABLE insurance_claims DROP CONSTRAINT IF EXISTS insurance_claims_incident_reported_check;
            ALTER TABLE insurance_policies DROP CONSTRAINT IF EXISTS insurance_policies_renewed_from_not_self_check;
            ALTER TABLE insurance_policies DROP CONSTRAINT IF EXISTS insurance_policies_draft_cycle_check;
            ALTER TABLE insurance_policies DROP CONSTRAINT IF EXISTS insurance_policies_cancellation_fields_check;
            ALTER TABLE insurance_policies DROP CONSTRAINT IF EXISTS insurance_policies_activation_pair_check;
            ALTER TABLE insurance_companies DROP CONSTRAINT IF EXISTS insurance_companies_deactivation_coherence_check;
        SQL);

        Schema::dropIfExists('insurance_policy_status_histories');
        Schema::table('insurance_claims', fn (Blueprint $table) => $table->dropColumn('incident_at'));
        Schema::table('insurance_policy_coverages', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('archived_by');
            $table->dropSoftDeletes();
        });
        Schema::table('insurance_policies', function (Blueprint $table): void {
            $table->dropIndex('insurance_policies_renewed_from_idx');
            $table->dropColumn('renewed_from_id');
            $table->dropConstrainedForeignId('cancelled_by');
            $table->dropColumn(['cancelled_at', 'cancellation_reason']);
            $table->dropConstrainedForeignId('activated_by');
            $table->dropColumn('activated_at');
        });
        Schema::table('insurance_companies', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('deactivated_by');
            $table->dropColumn('deactivated_at');
        });
    }
};
