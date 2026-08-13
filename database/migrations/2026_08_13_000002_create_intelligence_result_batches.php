<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('intelligence_result_batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('agency_id')->nullable();
            $table->unsignedBigInteger('intelligence_dataset_export_run_id');
            $table->uuid('batch_id')->unique();
            $table->uuid('idempotency_key');
            $table->string('schema_version', 16);
            $table->string('dataset_schema_version', 16);
            $table->string('dataset_version', 64);
            $table->unsignedInteger('export_row_count');
            $table->char('export_content_sha256', 64);
            $table->string('source_kind', 32);
            $table->string('computation_status', 64);
            $table->string('producer_name', 64);
            $table->string('producer_version', 16);
            $table->string('producer_environment', 32);
            $table->timestampTz('generated_at');
            $table->unsignedInteger('result_count');
            $table->char('canonical_payload_sha256', 64);
            $table->char('content_sha256', 64);
            $table->unsignedBigInteger('byte_size');
            $table->string('stored_path', 500);
            $table->string('original_name', 255);
            $table->string('validation_status', 24);
            $table->string('operational_effect', 32);
            $table->unsignedBigInteger('imported_by');
            $table->timestampTz('imported_at')->useCurrent();

            $table->unique(['tenant_id', 'id'], 'intelligence_result_batches_tenant_id_unique');
            $table->unique('stored_path', 'intelligence_result_batches_path_unique');
            $table->index(
                ['tenant_id', 'agency_id', 'imported_at'],
                'intelligence_result_batches_scope_date_idx',
            );
            $table->foreign(
                ['tenant_id', 'agency_id'],
                'intelligence_result_batches_agency_fk',
            )->references(['tenant_id', 'id'])->on('agencies')->restrictOnDelete();
            $table->foreign(
                ['tenant_id', 'intelligence_dataset_export_run_id'],
                'intelligence_result_batches_export_fk',
            )->references(['tenant_id', 'id'])->on('intelligence_dataset_export_runs')->restrictOnDelete();
            $table->foreign(
                ['tenant_id', 'imported_by'],
                'intelligence_result_batches_importer_fk',
            )->references(['tenant_id', 'id'])->on('users')->restrictOnDelete();
        });

        Schema::create('intelligence_result_rows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('agency_id')->nullable();
            $table->unsignedBigInteger('intelligence_result_batch_id');
            $table->unsignedInteger('row_position');
            $table->char('row_key', 66);
            $table->string('advisory_kind', 32);
            $table->string('priority', 16);
            $table->string('summary_code', 64);
            $table->jsonb('factors');
            $table->string('operational_effect', 32);
            $table->timestampTz('created_at')->useCurrent();

            $table->unique(['tenant_id', 'id'], 'intelligence_result_rows_tenant_id_unique');
            $table->unique(
                ['intelligence_result_batch_id', 'row_key'],
                'intelligence_result_rows_batch_row_unique',
            );
            $table->unique(
                ['intelligence_result_batch_id', 'row_position'],
                'intelligence_result_rows_batch_position_unique',
            );
            $table->index(
                ['tenant_id', 'agency_id', 'intelligence_result_batch_id'],
                'intelligence_result_rows_scope_batch_idx',
            );
            $table->foreign(
                ['tenant_id', 'agency_id'],
                'intelligence_result_rows_agency_fk',
            )->references(['tenant_id', 'id'])->on('agencies')->restrictOnDelete();
            $table->foreign(
                ['tenant_id', 'intelligence_result_batch_id'],
                'intelligence_result_rows_batch_fk',
            )->references(['tenant_id', 'id'])->on('intelligence_result_batches')->restrictOnDelete();
        });

        Schema::create('intelligence_result_batch_decisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('agency_id')->nullable();
            $table->unsignedBigInteger('intelligence_result_batch_id');
            $table->unsignedBigInteger('actor_user_id');
            $table->string('decision', 32);
            $table->string('reason_code', 64);
            $table->string('effect', 32);
            $table->timestampTz('created_at')->useCurrent();

            $table->unique(
                'intelligence_result_batch_id',
                'intelligence_result_decisions_batch_unique',
            );
            $table->index(
                ['tenant_id', 'agency_id', 'created_at'],
                'intelligence_result_decisions_scope_date_idx',
            );
            $table->foreign(
                ['tenant_id', 'agency_id'],
                'intelligence_result_decisions_agency_fk',
            )->references(['tenant_id', 'id'])->on('agencies')->restrictOnDelete();
            $table->foreign(
                ['tenant_id', 'intelligence_result_batch_id'],
                'intelligence_result_decisions_batch_fk',
            )->references(['tenant_id', 'id'])->on('intelligence_result_batches')->restrictOnDelete();
            $table->foreign(
                ['tenant_id', 'actor_user_id'],
                'intelligence_result_decisions_actor_fk',
            )->references(['tenant_id', 'id'])->on('users')->restrictOnDelete();
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE intelligence_result_batches
                ADD CONSTRAINT intelligence_result_batches_idempotency_unique
                    UNIQUE NULLS NOT DISTINCT (tenant_id, agency_id, idempotency_key),
                ADD CONSTRAINT intelligence_result_batches_schema_check
                    CHECK (
                        schema_version = '1.0.0'
                        AND dataset_schema_version = '1.1'
                        AND dataset_version = 'rentfleet-real-returns-v1.1.0'
                    ),
                ADD CONSTRAINT intelligence_result_batches_link_check
                    CHECK (
                        export_row_count BETWEEN 0 AND 10000
                        AND result_count = export_row_count
                        AND export_content_sha256 ~ '^[0-9a-f]{64}$'
                    ),
                ADD CONSTRAINT intelligence_result_batches_source_check
                    CHECK (
                        source_kind = 'synthetic_fixture'
                        AND computation_status = 'not_run_synthetic_contract_fixture'
                        AND producer_name = 'rentfleet-j14-synthetic-fixture'
                        AND producer_version = '1.0.0'
                        AND producer_environment = 'offline_contract_demo'
                    ),
                ADD CONSTRAINT intelligence_result_batches_digest_check
                    CHECK (
                        canonical_payload_sha256 ~ '^[0-9a-f]{64}$'
                        AND content_sha256 ~ '^[0-9a-f]{64}$'
                    ),
                ADD CONSTRAINT intelligence_result_batches_storage_check
                    CHECK (
                        byte_size > 0
                        AND stored_path ~ '^intelligence/result-batches/[0-9a-f-]{36}\.json$'
                        AND original_name ~ '^rentfleet_j14_result_batch_[0-9a-f-]{36}\.json$'
                    ),
                ADD CONSTRAINT intelligence_result_batches_validation_check
                    CHECK (validation_status = 'validated'),
                ADD CONSTRAINT intelligence_result_batches_effect_check
                    CHECK (operational_effect = 'NO_OPERATIONAL_ACTION');

            ALTER TABLE intelligence_result_rows
                ADD CONSTRAINT intelligence_result_rows_key_check
                    CHECK (row_position BETWEEN 0 AND 9999 AND row_key ~ '^r_[0-9a-f]{64}$'),
                ADD CONSTRAINT intelligence_result_rows_advisory_check
                    CHECK (
                        advisory_kind = 'rental_usage_review'
                        AND priority IN ('low', 'medium', 'high')
                        AND summary_code = 'SYNTHETIC_REVIEW_ONLY'
                    ),
                ADD CONSTRAINT intelligence_result_rows_factors_check
                    CHECK (
                        CASE
                            WHEN jsonb_typeof(factors) = 'array'
                            THEN jsonb_array_length(factors) = 3
                                AND factors->0 IN (
                                    '{"name":"late_hours","level":"normal"}'::jsonb,
                                    '{"name":"late_hours","level":"elevated"}'::jsonb
                                )
                                AND factors->1 IN (
                                    '{"name":"km_per_day","level":"normal"}'::jsonb,
                                    '{"name":"km_per_day","level":"elevated"}'::jsonb
                                )
                                AND factors->2 IN (
                                    '{"name":"fuel_drop_pct","level":"normal"}'::jsonb,
                                    '{"name":"fuel_drop_pct","level":"elevated"}'::jsonb
                                )
                                AND priority = CASE
                                    WHEN (
                                        (factors->0->>'level' = 'elevated')::integer
                                        + (factors->1->>'level' = 'elevated')::integer
                                        + (factors->2->>'level' = 'elevated')::integer
                                    ) = 0 THEN 'low'
                                    WHEN (
                                        (factors->0->>'level' = 'elevated')::integer
                                        + (factors->1->>'level' = 'elevated')::integer
                                        + (factors->2->>'level' = 'elevated')::integer
                                    ) = 1 THEN 'medium'
                                    ELSE 'high'
                                END
                            ELSE false
                        END
                    ),
                ADD CONSTRAINT intelligence_result_rows_effect_check
                    CHECK (operational_effect = 'NO_OPERATIONAL_ACTION');

            ALTER TABLE intelligence_result_batch_decisions
                ADD CONSTRAINT intelligence_result_decisions_value_check
                    CHECK (decision IN ('accepted_for_demo_review', 'rejected')),
                ADD CONSTRAINT intelligence_result_decisions_reason_check
                    CHECK (reason_code ~ '^[A-Z][A-Z0-9_]{2,63}$'),
                ADD CONSTRAINT intelligence_result_decisions_effect_check
                    CHECK (effect = 'NO_OPERATIONAL_ACTION');

            CREATE OR REPLACE FUNCTION enforce_intelligence_result_scope() RETURNS trigger
            LANGUAGE plpgsql AS $$
            BEGIN
                IF TG_TABLE_NAME = 'intelligence_result_batches' THEN
                    PERFORM 1
                    FROM intelligence_dataset_export_runs AS export_run
                    WHERE export_run.id = NEW.intelligence_dataset_export_run_id
                        AND export_run.tenant_id = NEW.tenant_id
                        AND export_run.agency_id IS NOT DISTINCT FROM NEW.agency_id
                        AND export_run.schema_version = NEW.dataset_schema_version
                        AND export_run.dataset_version = NEW.dataset_version
                        AND export_run.row_count = NEW.export_row_count
                        AND export_run.content_sha256 = NEW.export_content_sha256
                        AND export_run.operational_effect = 'NO_OPERATIONAL_ACTION'
                    FOR KEY SHARE;

                    IF NOT FOUND THEN
                        RAISE EXCEPTION 'J14 result batch export link mismatch' USING ERRCODE = '23514';
                    END IF;
                ELSIF TG_TABLE_NAME = 'intelligence_result_rows' THEN
                    PERFORM 1
                    FROM intelligence_result_batches AS batch
                    WHERE batch.id = NEW.intelligence_result_batch_id
                        AND batch.tenant_id = NEW.tenant_id
                        AND batch.agency_id IS NOT DISTINCT FROM NEW.agency_id
                        AND batch.validation_status = 'validated'
                    FOR KEY SHARE;

                    IF NOT FOUND THEN
                        RAISE EXCEPTION 'J14 result row scope mismatch' USING ERRCODE = '23514';
                    END IF;
                ELSIF TG_TABLE_NAME = 'intelligence_result_batch_decisions' THEN
                    PERFORM 1
                    FROM intelligence_result_batches AS batch
                    WHERE batch.id = NEW.intelligence_result_batch_id
                        AND batch.tenant_id = NEW.tenant_id
                        AND batch.agency_id IS NOT DISTINCT FROM NEW.agency_id
                    FOR KEY SHARE;

                    IF NOT FOUND THEN
                        RAISE EXCEPTION 'J14 result decision scope mismatch' USING ERRCODE = '23514';
                    END IF;

                    PERFORM 1
                    FROM users
                    WHERE id = NEW.actor_user_id
                        AND tenant_id = NEW.tenant_id
                        AND is_active = true
                    FOR KEY SHARE;

                    IF NOT FOUND THEN
                        RAISE EXCEPTION 'J14 result decision actor mismatch' USING ERRCODE = '23514';
                    END IF;
                END IF;

                RETURN NEW;
            END;
            $$;

            CREATE TRIGGER intelligence_result_batches_scope_guard
            BEFORE INSERT ON intelligence_result_batches
            FOR EACH ROW EXECUTE FUNCTION enforce_intelligence_result_scope();

            CREATE TRIGGER intelligence_result_rows_scope_guard
            BEFORE INSERT ON intelligence_result_rows
            FOR EACH ROW EXECUTE FUNCTION enforce_intelligence_result_scope();

            CREATE TRIGGER intelligence_result_decisions_scope_guard
            BEFORE INSERT ON intelligence_result_batch_decisions
            FOR EACH ROW EXECUTE FUNCTION enforce_intelligence_result_scope();

            CREATE OR REPLACE FUNCTION enforce_intelligence_result_completeness() RETURNS trigger
            LANGUAGE plpgsql AS $$
            DECLARE
                persisted_count integer;
                minimum_position integer;
                maximum_position integer;
            BEGIN
                SELECT count(*), min(row_position), max(row_position)
                INTO persisted_count, minimum_position, maximum_position
                FROM intelligence_result_rows
                WHERE intelligence_result_batch_id = NEW.id
                    AND tenant_id = NEW.tenant_id;

                IF persisted_count <> NEW.result_count
                    OR (persisted_count > 0 AND (minimum_position <> 0 OR maximum_position <> persisted_count - 1)) THEN
                    RAISE EXCEPTION 'J14 result batch rows are incomplete' USING ERRCODE = '23514';
                END IF;

                RETURN NEW;
            END;
            $$;

            CREATE CONSTRAINT TRIGGER intelligence_result_batches_completeness_guard
            AFTER INSERT ON intelligence_result_batches
            DEFERRABLE INITIALLY DEFERRED
            FOR EACH ROW EXECUTE FUNCTION enforce_intelligence_result_completeness();

            CREATE OR REPLACE FUNCTION prevent_intelligence_result_mutation() RETURNS trigger
            LANGUAGE plpgsql AS $$
            BEGIN
                RAISE EXCEPTION 'J14 result records are append-only' USING ERRCODE = '23514';
            END;
            $$;

            CREATE TRIGGER intelligence_result_batches_append_only
            BEFORE UPDATE OR DELETE ON intelligence_result_batches
            FOR EACH ROW EXECUTE FUNCTION prevent_intelligence_result_mutation();

            CREATE TRIGGER intelligence_result_rows_append_only
            BEFORE UPDATE OR DELETE ON intelligence_result_rows
            FOR EACH ROW EXECUTE FUNCTION prevent_intelligence_result_mutation();

            CREATE TRIGGER intelligence_result_decisions_append_only
            BEFORE UPDATE OR DELETE ON intelligence_result_batch_decisions
            FOR EACH ROW EXECUTE FUNCTION prevent_intelligence_result_mutation();
        SQL);

        DB::table('permissions')->where('slug', 'prediction.demo.review')->update([
            'name' => 'Importer et revoir les preuves Intelligence synthétiques',
            'group' => 'prediction',
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS intelligence_result_decisions_append_only ON intelligence_result_batch_decisions;
            DROP TRIGGER IF EXISTS intelligence_result_rows_append_only ON intelligence_result_rows;
            DROP TRIGGER IF EXISTS intelligence_result_batches_append_only ON intelligence_result_batches;
            DROP TRIGGER IF EXISTS intelligence_result_decisions_scope_guard ON intelligence_result_batch_decisions;
            DROP TRIGGER IF EXISTS intelligence_result_rows_scope_guard ON intelligence_result_rows;
            DROP TRIGGER IF EXISTS intelligence_result_batches_scope_guard ON intelligence_result_batches;
            DROP TRIGGER IF EXISTS intelligence_result_batches_completeness_guard ON intelligence_result_batches;
            DROP FUNCTION IF EXISTS prevent_intelligence_result_mutation();
            DROP FUNCTION IF EXISTS enforce_intelligence_result_completeness();
            DROP FUNCTION IF EXISTS enforce_intelligence_result_scope();
        SQL);

        Schema::dropIfExists('intelligence_result_batch_decisions');
        Schema::dropIfExists('intelligence_result_rows');
        Schema::dropIfExists('intelligence_result_batches');

        DB::table('permissions')->where('slug', 'prediction.demo.review')->update([
            'name' => 'Revoir les contrats Intelligence synthétiques de démonstration',
            'updated_at' => now(),
        ]);
    }
};
