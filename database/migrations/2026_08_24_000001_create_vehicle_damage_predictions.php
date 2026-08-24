<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vehicle_inspections', function (Blueprint $table) {
            $table->unique(
                ['tenant_id', 'agency_id', 'vehicle_id', 'id'],
                'vehicle_inspections_damage_scope_unique',
            );
        });

        Schema::create('vehicle_damage_prediction_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('agency_id');
            $table->uuid('run_id')->unique();
            $table->unsignedBigInteger('vehicle_inspection_id');
            $table->unsignedBigInteger('vehicle_id');
            $table->unsignedBigInteger('requested_by');
            $table->string('status', 16);
            $table->string('failure_code', 64)->nullable();
            $table->string('input_mime', 64);
            $table->string('input_extension', 8);
            $table->unsignedBigInteger('input_bytes');
            $table->char('input_sha256', 64);
            $table->string('input_stored_path', 512);
            $table->unsignedSmallInteger('input_width');
            $table->unsignedSmallInteger('input_height');
            $table->string('quality_status', 16)->nullable();
            $table->jsonb('quality_reasons')->nullable();
            $table->jsonb('quality_metrics')->nullable();
            $table->unsignedSmallInteger('evaluated_patches')->nullable();
            $table->decimal('max_probability_damage', 8, 7)->nullable();
            $table->boolean('suggested_damage')->nullable();
            $table->jsonb('candidate_regions')->nullable();
            $table->string('model_name', 64);
            $table->string('model_version', 48);
            $table->char('model_artifact_sha256', 64);
            $table->char('model_card_sha256', 64);
            $table->decimal('decision_threshold', 4, 3);
            $table->string('operational_effect', 32);
            $table->timestampTz('requested_at')->useCurrent();
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('finished_at')->nullable();

            $table->unique(['tenant_id', 'id'], 'vehicle_damage_runs_tenant_id_unique');
            $table->index(
                ['tenant_id', 'agency_id', 'requested_at'],
                'vehicle_damage_runs_scope_date_idx',
            );
            $table->foreign(['tenant_id', 'agency_id'], 'vehicle_damage_runs_agency_fk')
                ->references(['tenant_id', 'id'])->on('agencies')->restrictOnDelete();
            $table->foreign(
                ['tenant_id', 'agency_id', 'vehicle_id', 'vehicle_inspection_id'],
                'vehicle_damage_runs_inspection_scope_fk',
            )->references(['tenant_id', 'agency_id', 'vehicle_id', 'id'])
                ->on('vehicle_inspections')->restrictOnDelete();
            $table->foreign(['tenant_id', 'requested_by'], 'vehicle_damage_runs_requester_fk')
                ->references(['tenant_id', 'id'])->on('users')->restrictOnDelete();
        });

        Schema::create('vehicle_damage_prediction_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('agency_id');
            $table->unsignedBigInteger('vehicle_damage_prediction_run_id');
            $table->unsignedBigInteger('reviewed_by');
            $table->string('decision', 24);
            $table->string('note', 500)->nullable();
            $table->string('effect', 32);
            $table->timestampTz('reviewed_at')->useCurrent();

            $table->unique(
                'vehicle_damage_prediction_run_id',
                'vehicle_damage_reviews_one_per_run',
            );
            $table->index(
                ['tenant_id', 'agency_id', 'reviewed_at'],
                'vehicle_damage_reviews_scope_date_idx',
            );
            $table->foreign(['tenant_id', 'agency_id'], 'vehicle_damage_reviews_agency_fk')
                ->references(['tenant_id', 'id'])->on('agencies')->restrictOnDelete();
            $table->foreign(
                ['tenant_id', 'vehicle_damage_prediction_run_id'],
                'vehicle_damage_reviews_run_fk',
            )->references(['tenant_id', 'id'])->on('vehicle_damage_prediction_runs')->restrictOnDelete();
            $table->foreign(['tenant_id', 'reviewed_by'], 'vehicle_damage_reviews_reviewer_fk')
                ->references(['tenant_id', 'id'])->on('users')->restrictOnDelete();
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE vehicle_damage_prediction_runs
                ADD CONSTRAINT vehicle_damage_runs_contract_check
                    CHECK (
                        status IN ('queued', 'running', 'succeeded', 'failed')
                        AND input_mime = 'image/jpeg'
                        AND input_extension = 'jpg'
                        AND input_bytes BETWEEN 1 AND 8388608
                        AND input_width BETWEEN 1 AND 2048
                        AND input_height BETWEEN 1 AND 2048
                        AND input_sha256 ~ '^[a-f0-9]{64}$'
                        AND input_stored_path = (
                            'intelligence/vehicle-damage/inputs/'
                            || tenant_id::text
                            || '/'
                            || run_id::text
                            || '.jpg'
                        )
                        AND model_name = 'rentfleet_vehicle_damage_efficientnetv2s'
                        AND model_version = 's7-damage-efficientnetv2s-v1.1'
                        AND model_artifact_sha256 ~ '^[a-f0-9]{64}$'
                        AND model_card_sha256 ~ '^[a-f0-9]{64}$'
                        AND decision_threshold = 0.495
                        AND operational_effect = 'NO_OPERATIONAL_ACTION'
                        AND (failure_code IS NULL OR failure_code ~ '^[A-Z][A-Z0-9_]{2,63}$')
                    ),
                ADD CONSTRAINT vehicle_damage_runs_state_check
                    CHECK (
                        (status = 'queued'
                            AND started_at IS NULL
                            AND finished_at IS NULL
                            AND failure_code IS NULL
                            AND quality_status IS NULL
                            AND quality_reasons IS NULL
                            AND quality_metrics IS NULL
                            AND evaluated_patches IS NULL
                            AND max_probability_damage IS NULL
                            AND suggested_damage IS NULL
                            AND candidate_regions IS NULL)
                        OR (status = 'running'
                            AND started_at IS NOT NULL
                            AND finished_at IS NULL
                            AND failure_code IS NULL
                            AND quality_status IS NULL
                            AND quality_reasons IS NULL
                            AND quality_metrics IS NULL
                            AND evaluated_patches IS NULL
                            AND max_probability_damage IS NULL
                            AND suggested_damage IS NULL
                            AND candidate_regions IS NULL)
                        OR (status = 'succeeded'
                            AND started_at IS NOT NULL
                            AND finished_at IS NOT NULL
                            AND failure_code IS NULL
                            AND quality_status IN ('usable', 'abstained')
                            AND jsonb_typeof(quality_reasons) = 'array'
                            AND jsonb_typeof(quality_metrics) = 'object'
                            AND jsonb_typeof(candidate_regions) = 'array'
                            AND evaluated_patches BETWEEN 0 AND 64
                            AND jsonb_array_length(candidate_regions) <= 12
                            AND (
                                (quality_status = 'abstained'
                                    AND jsonb_array_length(quality_reasons) >= 1
                                    AND evaluated_patches = 0
                                    AND max_probability_damage IS NULL
                                    AND suggested_damage IS NULL
                                    AND jsonb_array_length(candidate_regions) = 0)
                                OR (quality_status = 'usable'
                                    AND quality_reasons = '[]'::jsonb
                                    AND evaluated_patches >= 1
                                    AND max_probability_damage BETWEEN 0 AND 1
                                    AND suggested_damage IS NOT NULL
                                    AND (
                                        (suggested_damage = true
                                            AND max_probability_damage >= decision_threshold
                                            AND jsonb_array_length(candidate_regions) >= 1)
                                        OR (suggested_damage = false
                                            AND max_probability_damage < decision_threshold
                                            AND jsonb_array_length(candidate_regions) = 0)
                                    ))
                            ))
                        OR (status = 'failed'
                            AND started_at IS NOT NULL
                            AND finished_at IS NOT NULL
                            AND failure_code IS NOT NULL
                            AND quality_status IS NULL
                            AND quality_reasons IS NULL
                            AND quality_metrics IS NULL
                            AND evaluated_patches IS NULL
                            AND max_probability_damage IS NULL
                            AND suggested_damage IS NULL
                            AND candidate_regions IS NULL)
                    );

            CREATE UNIQUE INDEX vehicle_damage_runs_one_active_per_inspection
            ON vehicle_damage_prediction_runs (tenant_id, vehicle_inspection_id)
            WHERE status IN ('queued', 'running');

            CREATE OR REPLACE FUNCTION guard_vehicle_damage_run_transition() RETURNS trigger
            LANGUAGE plpgsql AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    RAISE EXCEPTION 'Vehicle damage prediction runs cannot be deleted' USING ERRCODE = '23514';
                END IF;

                IF NEW.tenant_id <> OLD.tenant_id
                    OR NEW.agency_id <> OLD.agency_id
                    OR NEW.run_id <> OLD.run_id
                    OR NEW.vehicle_inspection_id <> OLD.vehicle_inspection_id
                    OR NEW.vehicle_id <> OLD.vehicle_id
                    OR NEW.requested_by <> OLD.requested_by
                    OR NEW.input_mime <> OLD.input_mime
                    OR NEW.input_extension <> OLD.input_extension
                    OR NEW.input_bytes <> OLD.input_bytes
                    OR NEW.input_sha256 <> OLD.input_sha256
                    OR NEW.input_stored_path <> OLD.input_stored_path
                    OR NEW.input_width <> OLD.input_width
                    OR NEW.input_height <> OLD.input_height
                    OR NEW.model_name <> OLD.model_name
                    OR NEW.model_version <> OLD.model_version
                    OR NEW.model_artifact_sha256 <> OLD.model_artifact_sha256
                    OR NEW.model_card_sha256 <> OLD.model_card_sha256
                    OR NEW.decision_threshold <> OLD.decision_threshold
                    OR NEW.operational_effect <> OLD.operational_effect
                    OR NEW.requested_at <> OLD.requested_at THEN
                    RAISE EXCEPTION 'Vehicle damage prediction identity is immutable' USING ERRCODE = '23514';
                END IF;

                IF OLD.status = 'queued' AND NEW.status NOT IN ('running', 'failed') THEN
                    RAISE EXCEPTION 'Invalid queued vehicle damage transition' USING ERRCODE = '23514';
                ELSIF OLD.status = 'running' AND NEW.status NOT IN ('succeeded', 'failed') THEN
                    RAISE EXCEPTION 'Invalid running vehicle damage transition' USING ERRCODE = '23514';
                ELSIF OLD.status IN ('succeeded', 'failed') THEN
                    RAISE EXCEPTION 'Terminal vehicle damage prediction is immutable' USING ERRCODE = '23514';
                END IF;

                RETURN NEW;
            END;
            $$;

            CREATE TRIGGER vehicle_damage_runs_transition_guard
            BEFORE UPDATE OR DELETE ON vehicle_damage_prediction_runs
            FOR EACH ROW EXECUTE FUNCTION guard_vehicle_damage_run_transition();

            ALTER TABLE vehicle_damage_prediction_reviews
                ADD CONSTRAINT vehicle_damage_reviews_contract_check
                    CHECK (
                        decision IN ('confirmed', 'rejected', 'new_photo_required')
                        AND effect = 'NO_OPERATIONAL_ACTION'
                        AND (note IS NULL OR char_length(note) BETWEEN 1 AND 500)
                    );

            CREATE OR REPLACE FUNCTION guard_vehicle_damage_review() RETURNS trigger
            LANGUAGE plpgsql AS $$
            DECLARE
                prediction_suggested_damage boolean;
                prediction_quality_status text;
            BEGIN
                IF TG_OP <> 'INSERT' THEN
                    RAISE EXCEPTION 'Vehicle damage prediction reviews are append-only' USING ERRCODE = '23514';
                END IF;

                SELECT run.suggested_damage, run.quality_status
                INTO prediction_suggested_damage, prediction_quality_status
                FROM vehicle_damage_prediction_runs AS run
                WHERE run.id = NEW.vehicle_damage_prediction_run_id
                    AND run.tenant_id = NEW.tenant_id
                    AND run.agency_id = NEW.agency_id
                    AND run.status = 'succeeded'
                FOR KEY SHARE;
                IF NOT FOUND THEN
                    RAISE EXCEPTION 'Vehicle damage review scope or state mismatch' USING ERRCODE = '23514';
                END IF;
                IF NEW.decision = 'confirmed'
                    AND (prediction_suggested_damage IS NOT TRUE OR prediction_quality_status <> 'usable') THEN
                    RAISE EXCEPTION 'Only a usable damage candidate can be confirmed' USING ERRCODE = '23514';
                END IF;

                RETURN NEW;
            END;
            $$;

            CREATE TRIGGER vehicle_damage_reviews_guard
            BEFORE INSERT OR UPDATE OR DELETE ON vehicle_damage_prediction_reviews
            FOR EACH ROW EXECUTE FUNCTION guard_vehicle_damage_review();
        SQL);

        DB::table('permissions')->insertOrIgnore([
            'slug' => 'prediction.damage.review',
            'name' => 'Analyser et revoir les zones de dommage d’un retour',
            'group' => 'prediction',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('permissions')->where('slug', 'prediction.damage.review')->update([
            'name' => 'Analyser et revoir les zones de dommage d’un retour',
            'group' => 'prediction',
            'updated_at' => now(),
        ]);

        $permissionId = DB::table('permissions')
            ->where('slug', 'prediction.damage.review')
            ->value('id');
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
            DROP TRIGGER IF EXISTS vehicle_damage_reviews_guard ON vehicle_damage_prediction_reviews;
            DROP FUNCTION IF EXISTS guard_vehicle_damage_review();
            DROP TRIGGER IF EXISTS vehicle_damage_runs_transition_guard ON vehicle_damage_prediction_runs;
            DROP FUNCTION IF EXISTS guard_vehicle_damage_run_transition();
        SQL);

        Schema::dropIfExists('vehicle_damage_prediction_reviews');
        Schema::dropIfExists('vehicle_damage_prediction_runs');
        Schema::table('vehicle_inspections', function (Blueprint $table) {
            $table->dropUnique('vehicle_inspections_damage_scope_unique');
        });

        // Conservative rollback: the permission and later delegations remain.
    }
};
