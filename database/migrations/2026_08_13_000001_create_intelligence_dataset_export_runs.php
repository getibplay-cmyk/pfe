<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('intelligence_dataset_export_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('agency_id')->nullable();
            $table->uuid('run_id')->unique();
            $table->string('manifest_version', 16);
            $table->string('schema_version', 16);
            $table->string('dataset_version', 64);
            $table->string('scope_kind', 16);
            $table->char('scope_key', 66);
            $table->date('date_from');
            $table->date('date_to');
            $table->string('timezone', 64);
            $table->unsignedInteger('row_count');
            $table->unsignedInteger('max_rows');
            $table->char('content_sha256', 64);
            $table->unsignedBigInteger('byte_size');
            $table->string('format', 8);
            $table->string('stored_path', 500);
            $table->string('original_name', 255);
            $table->string('operational_effect', 32);
            $table->unsignedBigInteger('created_by');
            $table->timestampTz('created_at')->useCurrent();

            $table->unique(['tenant_id', 'id'], 'intelligence_export_runs_tenant_id_unique');
            $table->unique('stored_path', 'intelligence_export_runs_stored_path_unique');
            $table->index(['tenant_id', 'agency_id', 'created_at'], 'intelligence_export_runs_scope_date_idx');
            $table->foreign(['tenant_id', 'agency_id'], 'intelligence_export_runs_agency_fk')
                ->references(['tenant_id', 'id'])->on('agencies')->restrictOnDelete();
            $table->foreign(['tenant_id', 'created_by'], 'intelligence_export_runs_creator_fk')
                ->references(['tenant_id', 'id'])->on('users')->restrictOnDelete();
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE intelligence_dataset_export_runs
                ADD CONSTRAINT intelligence_export_runs_manifest_check
                    CHECK (manifest_version = '1.0.0'),
                ADD CONSTRAINT intelligence_export_runs_dataset_check
                    CHECK (
                        schema_version = '1.1'
                        AND dataset_version = 'rentfleet-real-returns-v1.1.0'
                    ),
                ADD CONSTRAINT intelligence_export_runs_scope_check
                    CHECK (
                        (scope_kind = 'tenant' AND agency_id IS NULL AND scope_key ~ '^t_[0-9a-f]{64}$')
                        OR (scope_kind = 'agency' AND agency_id IS NOT NULL AND scope_key ~ '^a_[0-9a-f]{64}$')
                    ),
                ADD CONSTRAINT intelligence_export_runs_period_check
                    CHECK (date_from <= date_to),
                ADD CONSTRAINT intelligence_export_runs_rows_check
                    CHECK (max_rows = 10000 AND row_count <= max_rows),
                ADD CONSTRAINT intelligence_export_runs_digest_check
                    CHECK (content_sha256 ~ '^[0-9a-f]{64}$'),
                ADD CONSTRAINT intelligence_export_runs_storage_check
                    CHECK (
                        byte_size > 0
                        AND format = 'csv'
                        AND stored_path ~ '^intelligence/dataset-exports/[0-9a-f-]{36}\.csv$'
                    ),
                ADD CONSTRAINT intelligence_export_runs_effect_check
                    CHECK (operational_effect = 'NO_OPERATIONAL_ACTION');

            CREATE OR REPLACE FUNCTION prevent_intelligence_export_run_mutation() RETURNS trigger
            LANGUAGE plpgsql AS $$
            BEGIN
                RAISE EXCEPTION 'Intelligence dataset export runs are append-only' USING ERRCODE = '23514';
            END;
            $$;

            CREATE TRIGGER intelligence_export_runs_append_only
            BEFORE UPDATE OR DELETE ON intelligence_dataset_export_runs
            FOR EACH ROW EXECUTE FUNCTION prevent_intelligence_export_run_mutation();
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS intelligence_export_runs_append_only ON intelligence_dataset_export_runs;
            DROP FUNCTION IF EXISTS prevent_intelligence_export_run_mutation();
        SQL);

        Schema::dropIfExists('intelligence_dataset_export_runs');
    }
};
