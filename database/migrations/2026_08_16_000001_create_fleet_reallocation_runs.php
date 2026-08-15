<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fleet_reallocation_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->uuid('run_id')->unique();
            $table->unsignedBigInteger('requested_by');
            $table->unsignedBigInteger('fleet_reallocation_proposal_id')->nullable();
            $table->unsignedSmallInteger('forecast_horizon');
            $table->unsignedSmallInteger('scenario_number');
            $table->string('status', 16);
            $table->string('failure_code', 64)->nullable();
            $table->string('operational_effect', 32);
            $table->timestampTz('requested_at')->useCurrent();
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('finished_at')->nullable();

            $table->unique(['tenant_id', 'id'], 'fleet_reallocation_runs_tenant_id_unique');
            $table->unique(
                'fleet_reallocation_proposal_id',
                'fleet_reallocation_runs_proposal_unique',
            );
            $table->index(['tenant_id', 'requested_at'], 'fleet_reallocation_runs_scope_date_idx');
            $table->foreign(['tenant_id', 'requested_by'], 'fleet_reallocation_runs_requester_fk')
                ->references(['tenant_id', 'id'])->on('users')->restrictOnDelete();
            $table->foreign(
                ['tenant_id', 'fleet_reallocation_proposal_id'],
                'fleet_reallocation_runs_proposal_fk',
            )->references(['tenant_id', 'id'])->on('fleet_reallocation_proposals')->restrictOnDelete();
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE fleet_reallocation_runs
                ADD CONSTRAINT fleet_reallocation_runs_contract_check
                    CHECK (
                        forecast_horizon BETWEEN 1 AND 7
                        AND scenario_number = forecast_horizon
                        AND status IN ('queued', 'running', 'succeeded', 'failed')
                        AND operational_effect = 'NO_OPERATIONAL_ACTION'
                        AND (failure_code IS NULL OR failure_code ~ '^[A-Z][A-Z0-9_]{2,63}$')
                    ),
                ADD CONSTRAINT fleet_reallocation_runs_state_check
                    CHECK (
                        (status = 'queued'
                            AND started_at IS NULL
                            AND finished_at IS NULL
                            AND fleet_reallocation_proposal_id IS NULL
                            AND failure_code IS NULL)
                        OR (status = 'running'
                            AND started_at IS NOT NULL
                            AND finished_at IS NULL
                            AND fleet_reallocation_proposal_id IS NULL
                            AND failure_code IS NULL)
                        OR (status = 'succeeded'
                            AND started_at IS NOT NULL
                            AND finished_at IS NOT NULL
                            AND fleet_reallocation_proposal_id IS NOT NULL
                            AND failure_code IS NULL)
                        OR (status = 'failed'
                            AND started_at IS NOT NULL
                            AND finished_at IS NOT NULL
                            AND fleet_reallocation_proposal_id IS NULL
                            AND failure_code IS NOT NULL)
                    );

            CREATE UNIQUE INDEX fleet_reallocation_runs_one_active_per_tenant
            ON fleet_reallocation_runs (tenant_id)
            WHERE status IN ('queued', 'running');

            CREATE OR REPLACE FUNCTION guard_fleet_reallocation_run_transition() RETURNS trigger
            LANGUAGE plpgsql AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    RAISE EXCEPTION 'Fleet reallocation runs cannot be deleted' USING ERRCODE = '23514';
                END IF;

                IF NEW.tenant_id <> OLD.tenant_id
                    OR NEW.run_id <> OLD.run_id
                    OR NEW.requested_by <> OLD.requested_by
                    OR NEW.forecast_horizon <> OLD.forecast_horizon
                    OR NEW.scenario_number <> OLD.scenario_number
                    OR NEW.operational_effect <> OLD.operational_effect
                    OR NEW.requested_at <> OLD.requested_at THEN
                    RAISE EXCEPTION 'Fleet reallocation run identity is immutable' USING ERRCODE = '23514';
                END IF;

                IF OLD.status = 'queued' AND NEW.status NOT IN ('running', 'failed') THEN
                    RAISE EXCEPTION 'Invalid queued fleet reallocation transition' USING ERRCODE = '23514';
                ELSIF OLD.status = 'running' AND NEW.status NOT IN ('succeeded', 'failed') THEN
                    RAISE EXCEPTION 'Invalid running fleet reallocation transition' USING ERRCODE = '23514';
                ELSIF OLD.status IN ('succeeded', 'failed') THEN
                    RAISE EXCEPTION 'Terminal fleet reallocation run is immutable' USING ERRCODE = '23514';
                END IF;

                RETURN NEW;
            END;
            $$;

            CREATE TRIGGER fleet_reallocation_runs_transition_guard
            BEFORE UPDATE OR DELETE ON fleet_reallocation_runs
            FOR EACH ROW EXECUTE FUNCTION guard_fleet_reallocation_run_transition();
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS fleet_reallocation_runs_transition_guard ON fleet_reallocation_runs;
            DROP FUNCTION IF EXISTS guard_fleet_reallocation_run_transition();
        SQL);

        Schema::dropIfExists('fleet_reallocation_runs');
    }
};
