<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('demand_forecast_execution_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('agency_id');
            $table->uuid('run_id')->unique();
            $table->unsignedBigInteger('demand_history_export_run_id');
            $table->unsignedBigInteger('requested_by');
            $table->unsignedBigInteger('demand_forecast_run_id')->nullable();
            $table->string('status', 16);
            $table->string('failure_code', 64)->nullable();
            $table->char('model_artifact_sha256', 64);
            $table->unsignedBigInteger('model_artifact_bytes');
            $table->string('operational_effect', 32);
            $table->timestampTz('requested_at')->useCurrent();
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('finished_at')->nullable();

            $table->unique(['tenant_id', 'id'], 'demand_forecast_exec_tenant_id_unique');
            $table->unique('demand_forecast_run_id', 'demand_forecast_exec_forecast_unique');
            $table->index(
                ['tenant_id', 'agency_id', 'requested_at'],
                'demand_forecast_exec_scope_date_idx',
            );
            $table->foreign(['tenant_id', 'agency_id'], 'demand_forecast_exec_agency_fk')
                ->references(['tenant_id', 'id'])->on('agencies')->restrictOnDelete();
            $table->foreign(
                ['tenant_id', 'demand_history_export_run_id'],
                'demand_forecast_exec_history_fk',
            )->references(['tenant_id', 'id'])->on('demand_history_export_runs')->restrictOnDelete();
            $table->foreign(['tenant_id', 'requested_by'], 'demand_forecast_exec_requester_fk')
                ->references(['tenant_id', 'id'])->on('users')->restrictOnDelete();
            $table->foreign(
                ['tenant_id', 'demand_forecast_run_id'],
                'demand_forecast_exec_forecast_fk',
            )->references(['tenant_id', 'id'])->on('demand_forecast_runs')->restrictOnDelete();
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE demand_forecast_execution_runs
                ADD CONSTRAINT demand_forecast_exec_contract_check
                    CHECK (
                        status IN ('queued', 'running', 'succeeded', 'failed')
                        AND model_artifact_sha256 = '992217b4887623ca924a3dc36686c69ab616634aace64cf993ad50b61ace6802'
                        AND model_artifact_bytes = 6401204
                        AND operational_effect = 'NO_OPERATIONAL_ACTION'
                        AND (failure_code IS NULL OR failure_code ~ '^[A-Z][A-Z0-9_]{2,63}$')
                    ),
                ADD CONSTRAINT demand_forecast_exec_state_check
                    CHECK (
                        (status = 'queued'
                            AND started_at IS NULL
                            AND finished_at IS NULL
                            AND demand_forecast_run_id IS NULL
                            AND failure_code IS NULL)
                        OR (status = 'running'
                            AND started_at IS NOT NULL
                            AND finished_at IS NULL
                            AND demand_forecast_run_id IS NULL
                            AND failure_code IS NULL)
                        OR (status = 'succeeded'
                            AND started_at IS NOT NULL
                            AND finished_at IS NOT NULL
                            AND demand_forecast_run_id IS NOT NULL
                            AND failure_code IS NULL)
                        OR (status = 'failed'
                            AND started_at IS NOT NULL
                            AND finished_at IS NOT NULL
                            AND demand_forecast_run_id IS NULL
                            AND failure_code IS NOT NULL)
                    );

            CREATE UNIQUE INDEX demand_forecast_exec_one_active_per_history
            ON demand_forecast_execution_runs (tenant_id, demand_history_export_run_id)
            WHERE status IN ('queued', 'running');

            CREATE OR REPLACE FUNCTION enforce_demand_forecast_execution_scope() RETURNS trigger
            LANGUAGE plpgsql AS $$
            BEGIN
                PERFORM 1
                FROM demand_history_export_runs AS history
                WHERE history.id = NEW.demand_history_export_run_id
                    AND history.tenant_id = NEW.tenant_id
                    AND history.agency_id = NEW.agency_id
                FOR KEY SHARE;
                IF NOT FOUND THEN
                    RAISE EXCEPTION 'Demand forecast execution history scope mismatch' USING ERRCODE = '23514';
                END IF;

                IF NEW.demand_forecast_run_id IS NOT NULL THEN
                    PERFORM 1
                    FROM demand_forecast_runs AS forecast
                    WHERE forecast.id = NEW.demand_forecast_run_id
                        AND forecast.tenant_id = NEW.tenant_id
                        AND forecast.agency_id = NEW.agency_id
                        AND forecast.demand_history_export_run_id = NEW.demand_history_export_run_id
                        AND forecast.validation_status = 'validated'
                    FOR KEY SHARE;
                    IF NOT FOUND THEN
                        RAISE EXCEPTION 'Demand forecast execution result lineage mismatch' USING ERRCODE = '23514';
                    END IF;
                END IF;

                RETURN NEW;
            END;
            $$;

            CREATE TRIGGER demand_forecast_exec_scope_guard
            BEFORE INSERT OR UPDATE ON demand_forecast_execution_runs
            FOR EACH ROW EXECUTE FUNCTION enforce_demand_forecast_execution_scope();

            CREATE OR REPLACE FUNCTION guard_demand_forecast_execution_transition() RETURNS trigger
            LANGUAGE plpgsql AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    RAISE EXCEPTION 'Demand forecast execution runs cannot be deleted' USING ERRCODE = '23514';
                END IF;

                IF NEW.tenant_id <> OLD.tenant_id
                    OR NEW.agency_id <> OLD.agency_id
                    OR NEW.run_id <> OLD.run_id
                    OR NEW.demand_history_export_run_id <> OLD.demand_history_export_run_id
                    OR NEW.requested_by <> OLD.requested_by
                    OR NEW.model_artifact_sha256 <> OLD.model_artifact_sha256
                    OR NEW.model_artifact_bytes <> OLD.model_artifact_bytes
                    OR NEW.operational_effect <> OLD.operational_effect
                    OR NEW.requested_at <> OLD.requested_at THEN
                    RAISE EXCEPTION 'Demand forecast execution identity is immutable' USING ERRCODE = '23514';
                END IF;

                IF OLD.status = 'queued' AND NEW.status NOT IN ('running', 'failed') THEN
                    RAISE EXCEPTION 'Invalid queued demand forecast transition' USING ERRCODE = '23514';
                ELSIF OLD.status = 'running' AND NEW.status NOT IN ('succeeded', 'failed') THEN
                    RAISE EXCEPTION 'Invalid running demand forecast transition' USING ERRCODE = '23514';
                ELSIF OLD.status IN ('succeeded', 'failed') THEN
                    RAISE EXCEPTION 'Terminal demand forecast execution is immutable' USING ERRCODE = '23514';
                END IF;

                RETURN NEW;
            END;
            $$;

            CREATE TRIGGER demand_forecast_exec_transition_guard
            BEFORE UPDATE OR DELETE ON demand_forecast_execution_runs
            FOR EACH ROW EXECUTE FUNCTION guard_demand_forecast_execution_transition();
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS demand_forecast_exec_transition_guard ON demand_forecast_execution_runs;
            DROP TRIGGER IF EXISTS demand_forecast_exec_scope_guard ON demand_forecast_execution_runs;
            DROP FUNCTION IF EXISTS guard_demand_forecast_execution_transition();
            DROP FUNCTION IF EXISTS enforce_demand_forecast_execution_scope();
        SQL);

        Schema::dropIfExists('demand_forecast_execution_runs');
    }
};
