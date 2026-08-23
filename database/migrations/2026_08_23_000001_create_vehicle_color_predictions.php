<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vehicle_color_prediction_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('agency_id');
            $table->uuid('run_id')->unique();
            $table->unsignedBigInteger('vehicle_id');
            $table->unsignedBigInteger('requested_by');
            $table->string('status', 16);
            $table->string('failure_code', 64)->nullable();
            $table->string('input_mime', 64);
            $table->string('input_extension', 8);
            $table->unsignedBigInteger('input_bytes');
            $table->char('input_sha256', 64);
            $table->string('input_stored_path', 512);
            $table->string('suggested_color', 16)->nullable();
            $table->decimal('confidence', 8, 7)->nullable();
            $table->boolean('model_accepted')->nullable();
            $table->jsonb('probabilities')->nullable();
            $table->string('model_name', 64);
            $table->string('model_version', 32);
            $table->char('model_artifact_sha256', 64);
            $table->char('metadata_sha256', 64);
            $table->decimal('accepted_threshold', 4, 3);
            $table->string('operational_effect', 32);
            $table->timestampTz('requested_at')->useCurrent();
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('finished_at')->nullable();

            $table->unique(['tenant_id', 'id'], 'vehicle_color_runs_tenant_id_unique');
            $table->index(
                ['tenant_id', 'agency_id', 'requested_at'],
                'vehicle_color_runs_scope_date_idx',
            );
            $table->foreign(['tenant_id', 'agency_id'], 'vehicle_color_runs_agency_fk')
                ->references(['tenant_id', 'id'])->on('agencies')->restrictOnDelete();
            $table->foreign(['tenant_id', 'vehicle_id'], 'vehicle_color_runs_vehicle_fk')
                ->references(['tenant_id', 'id'])->on('vehicles')->restrictOnDelete();
            $table->foreign(['tenant_id', 'requested_by'], 'vehicle_color_runs_requester_fk')
                ->references(['tenant_id', 'id'])->on('users')->restrictOnDelete();
        });

        Schema::create('vehicle_color_prediction_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('agency_id');
            $table->unsignedBigInteger('vehicle_color_prediction_run_id');
            $table->unsignedBigInteger('reviewed_by');
            $table->string('decision', 16);
            $table->string('note', 500)->nullable();
            $table->string('effect', 32);
            $table->timestampTz('reviewed_at')->useCurrent();

            $table->unique(
                'vehicle_color_prediction_run_id',
                'vehicle_color_reviews_one_per_run',
            );
            $table->index(
                ['tenant_id', 'agency_id', 'reviewed_at'],
                'vehicle_color_reviews_scope_date_idx',
            );
            $table->foreign(['tenant_id', 'agency_id'], 'vehicle_color_reviews_agency_fk')
                ->references(['tenant_id', 'id'])->on('agencies')->restrictOnDelete();
            $table->foreign(
                ['tenant_id', 'vehicle_color_prediction_run_id'],
                'vehicle_color_reviews_run_fk',
            )->references(['tenant_id', 'id'])->on('vehicle_color_prediction_runs')->restrictOnDelete();
            $table->foreign(['tenant_id', 'reviewed_by'], 'vehicle_color_reviews_reviewer_fk')
                ->references(['tenant_id', 'id'])->on('users')->restrictOnDelete();
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE vehicle_color_prediction_runs
                ADD CONSTRAINT vehicle_color_runs_contract_check
                    CHECK (
                        status IN ('queued', 'running', 'succeeded', 'failed')
                        AND input_mime IN ('image/jpeg', 'image/png', 'image/webp')
                        AND input_extension IN ('jpg', 'jpeg', 'png', 'webp')
                        AND input_bytes BETWEEN 1 AND 8388608
                        AND input_sha256 ~ '^[a-f0-9]{64}$'
                        AND input_stored_path LIKE 'intelligence/color-v8/inputs/%'
                        AND model_name = 'vehicle_color_mobilenet_v3_large'
                        AND model_version = 's7-color-v8.0.0'
                        AND model_artifact_sha256 = '5ec7757a7bafda0abd45685dd8e1178e5b6b79220ff61b6018398d00f2e86a76'
                        AND metadata_sha256 = '661b0dcaa9b66fc69a2d8ba55eb21ec806e66c05d86c06ef4b2c5e7ff71901e6'
                        AND accepted_threshold = 0.977
                        AND operational_effect = 'NO_OPERATIONAL_ACTION'
                        AND (failure_code IS NULL OR failure_code ~ '^[A-Z][A-Z0-9_]{2,63}$')
                    ),
                ADD CONSTRAINT vehicle_color_runs_state_check
                    CHECK (
                        (status = 'queued'
                            AND started_at IS NULL
                            AND finished_at IS NULL
                            AND failure_code IS NULL
                            AND suggested_color IS NULL
                            AND confidence IS NULL
                            AND model_accepted IS NULL
                            AND probabilities IS NULL)
                        OR (status = 'running'
                            AND started_at IS NOT NULL
                            AND finished_at IS NULL
                            AND failure_code IS NULL
                            AND suggested_color IS NULL
                            AND confidence IS NULL
                            AND model_accepted IS NULL
                            AND probabilities IS NULL)
                        OR (status = 'succeeded'
                            AND started_at IS NOT NULL
                            AND finished_at IS NOT NULL
                            AND failure_code IS NULL
                            AND suggested_color IN ('black', 'blue', 'gray', 'green', 'orange', 'red', 'white', 'yellow')
                            AND confidence BETWEEN 0 AND 1
                            AND model_accepted IS NOT NULL
                            AND jsonb_typeof(probabilities) = 'object')
                        OR (status = 'failed'
                            AND started_at IS NOT NULL
                            AND finished_at IS NOT NULL
                            AND failure_code IS NOT NULL
                            AND suggested_color IS NULL
                            AND confidence IS NULL
                            AND model_accepted IS NULL
                            AND probabilities IS NULL)
                    );

            CREATE UNIQUE INDEX vehicle_color_runs_one_active_per_vehicle
            ON vehicle_color_prediction_runs (tenant_id, vehicle_id)
            WHERE status IN ('queued', 'running');

            CREATE OR REPLACE FUNCTION guard_vehicle_color_run_transition() RETURNS trigger
            LANGUAGE plpgsql AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    RAISE EXCEPTION 'Vehicle color prediction runs cannot be deleted' USING ERRCODE = '23514';
                END IF;

                IF NEW.tenant_id <> OLD.tenant_id
                    OR NEW.agency_id <> OLD.agency_id
                    OR NEW.run_id <> OLD.run_id
                    OR NEW.vehicle_id <> OLD.vehicle_id
                    OR NEW.requested_by <> OLD.requested_by
                    OR NEW.input_mime <> OLD.input_mime
                    OR NEW.input_extension <> OLD.input_extension
                    OR NEW.input_bytes <> OLD.input_bytes
                    OR NEW.input_sha256 <> OLD.input_sha256
                    OR NEW.input_stored_path <> OLD.input_stored_path
                    OR NEW.model_name <> OLD.model_name
                    OR NEW.model_version <> OLD.model_version
                    OR NEW.model_artifact_sha256 <> OLD.model_artifact_sha256
                    OR NEW.metadata_sha256 <> OLD.metadata_sha256
                    OR NEW.accepted_threshold <> OLD.accepted_threshold
                    OR NEW.operational_effect <> OLD.operational_effect
                    OR NEW.requested_at <> OLD.requested_at THEN
                    RAISE EXCEPTION 'Vehicle color prediction identity is immutable' USING ERRCODE = '23514';
                END IF;

                IF OLD.status = 'queued' AND NEW.status NOT IN ('running', 'failed') THEN
                    RAISE EXCEPTION 'Invalid queued vehicle color transition' USING ERRCODE = '23514';
                ELSIF OLD.status = 'running' AND NEW.status NOT IN ('succeeded', 'failed') THEN
                    RAISE EXCEPTION 'Invalid running vehicle color transition' USING ERRCODE = '23514';
                ELSIF OLD.status IN ('succeeded', 'failed') THEN
                    RAISE EXCEPTION 'Terminal vehicle color prediction is immutable' USING ERRCODE = '23514';
                END IF;

                RETURN NEW;
            END;
            $$;

            CREATE TRIGGER vehicle_color_runs_transition_guard
            BEFORE UPDATE OR DELETE ON vehicle_color_prediction_runs
            FOR EACH ROW EXECUTE FUNCTION guard_vehicle_color_run_transition();

            ALTER TABLE vehicle_color_prediction_reviews
                ADD CONSTRAINT vehicle_color_reviews_contract_check
                    CHECK (
                        decision IN ('accepted', 'rejected', 'ignored')
                        AND effect = 'NO_OPERATIONAL_ACTION'
                        AND (note IS NULL OR char_length(note) BETWEEN 1 AND 500)
                    );

            CREATE OR REPLACE FUNCTION guard_vehicle_color_review() RETURNS trigger
            LANGUAGE plpgsql AS $$
            DECLARE
                prediction_model_accepted boolean;
            BEGIN
                IF TG_OP <> 'INSERT' THEN
                    RAISE EXCEPTION 'Vehicle color prediction reviews are append-only' USING ERRCODE = '23514';
                END IF;

                SELECT run.model_accepted
                INTO prediction_model_accepted
                FROM vehicle_color_prediction_runs AS run
                WHERE run.id = NEW.vehicle_color_prediction_run_id
                    AND run.tenant_id = NEW.tenant_id
                    AND run.agency_id = NEW.agency_id
                    AND run.status = 'succeeded'
                FOR KEY SHARE;
                IF NOT FOUND THEN
                    RAISE EXCEPTION 'Vehicle color review scope or state mismatch' USING ERRCODE = '23514';
                END IF;
                IF NEW.decision = 'accepted' AND prediction_model_accepted IS NOT TRUE THEN
                    RAISE EXCEPTION 'An abstained vehicle color cannot be accepted' USING ERRCODE = '23514';
                END IF;

                RETURN NEW;
            END;
            $$;

            CREATE TRIGGER vehicle_color_reviews_guard
            BEFORE INSERT OR UPDATE OR DELETE ON vehicle_color_prediction_reviews
            FOR EACH ROW EXECUTE FUNCTION guard_vehicle_color_review();
        SQL);

        DB::table('permissions')->insertOrIgnore([
            'slug' => 'prediction.color.review',
            'name' => 'Analyser et revoir la couleur d’un véhicule',
            'group' => 'prediction',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('permissions')->where('slug', 'prediction.color.review')->update([
            'name' => 'Analyser et revoir la couleur d’un véhicule',
            'group' => 'prediction',
            'updated_at' => now(),
        ]);

        $permissionId = DB::table('permissions')
            ->where('slug', 'prediction.color.review')
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
            DROP TRIGGER IF EXISTS vehicle_color_reviews_guard ON vehicle_color_prediction_reviews;
            DROP FUNCTION IF EXISTS guard_vehicle_color_review();
            DROP TRIGGER IF EXISTS vehicle_color_runs_transition_guard ON vehicle_color_prediction_runs;
            DROP FUNCTION IF EXISTS guard_vehicle_color_run_transition();
        SQL);

        Schema::dropIfExists('vehicle_color_prediction_reviews');
        Schema::dropIfExists('vehicle_color_prediction_runs');

        // Conservative rollback: the permission and later delegations remain.
    }
};
