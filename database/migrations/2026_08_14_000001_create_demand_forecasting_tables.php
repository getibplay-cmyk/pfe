<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('demand_history_export_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('agency_id');
            $table->uuid('run_id')->unique();
            $table->string('manifest_version', 16);
            $table->string('schema_version', 16);
            $table->string('dataset_version', 64);
            $table->string('preprocessing_version', 64);
            $table->string('target_semantics', 64);
            $table->string('vehicle_category_scope', 16);
            $table->string('timezone', 64);
            $table->string('distance_unit', 8);
            $table->char('agency_key', 66);
            $table->char('series_key', 66);
            $table->date('date_from');
            $table->date('date_to');
            $table->unsignedInteger('row_count');
            $table->unsignedInteger('max_rows');
            $table->unsignedBigInteger('observed_departures_count');
            $table->char('content_sha256', 64);
            $table->unsignedBigInteger('byte_size');
            $table->string('format', 8);
            $table->string('stored_path', 500);
            $table->string('original_name', 255);
            $table->string('operational_effect', 32);
            $table->unsignedBigInteger('created_by');
            $table->timestampTz('created_at')->useCurrent();

            $table->unique(['tenant_id', 'id'], 'demand_history_runs_tenant_id_unique');
            $table->unique('stored_path', 'demand_history_runs_stored_path_unique');
            $table->index(['tenant_id', 'agency_id', 'created_at'], 'demand_history_runs_scope_date_idx');
            $table->foreign(['tenant_id', 'agency_id'], 'demand_history_runs_agency_fk')
                ->references(['tenant_id', 'id'])->on('agencies')->restrictOnDelete();
            $table->foreign(['tenant_id', 'created_by'], 'demand_history_runs_creator_fk')
                ->references(['tenant_id', 'id'])->on('users')->restrictOnDelete();
        });

        Schema::create('demand_forecast_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('agency_id');
            $table->unsignedBigInteger('demand_history_export_run_id');
            $table->uuid('run_id')->unique();
            $table->uuid('idempotency_key');
            $table->string('schema_version', 16);
            $table->string('model_name', 64);
            $table->string('model_version', 16);
            $table->char('model_artifact_sha256', 64);
            $table->string('framework', 32);
            $table->string('framework_version', 16);
            $table->string('compute', 8);
            $table->string('explanation_method', 64);
            $table->string('mode', 32);
            $table->string('validation_scope', 64);
            $table->string('target_semantics', 64);
            $table->timestampTz('generated_at');
            $table->date('as_of_date');
            $table->unsignedInteger('input_row_count');
            $table->char('input_content_sha256', 64);
            $table->unsignedSmallInteger('result_count');
            $table->decimal('public_wape', 8, 6);
            $table->decimal('public_mase', 8, 6);
            $table->decimal('public_interval_coverage', 8, 6);
            $table->string('local_holdout_status', 64);
            $table->decimal('local_wape', 8, 6)->nullable();
            $table->decimal('local_mase', 8, 6)->nullable();
            $table->decimal('local_interval_coverage', 8, 6)->nullable();
            $table->char('canonical_payload_sha256', 64);
            $table->char('content_sha256', 64);
            $table->unsignedBigInteger('byte_size');
            $table->string('stored_path', 500);
            $table->string('original_name', 255);
            $table->string('validation_status', 24);
            $table->string('operational_effect', 32);
            $table->unsignedBigInteger('imported_by');
            $table->timestampTz('imported_at')->useCurrent();

            $table->unique(['tenant_id', 'id'], 'demand_forecast_runs_tenant_id_unique');
            $table->unique(['tenant_id', 'agency_id', 'idempotency_key'], 'demand_forecast_runs_idempotency_unique');
            $table->unique('stored_path', 'demand_forecast_runs_stored_path_unique');
            $table->index(['tenant_id', 'agency_id', 'as_of_date'], 'demand_forecast_runs_scope_date_idx');
            $table->foreign(['tenant_id', 'agency_id'], 'demand_forecast_runs_agency_fk')
                ->references(['tenant_id', 'id'])->on('agencies')->restrictOnDelete();
            $table->foreign(
                ['tenant_id', 'demand_history_export_run_id'],
                'demand_forecast_runs_history_fk',
            )->references(['tenant_id', 'id'])->on('demand_history_export_runs')->restrictOnDelete();
            $table->foreign(['tenant_id', 'imported_by'], 'demand_forecast_runs_importer_fk')
                ->references(['tenant_id', 'id'])->on('users')->restrictOnDelete();
        });

        Schema::create('demand_forecasts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('agency_id');
            $table->unsignedBigInteger('demand_forecast_run_id');
            $table->unsignedSmallInteger('row_position');
            $table->date('target_date');
            $table->unsignedSmallInteger('horizon');
            $table->string('vehicle_category_scope', 16);
            $table->decimal('conditional_mean', 14, 6);
            $table->decimal('p05', 14, 6);
            $table->decimal('p50', 14, 6);
            $table->decimal('p90', 14, 6);
            $table->decimal('p95', 14, 6);
            $table->boolean('raw_any_crossing');
            $table->boolean('monotone_adjusted');
            $table->jsonb('explanations');
            $table->string('demand_semantics', 64);
            $table->string('operational_effect', 32);
            $table->timestampTz('created_at')->useCurrent();

            $table->unique(['tenant_id', 'id'], 'demand_forecasts_tenant_id_unique');
            $table->unique(['demand_forecast_run_id', 'row_position'], 'demand_forecasts_run_position_unique');
            $table->unique(['demand_forecast_run_id', 'horizon'], 'demand_forecasts_run_horizon_unique');
            $table->unique(['demand_forecast_run_id', 'target_date'], 'demand_forecasts_run_target_unique');
            $table->index(['tenant_id', 'agency_id', 'target_date'], 'demand_forecasts_scope_target_idx');
            $table->foreign(['tenant_id', 'agency_id'], 'demand_forecasts_agency_fk')
                ->references(['tenant_id', 'id'])->on('agencies')->restrictOnDelete();
            $table->foreign(
                ['tenant_id', 'demand_forecast_run_id'],
                'demand_forecasts_run_fk',
            )->references(['tenant_id', 'id'])->on('demand_forecast_runs')->restrictOnDelete();
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE demand_history_export_runs
                ADD CONSTRAINT demand_history_runs_contract_check
                    CHECK (
                        manifest_version = '1.0.0'
                        AND schema_version = '1.0'
                        AND dataset_version = 'rentfleet-demand-history-v1.0.0'
                        AND preprocessing_version = 'rentfleet-demand-preprocessing-v1.0.0'
                        AND target_semantics = 'observed_departures'
                        AND vehicle_category_scope = 'all'
                        AND timezone = 'Africa/Casablanca'
                        AND distance_unit = 'km'
                    ),
                ADD CONSTRAINT demand_history_runs_scope_check
                    CHECK (
                        agency_key ~ '^a_[0-9a-f]{64}$'
                        AND series_key ~ '^s_[0-9a-f]{64}$'
                    ),
                ADD CONSTRAINT demand_history_runs_period_check
                    CHECK (
                        date_from <= date_to
                        AND (date_to - date_from + 1) BETWEEN 35 AND 731
                        AND row_count = (date_to - date_from + 1)
                        AND max_rows = 731
                        AND observed_departures_count >= 0
                    ),
                ADD CONSTRAINT demand_history_runs_digest_check
                    CHECK (content_sha256 ~ '^[0-9a-f]{64}$'),
                ADD CONSTRAINT demand_history_runs_storage_check
                    CHECK (
                        byte_size > 0
                        AND format = 'csv'
                        AND stored_path ~ '^intelligence/demand-history/[0-9a-f-]{36}\.csv$'
                        AND original_name ~ '^rentfleet_demand_history_[0-9a-f-]{36}\.csv$'
                    ),
                ADD CONSTRAINT demand_history_runs_effect_check
                    CHECK (operational_effect = 'NO_OPERATIONAL_ACTION');

            ALTER TABLE demand_forecast_runs
                ADD CONSTRAINT demand_forecast_runs_model_check
                    CHECK (
                        schema_version = '1.0.0'
                        AND model_name = 'hgb_poisson::regularized'
                        AND model_version = 'j5-v1'
                        AND model_artifact_sha256 = '992217b4887623ca924a3dc36686c69ab616634aace64cf993ad50b61ace6802'
                        AND framework = 'scikit-learn'
                        AND framework_version = '1.6.1'
                        AND compute = 'cpu'
                        AND explanation_method = 'one_at_a_time_sensitivity_v1'
                    ),
                ADD CONSTRAINT demand_forecast_runs_semantics_check
                    CHECK (
                        mode = 'consultative_shadow'
                        AND validation_scope = 'public_proxy_only_local_shadow'
                        AND target_semantics = 'observed_departures'
                        AND local_holdout_status = 'not_available_pending_real_history'
                    ),
                ADD CONSTRAINT demand_forecast_runs_input_check
                    CHECK (
                        input_row_count BETWEEN 35 AND 731
                        AND input_content_sha256 ~ '^[0-9a-f]{64}$'
                        AND result_count = 7
                    ),
                ADD CONSTRAINT demand_forecast_runs_evidence_check
                    CHECK (
                        public_wape = 0.152342
                        AND public_mase = 0.829556
                        AND public_interval_coverage = 0.860700
                        AND local_wape IS NULL
                        AND local_mase IS NULL
                        AND local_interval_coverage IS NULL
                    ),
                ADD CONSTRAINT demand_forecast_runs_digest_check
                    CHECK (
                        canonical_payload_sha256 ~ '^[0-9a-f]{64}$'
                        AND content_sha256 ~ '^[0-9a-f]{64}$'
                    ),
                ADD CONSTRAINT demand_forecast_runs_storage_check
                    CHECK (
                        byte_size > 0
                        AND stored_path ~ '^intelligence/demand-forecasts/[0-9a-f-]{36}\.json$'
                        AND original_name ~ '^rentfleet_demand_forecast_[0-9a-f-]{36}\.json$'
                    ),
                ADD CONSTRAINT demand_forecast_runs_status_check
                    CHECK (
                        validation_status = 'validated'
                        AND operational_effect = 'NO_OPERATIONAL_ACTION'
                    );

            ALTER TABLE demand_forecasts
                ADD CONSTRAINT demand_forecasts_contract_check
                    CHECK (
                        row_position BETWEEN 0 AND 6
                        AND horizon BETWEEN 1 AND 7
                        AND vehicle_category_scope = 'all'
                        AND demand_semantics = 'observed_departures'
                        AND operational_effect = 'NO_OPERATIONAL_ACTION'
                    ),
                ADD CONSTRAINT demand_forecasts_values_check
                    CHECK (
                        conditional_mean >= 0
                        AND p05 >= 0
                        AND p05 <= p50
                        AND p50 <= p90
                        AND p90 <= p95
                    ),
                ADD CONSTRAINT demand_forecasts_explanations_check
                    CHECK (
                        jsonb_typeof(explanations) = 'array'
                        AND jsonb_array_length(explanations) = 3
                    );

            CREATE OR REPLACE FUNCTION enforce_demand_forecast_scope() RETURNS trigger
            LANGUAGE plpgsql AS $$
            DECLARE
                linked_as_of date;
            BEGIN
                IF TG_TABLE_NAME = 'demand_forecast_runs' THEN
                    PERFORM 1
                    FROM demand_history_export_runs AS history
                    WHERE history.id = NEW.demand_history_export_run_id
                        AND history.tenant_id = NEW.tenant_id
                        AND history.agency_id = NEW.agency_id
                        AND history.date_to = NEW.as_of_date
                        AND history.row_count = NEW.input_row_count
                        AND history.content_sha256 = NEW.input_content_sha256
                        AND history.target_semantics = NEW.target_semantics
                        AND history.distance_unit = 'km'
                        AND history.operational_effect = 'NO_OPERATIONAL_ACTION'
                    FOR KEY SHARE;

                    IF NOT FOUND THEN
                        RAISE EXCEPTION 'Demand forecast history lineage mismatch' USING ERRCODE = '23514';
                    END IF;
                ELSIF TG_TABLE_NAME = 'demand_forecasts' THEN
                    SELECT run.as_of_date
                    INTO linked_as_of
                    FROM demand_forecast_runs AS run
                    WHERE run.id = NEW.demand_forecast_run_id
                        AND run.tenant_id = NEW.tenant_id
                        AND run.agency_id = NEW.agency_id
                        AND run.validation_status = 'validated'
                    FOR KEY SHARE;

                    IF linked_as_of IS NULL
                        OR NEW.target_date <> linked_as_of + NEW.horizon::integer
                        OR NEW.row_position <> NEW.horizon - 1 THEN
                        RAISE EXCEPTION 'Demand forecast row scope or horizon mismatch' USING ERRCODE = '23514';
                    END IF;
                END IF;

                RETURN NEW;
            END;
            $$;

            CREATE TRIGGER demand_forecast_runs_scope_guard
            BEFORE INSERT ON demand_forecast_runs
            FOR EACH ROW EXECUTE FUNCTION enforce_demand_forecast_scope();

            CREATE TRIGGER demand_forecasts_scope_guard
            BEFORE INSERT ON demand_forecasts
            FOR EACH ROW EXECUTE FUNCTION enforce_demand_forecast_scope();

            CREATE OR REPLACE FUNCTION enforce_demand_forecast_completeness() RETURNS trigger
            LANGUAGE plpgsql AS $$
            DECLARE
                persisted_count integer;
                minimum_horizon integer;
                maximum_horizon integer;
            BEGIN
                SELECT count(*), min(horizon), max(horizon)
                INTO persisted_count, minimum_horizon, maximum_horizon
                FROM demand_forecasts
                WHERE demand_forecast_run_id = NEW.id
                    AND tenant_id = NEW.tenant_id;

                IF persisted_count <> NEW.result_count
                    OR minimum_horizon <> 1
                    OR maximum_horizon <> 7 THEN
                    RAISE EXCEPTION 'Demand forecast rows are incomplete' USING ERRCODE = '23514';
                END IF;

                RETURN NEW;
            END;
            $$;

            CREATE CONSTRAINT TRIGGER demand_forecast_runs_completeness_guard
            AFTER INSERT ON demand_forecast_runs
            DEFERRABLE INITIALLY DEFERRED
            FOR EACH ROW EXECUTE FUNCTION enforce_demand_forecast_completeness();

            CREATE OR REPLACE FUNCTION prevent_demand_forecast_mutation() RETURNS trigger
            LANGUAGE plpgsql AS $$
            BEGIN
                RAISE EXCEPTION 'Demand forecast evidence is append-only' USING ERRCODE = '23514';
            END;
            $$;

            CREATE TRIGGER demand_history_runs_append_only
            BEFORE UPDATE OR DELETE ON demand_history_export_runs
            FOR EACH ROW EXECUTE FUNCTION prevent_demand_forecast_mutation();

            CREATE TRIGGER demand_forecast_runs_append_only
            BEFORE UPDATE OR DELETE ON demand_forecast_runs
            FOR EACH ROW EXECUTE FUNCTION prevent_demand_forecast_mutation();

            CREATE TRIGGER demand_forecasts_append_only
            BEFORE UPDATE OR DELETE ON demand_forecasts
            FOR EACH ROW EXECUTE FUNCTION prevent_demand_forecast_mutation();
        SQL);

        DB::table('permissions')->insertOrIgnore([
            'slug' => 'prediction.forecast.import',
            'name' => 'Importer les prévisions de demande consultatives',
            'group' => 'prediction',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('permissions')->where('slug', 'prediction.forecast.import')->update([
            'name' => 'Importer les prévisions de demande consultatives',
            'group' => 'prediction',
            'updated_at' => now(),
        ]);

        $permissionId = DB::table('permissions')
            ->where('slug', 'prediction.forecast.import')
            ->value('id');
        if ($permissionId !== null) {
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
        }
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS demand_forecasts_append_only ON demand_forecasts;
            DROP TRIGGER IF EXISTS demand_forecast_runs_append_only ON demand_forecast_runs;
            DROP TRIGGER IF EXISTS demand_history_runs_append_only ON demand_history_export_runs;
            DROP TRIGGER IF EXISTS demand_forecasts_scope_guard ON demand_forecasts;
            DROP TRIGGER IF EXISTS demand_forecast_runs_scope_guard ON demand_forecast_runs;
            DROP TRIGGER IF EXISTS demand_forecast_runs_completeness_guard ON demand_forecast_runs;
            DROP FUNCTION IF EXISTS prevent_demand_forecast_mutation();
            DROP FUNCTION IF EXISTS enforce_demand_forecast_completeness();
            DROP FUNCTION IF EXISTS enforce_demand_forecast_scope();
        SQL);

        Schema::dropIfExists('demand_forecasts');
        Schema::dropIfExists('demand_forecast_runs');
        Schema::dropIfExists('demand_history_export_runs');

        // Conservative rollback: the permission and later delegations remain.
    }
};
