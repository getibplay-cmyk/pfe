<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fleet_reallocation_planning_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->uuid('run_id')->unique();
            $table->unsignedBigInteger('requested_by');
            $table->string('source_kind', 32);
            $table->string('status', 16);
            $table->string('outcome', 48)->nullable();
            $table->string('solver_status', 16)->nullable();
            $table->string('failure_code', 64)->nullable();
            $table->date('reference_date');
            $table->char('input_fingerprint', 64);
            $table->char('distance_matrix_fingerprint', 64);
            $table->char('runtime_sha256', 64);
            $table->jsonb('snapshot');
            $table->jsonb('runtime_result')->nullable();
            $table->string('operational_effect', 32);
            $table->timestampTz('requested_at')->useCurrent();
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('finished_at')->nullable();

            $table->unique(['tenant_id', 'id'], 'fleet_reallocation_planning_runs_tenant_id_unique');
            $table->index(['tenant_id', 'requested_at'], 'fleet_reallocation_planning_runs_scope_date_idx');
            $table->index(['tenant_id', 'input_fingerprint'], 'fleet_reallocation_planning_runs_input_idx');
            $table->foreign(['tenant_id', 'requested_by'], 'fleet_reallocation_planning_runs_requester_fk')
                ->references(['tenant_id', 'id'])->on('users')->restrictOnDelete();
        });

        Schema::create('fleet_reallocation_recommendations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('fleet_reallocation_planning_run_id');
            $table->unsignedSmallInteger('horizon');
            $table->date('planning_date');
            $table->unsignedBigInteger('from_agency_id');
            $table->unsignedBigInteger('to_agency_id');
            $table->unsignedInteger('vehicle_units');
            $table->decimal('distance_km', 10, 3);
            $table->timestampTz('created_at')->useCurrent();

            $table->unique(
                ['fleet_reallocation_planning_run_id', 'horizon', 'from_agency_id', 'to_agency_id'],
                'fleet_reallocation_recommendations_lane_unique',
            );
            $table->index(
                ['tenant_id', 'fleet_reallocation_planning_run_id'],
                'fleet_reallocation_recommendations_scope_run_idx',
            );
            $table->foreign(
                ['tenant_id', 'fleet_reallocation_planning_run_id'],
                'fleet_reallocation_recommendations_run_fk',
            )->references(['tenant_id', 'id'])->on('fleet_reallocation_planning_runs')->restrictOnDelete();
            $table->foreign(
                ['tenant_id', 'from_agency_id'],
                'fleet_reallocation_recommendations_from_agency_fk',
            )->references(['tenant_id', 'id'])->on('agencies')->restrictOnDelete();
            $table->foreign(
                ['tenant_id', 'to_agency_id'],
                'fleet_reallocation_recommendations_to_agency_fk',
            )->references(['tenant_id', 'id'])->on('agencies')->restrictOnDelete();
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE fleet_reallocation_planning_runs
                ADD CONSTRAINT fleet_reallocation_planning_runs_contract_check
                    CHECK (
                        source_kind = 'rentfleet_operational'
                        AND status IN ('queued', 'running', 'succeeded', 'failed')
                        AND (outcome IS NULL OR outcome IN (
                            'transfers_recommended',
                            'balanced_without_transfer',
                            'insufficient_transferable_surplus'
                        ))
                        AND (solver_status IS NULL OR solver_status = 'OPTIMAL')
                        AND (failure_code IS NULL OR failure_code ~ '^[A-Z][A-Z0-9_]{2,63}$')
                        AND input_fingerprint ~ '^[0-9a-f]{64}$'
                        AND distance_matrix_fingerprint ~ '^[0-9a-f]{64}$'
                        AND runtime_sha256 ~ '^[0-9a-f]{64}$'
                        AND operational_effect = 'NO_OPERATIONAL_ACTION'
                    ),
                ADD CONSTRAINT fleet_reallocation_planning_runs_state_check
                    CHECK (
                        (status = 'queued'
                            AND started_at IS NULL AND finished_at IS NULL
                            AND outcome IS NULL AND solver_status IS NULL
                            AND failure_code IS NULL AND runtime_result IS NULL)
                        OR (status = 'running'
                            AND started_at IS NOT NULL AND finished_at IS NULL
                            AND outcome IS NULL AND solver_status IS NULL
                            AND failure_code IS NULL AND runtime_result IS NULL)
                        OR (status = 'succeeded'
                            AND started_at IS NOT NULL AND finished_at IS NOT NULL
                            AND outcome IS NOT NULL AND solver_status = 'OPTIMAL'
                            AND failure_code IS NULL AND runtime_result IS NOT NULL)
                        OR (status = 'failed'
                            AND started_at IS NOT NULL AND finished_at IS NOT NULL
                            AND outcome IS NULL AND solver_status IS NULL
                            AND failure_code IS NOT NULL AND runtime_result IS NULL)
                    );

            CREATE UNIQUE INDEX fleet_reallocation_planning_runs_active_input_unique
            ON fleet_reallocation_planning_runs (tenant_id, input_fingerprint)
            WHERE status IN ('queued', 'running');

            ALTER TABLE fleet_reallocation_recommendations
                ADD CONSTRAINT fleet_reallocation_recommendations_value_check
                    CHECK (
                        horizon BETWEEN 1 AND 7
                        AND vehicle_units > 0
                        AND distance_km > 0.000
                        AND from_agency_id <> to_agency_id
                    );

            CREATE OR REPLACE FUNCTION guard_fleet_reallocation_planning_run() RETURNS trigger
            LANGUAGE plpgsql AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    RAISE EXCEPTION 'Fleet reallocation planning runs cannot be deleted' USING ERRCODE = '23514';
                END IF;

                IF NEW.tenant_id <> OLD.tenant_id
                    OR NEW.run_id <> OLD.run_id
                    OR NEW.requested_by <> OLD.requested_by
                    OR NEW.source_kind <> OLD.source_kind
                    OR NEW.reference_date <> OLD.reference_date
                    OR NEW.input_fingerprint <> OLD.input_fingerprint
                    OR NEW.distance_matrix_fingerprint <> OLD.distance_matrix_fingerprint
                    OR NEW.runtime_sha256 <> OLD.runtime_sha256
                    OR NEW.snapshot <> OLD.snapshot
                    OR NEW.operational_effect <> OLD.operational_effect
                    OR NEW.requested_at <> OLD.requested_at THEN
                    RAISE EXCEPTION 'Fleet reallocation planning snapshot is immutable' USING ERRCODE = '23514';
                END IF;

                IF OLD.status = 'queued' AND NEW.status NOT IN ('running', 'failed') THEN
                    RAISE EXCEPTION 'Invalid queued planning transition' USING ERRCODE = '23514';
                ELSIF OLD.status = 'running' AND NEW.status NOT IN ('succeeded', 'failed') THEN
                    RAISE EXCEPTION 'Invalid running planning transition' USING ERRCODE = '23514';
                ELSIF OLD.status IN ('succeeded', 'failed') THEN
                    RAISE EXCEPTION 'Terminal planning run is immutable' USING ERRCODE = '23514';
                END IF;

                RETURN NEW;
            END;
            $$;

            CREATE TRIGGER fleet_reallocation_planning_runs_guard
            BEFORE UPDATE OR DELETE ON fleet_reallocation_planning_runs
            FOR EACH ROW EXECUTE FUNCTION guard_fleet_reallocation_planning_run();

            CREATE OR REPLACE FUNCTION guard_fleet_reallocation_recommendation() RETURNS trigger
            LANGUAGE plpgsql AS $$
            BEGIN
                IF TG_OP <> 'INSERT' THEN
                    RAISE EXCEPTION 'Fleet reallocation recommendations are append-only' USING ERRCODE = '23514';
                END IF;

                PERFORM 1
                FROM fleet_reallocation_planning_runs AS run
                WHERE run.id = NEW.fleet_reallocation_planning_run_id
                    AND run.tenant_id = NEW.tenant_id
                    AND run.status = 'running'
                    AND NEW.planning_date = run.reference_date + NEW.horizon
                FOR KEY SHARE;

                IF NOT FOUND THEN
                    RAISE EXCEPTION 'Fleet reallocation recommendation scope mismatch' USING ERRCODE = '23514';
                END IF;

                RETURN NEW;
            END;
            $$;

            CREATE TRIGGER fleet_reallocation_recommendations_guard
            BEFORE INSERT OR UPDATE OR DELETE ON fleet_reallocation_recommendations
            FOR EACH ROW EXECUTE FUNCTION guard_fleet_reallocation_recommendation();
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS fleet_reallocation_recommendations_guard ON fleet_reallocation_recommendations;
            DROP TRIGGER IF EXISTS fleet_reallocation_planning_runs_guard ON fleet_reallocation_planning_runs;
            DROP FUNCTION IF EXISTS guard_fleet_reallocation_recommendation();
            DROP FUNCTION IF EXISTS guard_fleet_reallocation_planning_run();
        SQL);

        Schema::dropIfExists('fleet_reallocation_recommendations');
        Schema::dropIfExists('fleet_reallocation_planning_runs');
    }
};
