<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rental_usage_anomaly_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('agency_id')->nullable();
            $table->uuid('run_id')->unique();
            $table->unsignedBigInteger('intelligence_dataset_export_run_id');
            $table->unsignedBigInteger('requested_by');
            $table->string('status', 16);
            $table->string('failure_code', 64)->nullable();
            $table->string('data_status', 24)->nullable();
            $table->unsignedInteger('source_row_count');
            $table->unsignedSmallInteger('minimum_rows');
            $table->unsignedSmallInteger('default_budget_basis_points');
            $table->jsonb('budget_results')->nullable();
            $table->unsignedSmallInteger('candidate_count')->nullable();
            $table->string('primary_model', 32);
            $table->string('primary_version', 16);
            $table->string('challenger_model', 32);
            $table->string('challenger_version', 16);
            $table->unsignedInteger('random_state');
            $table->char('runtime_sha256', 64);
            $table->string('compute', 8);
            $table->string('operational_effect', 32);
            $table->timestampTz('requested_at')->useCurrent();
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('finished_at')->nullable();

            $table->unique(['tenant_id', 'id'], 'rental_anomaly_runs_tenant_id_unique');
            $table->index(['tenant_id', 'agency_id', 'requested_at'], 'rental_anomaly_runs_scope_date_idx');
            $table->foreign(['tenant_id', 'agency_id'], 'rental_anomaly_runs_agency_fk')
                ->references(['tenant_id', 'id'])->on('agencies')->restrictOnDelete();
            $table->foreign(
                ['tenant_id', 'intelligence_dataset_export_run_id'],
                'rental_anomaly_runs_export_fk',
            )->references(['tenant_id', 'id'])->on('intelligence_dataset_export_runs')->restrictOnDelete();
            $table->foreign(['tenant_id', 'requested_by'], 'rental_anomaly_runs_requester_fk')
                ->references(['tenant_id', 'id'])->on('users')->restrictOnDelete();
        });

        Schema::create('rental_usage_anomaly_results', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('agency_id');
            $table->unsignedBigInteger('rental_usage_anomaly_run_id');
            $table->unsignedBigInteger('rental_contract_id');
            $table->char('row_id', 66);
            $table->char('contract_key', 66);
            $table->timestampTz('event_at');
            $table->decimal('late_hours', 20, 6);
            $table->decimal('km_per_day', 20, 6);
            $table->decimal('fuel_drop_pct', 20, 6);
            $table->decimal('primary_score', 30, 8);
            $table->unsignedInteger('primary_rank');
            $table->boolean('primary_selected_005');
            $table->boolean('primary_selected_010');
            $table->boolean('primary_selected_020');
            $table->jsonb('primary_factors');
            $table->decimal('challenger_score', 30, 8);
            $table->unsignedInteger('challenger_rank');
            $table->boolean('challenger_selected_005');
            $table->boolean('challenger_selected_010');
            $table->boolean('challenger_selected_020');
            $table->string('operational_effect', 32);
            $table->timestampTz('recorded_at')->useCurrent();

            $table->unique(['tenant_id', 'id'], 'rental_anomaly_results_tenant_id_unique');
            $table->unique(
                ['rental_usage_anomaly_run_id', 'row_id'],
                'rental_anomaly_results_run_row_unique',
            );
            $table->unique(
                ['rental_usage_anomaly_run_id', 'rental_contract_id'],
                'rental_anomaly_results_run_contract_unique',
            );
            $table->index(
                ['tenant_id', 'agency_id', 'primary_selected_010', 'primary_rank'],
                'rental_anomaly_results_review_idx',
            );
            $table->foreign(['tenant_id', 'agency_id'], 'rental_anomaly_results_agency_fk')
                ->references(['tenant_id', 'id'])->on('agencies')->restrictOnDelete();
            $table->foreign(
                ['tenant_id', 'rental_usage_anomaly_run_id'],
                'rental_anomaly_results_run_fk',
            )->references(['tenant_id', 'id'])->on('rental_usage_anomaly_runs')->restrictOnDelete();
            $table->foreign(
                ['tenant_id', 'agency_id', 'rental_contract_id'],
                'rental_anomaly_results_contract_fk',
            )->references(['tenant_id', 'agency_id', 'id'])->on('rental_contracts')->restrictOnDelete();
        });

        Schema::create('rental_usage_anomaly_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('agency_id');
            $table->unsignedBigInteger('rental_usage_anomaly_result_id');
            $table->unsignedBigInteger('reviewed_by');
            $table->string('decision', 24);
            $table->string('note', 500)->nullable();
            $table->string('effect', 32);
            $table->timestampTz('reviewed_at')->useCurrent();

            $table->index(['tenant_id', 'agency_id', 'reviewed_at'], 'rental_anomaly_reviews_scope_date_idx');
            $table->foreign(['tenant_id', 'agency_id'], 'rental_anomaly_reviews_agency_fk')
                ->references(['tenant_id', 'id'])->on('agencies')->restrictOnDelete();
            $table->foreign(
                ['tenant_id', 'rental_usage_anomaly_result_id'],
                'rental_anomaly_reviews_result_fk',
            )->references(['tenant_id', 'id'])->on('rental_usage_anomaly_results')->restrictOnDelete();
            $table->foreign(['tenant_id', 'reviewed_by'], 'rental_anomaly_reviews_reviewer_fk')
                ->references(['tenant_id', 'id'])->on('users')->restrictOnDelete();
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE rental_usage_anomaly_runs
                ADD CONSTRAINT rental_anomaly_runs_contract_check
                    CHECK (
                        status IN ('queued', 'running', 'succeeded', 'failed')
                        AND source_row_count BETWEEN 0 AND 10000
                        AND minimum_rows = 200
                        AND default_budget_basis_points = 100
                        AND primary_model = 'robust_mad_top2'
                        AND primary_version = '1.0.0'
                        AND challenger_model = 'isolation_forest'
                        AND challenger_version = '1.0.0'
                        AND random_state = 20260824
                        AND runtime_sha256 ~ '^[a-f0-9]{64}$'
                        AND compute = 'CPU'
                        AND operational_effect = 'NO_OPERATIONAL_ACTION'
                        AND (failure_code IS NULL OR failure_code ~ '^[A-Z][A-Z0-9_]{2,63}$')
                    ),
                ADD CONSTRAINT rental_anomaly_runs_state_check
                    CHECK (
                        (status = 'queued'
                            AND started_at IS NULL
                            AND finished_at IS NULL
                            AND failure_code IS NULL
                            AND data_status IS NULL
                            AND budget_results IS NULL
                            AND candidate_count IS NULL)
                        OR (status = 'running'
                            AND started_at IS NOT NULL
                            AND finished_at IS NULL
                            AND failure_code IS NULL
                            AND data_status IS NULL
                            AND budget_results IS NULL
                            AND candidate_count IS NULL)
                        OR (status = 'succeeded'
                            AND started_at IS NOT NULL
                            AND finished_at IS NOT NULL
                            AND failure_code IS NULL
                            AND data_status IN ('usable', 'insufficient_data')
                            AND jsonb_typeof(budget_results) = 'array'
                            AND candidate_count IS NOT NULL
                            AND ((data_status = 'usable'
                                    AND source_row_count >= minimum_rows
                                    AND jsonb_array_length(budget_results) = 3
                                    AND candidate_count BETWEEN 1 AND 400)
                                OR (data_status = 'insufficient_data'
                                    AND source_row_count < minimum_rows
                                    AND budget_results = '[]'::jsonb
                                    AND candidate_count = 0)))
                        OR (status = 'failed'
                            AND started_at IS NOT NULL
                            AND finished_at IS NOT NULL
                            AND failure_code IS NOT NULL
                            AND data_status IS NULL
                            AND budget_results IS NULL
                            AND candidate_count IS NULL)
                    );

            CREATE UNIQUE INDEX rental_anomaly_runs_one_active_per_export
            ON rental_usage_anomaly_runs (tenant_id, intelligence_dataset_export_run_id)
            WHERE status IN ('queued', 'running');

            CREATE OR REPLACE FUNCTION guard_rental_anomaly_run_insert() RETURNS trigger
            LANGUAGE plpgsql AS $$
            DECLARE
                export_agency_id bigint;
                export_row_count integer;
            BEGIN
                SELECT export.agency_id, export.row_count
                INTO export_agency_id, export_row_count
                FROM intelligence_dataset_export_runs AS export
                WHERE export.id = NEW.intelligence_dataset_export_run_id
                    AND export.tenant_id = NEW.tenant_id
                FOR KEY SHARE;
                IF NOT FOUND
                    OR export_agency_id IS DISTINCT FROM NEW.agency_id
                    OR export_row_count <> NEW.source_row_count THEN
                    RAISE EXCEPTION 'Rental usage anomaly export scope or row count mismatch' USING ERRCODE = '23514';
                END IF;

                RETURN NEW;
            END;
            $$;

            CREATE TRIGGER rental_anomaly_runs_insert_guard
            BEFORE INSERT ON rental_usage_anomaly_runs
            FOR EACH ROW EXECUTE FUNCTION guard_rental_anomaly_run_insert();

            CREATE OR REPLACE FUNCTION guard_rental_anomaly_run_transition() RETURNS trigger
            LANGUAGE plpgsql AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    RAISE EXCEPTION 'Rental usage anomaly runs cannot be deleted' USING ERRCODE = '23514';
                END IF;

                IF NEW.tenant_id <> OLD.tenant_id
                    OR NEW.agency_id IS DISTINCT FROM OLD.agency_id
                    OR NEW.run_id <> OLD.run_id
                    OR NEW.intelligence_dataset_export_run_id <> OLD.intelligence_dataset_export_run_id
                    OR NEW.requested_by <> OLD.requested_by
                    OR NEW.source_row_count <> OLD.source_row_count
                    OR NEW.minimum_rows <> OLD.minimum_rows
                    OR NEW.default_budget_basis_points <> OLD.default_budget_basis_points
                    OR NEW.primary_model <> OLD.primary_model
                    OR NEW.primary_version <> OLD.primary_version
                    OR NEW.challenger_model <> OLD.challenger_model
                    OR NEW.challenger_version <> OLD.challenger_version
                    OR NEW.random_state <> OLD.random_state
                    OR NEW.runtime_sha256 <> OLD.runtime_sha256
                    OR NEW.compute <> OLD.compute
                    OR NEW.operational_effect <> OLD.operational_effect
                    OR NEW.requested_at <> OLD.requested_at THEN
                    RAISE EXCEPTION 'Rental usage anomaly run identity is immutable' USING ERRCODE = '23514';
                END IF;

                IF OLD.status = 'queued' AND NEW.status NOT IN ('running', 'failed') THEN
                    RAISE EXCEPTION 'Invalid queued rental anomaly transition' USING ERRCODE = '23514';
                ELSIF OLD.status = 'running' AND NEW.status NOT IN ('succeeded', 'failed') THEN
                    RAISE EXCEPTION 'Invalid running rental anomaly transition' USING ERRCODE = '23514';
                ELSIF OLD.status IN ('succeeded', 'failed') THEN
                    RAISE EXCEPTION 'Terminal rental usage anomaly run is immutable' USING ERRCODE = '23514';
                END IF;

                RETURN NEW;
            END;
            $$;

            CREATE TRIGGER rental_anomaly_runs_transition_guard
            BEFORE UPDATE OR DELETE ON rental_usage_anomaly_runs
            FOR EACH ROW EXECUTE FUNCTION guard_rental_anomaly_run_transition();

            ALTER TABLE rental_usage_anomaly_results
                ADD CONSTRAINT rental_anomaly_results_contract_check
                    CHECK (
                        row_id ~ '^r_[0-9a-f]{64}$'
                        AND contract_key ~ '^c_[0-9a-f]{64}$'
                        AND late_hours BETWEEN 0 AND 1000000000
                        AND km_per_day BETWEEN 0 AND 1000000000
                        AND fuel_drop_pct BETWEEN 0 AND 1000000000
                        AND primary_score >= 0
                        AND challenger_score >= 0
                        AND primary_rank BETWEEN 1 AND 10000
                        AND challenger_rank BETWEEN 1 AND 10000
                        AND (NOT primary_selected_005 OR primary_selected_010)
                        AND (NOT primary_selected_010 OR primary_selected_020)
                        AND (NOT challenger_selected_005 OR challenger_selected_010)
                        AND (NOT challenger_selected_010 OR challenger_selected_020)
                        AND (primary_selected_020 OR challenger_selected_020)
                        AND jsonb_typeof(primary_factors) = 'array'
                        AND jsonb_array_length(primary_factors) = 2
                        AND operational_effect = 'NO_OPERATIONAL_ACTION'
                    );

            CREATE OR REPLACE FUNCTION guard_rental_anomaly_result() RETURNS trigger
            LANGUAGE plpgsql AS $$
            DECLARE
                run_agency_id bigint;
                run_status text;
            BEGIN
                IF TG_OP <> 'INSERT' THEN
                    RAISE EXCEPTION 'Rental usage anomaly results are append-only' USING ERRCODE = '23514';
                END IF;

                SELECT run.agency_id, run.status
                INTO run_agency_id, run_status
                FROM rental_usage_anomaly_runs AS run
                WHERE run.id = NEW.rental_usage_anomaly_run_id
                    AND run.tenant_id = NEW.tenant_id
                FOR KEY SHARE;
                IF NOT FOUND OR run_status <> 'running'
                    OR (run_agency_id IS NOT NULL AND run_agency_id <> NEW.agency_id) THEN
                    RAISE EXCEPTION 'Rental usage anomaly result scope or state mismatch' USING ERRCODE = '23514';
                END IF;

                RETURN NEW;
            END;
            $$;

            CREATE TRIGGER rental_anomaly_results_guard
            BEFORE INSERT OR UPDATE OR DELETE ON rental_usage_anomaly_results
            FOR EACH ROW EXECUTE FUNCTION guard_rental_anomaly_result();

            ALTER TABLE rental_usage_anomaly_reviews
                ADD CONSTRAINT rental_anomaly_reviews_contract_check
                    CHECK (
                        decision IN ('follow_up', 'dismissed', 'needs_information')
                        AND effect = 'NO_OPERATIONAL_ACTION'
                        AND (note IS NULL OR char_length(note) BETWEEN 1 AND 500)
                    );

            CREATE OR REPLACE FUNCTION guard_rental_anomaly_review() RETURNS trigger
            LANGUAGE plpgsql AS $$
            DECLARE
                result_agency_id bigint;
                result_is_primary boolean;
                run_status text;
            BEGIN
                IF TG_OP <> 'INSERT' THEN
                    RAISE EXCEPTION 'Rental usage anomaly reviews are append-only' USING ERRCODE = '23514';
                END IF;

                SELECT result.agency_id, result.primary_selected_020, run.status
                INTO result_agency_id, result_is_primary, run_status
                FROM rental_usage_anomaly_results AS result
                JOIN rental_usage_anomaly_runs AS run
                    ON run.id = result.rental_usage_anomaly_run_id
                    AND run.tenant_id = result.tenant_id
                WHERE result.id = NEW.rental_usage_anomaly_result_id
                    AND result.tenant_id = NEW.tenant_id
                FOR KEY SHARE OF result, run;
                IF NOT FOUND OR result_agency_id <> NEW.agency_id
                    OR result_is_primary IS NOT TRUE OR run_status <> 'succeeded' THEN
                    RAISE EXCEPTION 'Rental usage anomaly review scope or state mismatch' USING ERRCODE = '23514';
                END IF;

                RETURN NEW;
            END;
            $$;

            CREATE TRIGGER rental_anomaly_reviews_guard
            BEFORE INSERT OR UPDATE OR DELETE ON rental_usage_anomaly_reviews
            FOR EACH ROW EXECUTE FUNCTION guard_rental_anomaly_review();
        SQL);

        DB::table('permissions')->insertOrIgnore([
            'slug' => 'prediction.anomaly.review',
            'name' => 'Analyser et revoir les usages de location atypiques',
            'group' => 'prediction',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('permissions')->where('slug', 'prediction.anomaly.review')->update([
            'name' => 'Analyser et revoir les usages de location atypiques',
            'group' => 'prediction',
            'updated_at' => now(),
        ]);

        $permissionId = DB::table('permissions')->where('slug', 'prediction.anomaly.review')->value('id');
        $roleIds = DB::table('roles')
            ->whereNull('tenant_id')
            ->whereIn('slug', ['tenant-owner', 'agency-manager', 'fleet-manager'])
            ->pluck('id');
        foreach ($roleIds as $roleId) {
            DB::table('permission_role')->insertOrIgnore([
                'role_id' => $roleId,
                'permission_id' => $permissionId,
            ]);
        }
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS rental_anomaly_reviews_guard ON rental_usage_anomaly_reviews;
            DROP FUNCTION IF EXISTS guard_rental_anomaly_review();
            DROP TRIGGER IF EXISTS rental_anomaly_results_guard ON rental_usage_anomaly_results;
            DROP FUNCTION IF EXISTS guard_rental_anomaly_result();
            DROP TRIGGER IF EXISTS rental_anomaly_runs_transition_guard ON rental_usage_anomaly_runs;
            DROP FUNCTION IF EXISTS guard_rental_anomaly_run_transition();
            DROP TRIGGER IF EXISTS rental_anomaly_runs_insert_guard ON rental_usage_anomaly_runs;
            DROP FUNCTION IF EXISTS guard_rental_anomaly_run_insert();
        SQL);

        Schema::dropIfExists('rental_usage_anomaly_reviews');
        Schema::dropIfExists('rental_usage_anomaly_results');
        Schema::dropIfExists('rental_usage_anomaly_runs');

        // Conservative rollback: the permission and later delegations remain.
    }
};
