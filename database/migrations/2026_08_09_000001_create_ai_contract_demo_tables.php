<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_advisory_records_demo', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('agency_id')->nullable()->constrained()->cascadeOnDelete();
            $table->uuid('external_record_id');
            $table->string('module_id', 64);
            $table->string('contract_version', 16);
            $table->string('source_kind', 32);
            $table->jsonb('payload');
            $table->char('fingerprint', 64);
            $table->string('validation_status', 24);
            $table->string('operational_effect', 32);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->unique(['tenant_id', 'id'], 'ai_advisory_demo_tenant_id_unique');
            $table->unique(['tenant_id', 'module_id', 'external_record_id'], 'ai_advisory_demo_external_unique');
            $table->index(['tenant_id', 'agency_id', 'created_at'], 'ai_advisory_demo_scope_date_idx');
            $table->foreign(['tenant_id', 'created_by'], 'ai_advisory_demo_creator_fk')
                ->references(['tenant_id', 'id'])->on('users')->restrictOnDelete();
        });

        Schema::create('ai_idempotency_keys_demo', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('ai_advisory_record_demo_id');
            $table->uuid('idempotency_key');
            $table->char('fingerprint', 64);
            $table->string('first_result', 32);
            $table->timestampTz('created_at')->useCurrent();

            $table->unique(['tenant_id', 'idempotency_key'], 'ai_idempotency_demo_tenant_key_unique');
            $table->foreign(['tenant_id', 'ai_advisory_record_demo_id'], 'ai_idempotency_demo_advisory_fk')
                ->references(['tenant_id', 'id'])->on('ai_advisory_records_demo')->cascadeOnDelete();
        });

        Schema::create('ai_human_decisions_demo', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('agency_id')->nullable()->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('ai_advisory_record_demo_id');
            $table->unsignedBigInteger('actor_user_id');
            $table->string('decision', 32);
            $table->string('reason_code', 64);
            $table->string('note', 500)->nullable();
            $table->string('effect', 32);
            $table->timestampTz('created_at')->useCurrent();

            $table->index(['tenant_id', 'ai_advisory_record_demo_id', 'created_at'], 'ai_human_demo_advisory_date_idx');
            $table->foreign(['tenant_id', 'ai_advisory_record_demo_id'], 'ai_human_demo_advisory_fk')
                ->references(['tenant_id', 'id'])->on('ai_advisory_records_demo')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'actor_user_id'], 'ai_human_demo_actor_fk')
                ->references(['tenant_id', 'id'])->on('users')->restrictOnDelete();
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE ai_advisory_records_demo
                ADD CONSTRAINT ai_advisory_demo_module_check
                    CHECK (module_id IN ('demand_forecast', 'fleet_optimization', 'predictive_maintenance', 'rental_usage_anomaly')),
                ADD CONSTRAINT ai_advisory_demo_source_check
                    CHECK (source_kind = 'synthetic_fixture'),
                ADD CONSTRAINT ai_advisory_demo_validation_check
                    CHECK (validation_status = 'validated'),
                ADD CONSTRAINT ai_advisory_demo_effect_check
                    CHECK (operational_effect = 'NO_OPERATIONAL_ACTION'),
                ADD CONSTRAINT ai_advisory_demo_fingerprint_check
                    CHECK (fingerprint ~ '^[0-9a-f]{64}$'),
                ADD CONSTRAINT ai_advisory_demo_payload_check
                    CHECK (
                        payload->>'module_id' = module_id
                        AND payload->>'record_id' = external_record_id::text
                        AND payload#>>'{scope,synthetic_fixture}' = 'true'
                        AND payload#>>'{scope,feature_flag,enabled}' = 'false'
                        AND payload#>>'{scope,automatic_action_allowed}' = 'false'
                        AND payload#>>'{scope,human_decision_required}' = 'true'
                        AND payload#>>'{scope,contains_real_customer_data}' = 'false'
                        AND payload#>>'{scope,contains_coordinates}' = 'false'
                        AND payload#>>'{research_status,historical_public_output_import_allowed}' = 'false'
                        AND payload#>>'{research_status,ready_for_saas}' = 'false'
                        AND payload#>>'{research_status,production_allowed}' = 'false'
                        AND payload#>>'{human_decision,effect}' = 'NO_OPERATIONAL_ACTION'
                        AND payload#>>'{idempotency,canonical_payload_sha256}' = fingerprint
                    );

            ALTER TABLE ai_idempotency_keys_demo
                ADD CONSTRAINT ai_idempotency_demo_fingerprint_check
                    CHECK (fingerprint ~ '^[0-9a-f]{64}$'),
                ADD CONSTRAINT ai_idempotency_demo_result_check
                    CHECK (first_result = 'CREATED');

            ALTER TABLE ai_human_decisions_demo
                ADD CONSTRAINT ai_human_demo_decision_check
                    CHECK (decision IN ('accepted_for_demo_review', 'rejected')),
                ADD CONSTRAINT ai_human_demo_reason_check
                    CHECK (reason_code ~ '^[A-Z][A-Z0-9_]{2,63}$'),
                ADD CONSTRAINT ai_human_demo_effect_check
                    CHECK (effect = 'NO_OPERATIONAL_ACTION');

            CREATE OR REPLACE FUNCTION enforce_ai_contract_demo_scope() RETURNS trigger
            LANGUAGE plpgsql AS $$
            BEGIN
                IF NEW.agency_id IS NOT NULL AND NOT EXISTS (
                    SELECT 1 FROM agencies
                    WHERE id = NEW.agency_id AND tenant_id = NEW.tenant_id AND deleted_at IS NULL
                ) THEN
                    RAISE EXCEPTION 'AI contract demo agency scope mismatch' USING ERRCODE = '23514';
                END IF;

                IF TG_TABLE_NAME = 'ai_advisory_records_demo' THEN
                    IF NEW.created_by IS NOT NULL AND NOT EXISTS (
                        SELECT 1 FROM users
                        WHERE id = NEW.created_by AND tenant_id = NEW.tenant_id
                    ) THEN
                        RAISE EXCEPTION 'AI contract demo creator scope mismatch' USING ERRCODE = '23514';
                    END IF;
                ELSIF TG_TABLE_NAME = 'ai_human_decisions_demo' THEN
                    IF NOT EXISTS (
                        SELECT 1 FROM users
                        WHERE id = NEW.actor_user_id AND tenant_id = NEW.tenant_id AND is_active = true
                    ) THEN
                        RAISE EXCEPTION 'AI contract demo actor scope mismatch' USING ERRCODE = '23514';
                    END IF;

                    IF NOT EXISTS (
                        SELECT 1 FROM ai_advisory_records_demo
                        WHERE id = NEW.ai_advisory_record_demo_id
                            AND tenant_id = NEW.tenant_id
                            AND agency_id IS NOT DISTINCT FROM NEW.agency_id
                    ) THEN
                        RAISE EXCEPTION 'AI contract demo decision scope mismatch' USING ERRCODE = '23514';
                    END IF;
                END IF;

                RETURN NEW;
            END;
            $$;

            CREATE TRIGGER ai_advisory_demo_scope_guard
            BEFORE INSERT ON ai_advisory_records_demo
            FOR EACH ROW EXECUTE FUNCTION enforce_ai_contract_demo_scope();

            CREATE TRIGGER ai_human_demo_scope_guard
            BEFORE INSERT ON ai_human_decisions_demo
            FOR EACH ROW EXECUTE FUNCTION enforce_ai_contract_demo_scope();

            CREATE OR REPLACE FUNCTION prevent_ai_contract_demo_mutation() RETURNS trigger
            LANGUAGE plpgsql AS $$
            BEGIN
                RAISE EXCEPTION 'AI contract demo records are append-only' USING ERRCODE = '23514';
            END;
            $$;

            CREATE TRIGGER ai_advisory_demo_append_only
            BEFORE UPDATE OR DELETE ON ai_advisory_records_demo
            FOR EACH ROW EXECUTE FUNCTION prevent_ai_contract_demo_mutation();

            CREATE TRIGGER ai_idempotency_demo_append_only
            BEFORE UPDATE OR DELETE ON ai_idempotency_keys_demo
            FOR EACH ROW EXECUTE FUNCTION prevent_ai_contract_demo_mutation();

            CREATE TRIGGER ai_human_demo_append_only
            BEFORE UPDATE OR DELETE ON ai_human_decisions_demo
            FOR EACH ROW EXECUTE FUNCTION prevent_ai_contract_demo_mutation();
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS ai_human_demo_append_only ON ai_human_decisions_demo;
            DROP TRIGGER IF EXISTS ai_idempotency_demo_append_only ON ai_idempotency_keys_demo;
            DROP TRIGGER IF EXISTS ai_advisory_demo_append_only ON ai_advisory_records_demo;
            DROP TRIGGER IF EXISTS ai_human_demo_scope_guard ON ai_human_decisions_demo;
            DROP TRIGGER IF EXISTS ai_advisory_demo_scope_guard ON ai_advisory_records_demo;
            DROP FUNCTION IF EXISTS prevent_ai_contract_demo_mutation();
            DROP FUNCTION IF EXISTS enforce_ai_contract_demo_scope();
        SQL);

        Schema::dropIfExists('ai_human_decisions_demo');
        Schema::dropIfExists('ai_idempotency_keys_demo');
        Schema::dropIfExists('ai_advisory_records_demo');
    }
};
