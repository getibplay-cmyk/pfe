<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vehicle_plate_prediction_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('agency_id');
            $table->uuid('run_id')->unique();
            $table->unsignedBigInteger('vehicle_id');
            $table->unsignedBigInteger('requested_by');
            $table->string('status', 16);
            $table->string('failure_code', 64)->nullable();
            $table->string('input_kind', 24);
            $table->string('input_mime', 64);
            $table->string('input_extension', 8);
            $table->unsignedBigInteger('input_bytes');
            $table->unsignedSmallInteger('input_width');
            $table->unsignedSmallInteger('input_height');
            $table->char('input_sha256', 64);
            $table->string('input_stored_path', 512);
            $table->string('detector_model_name', 96)->nullable();
            $table->char('detector_checkpoint_sha256', 64)->nullable();
            $table->decimal('detector_threshold', 8, 7)->nullable();
            $table->decimal('detector_padding_ratio', 5, 4)->nullable();
            $table->decimal('detector_confidence', 8, 7)->nullable();
            $table->unsignedSmallInteger('detector_candidate_count')->nullable();
            $table->jsonb('detector_bbox')->nullable();
            $table->string('crop_mime', 64)->nullable();
            $table->string('crop_extension', 8)->nullable();
            $table->unsignedBigInteger('crop_bytes')->nullable();
            $table->unsignedSmallInteger('crop_width')->nullable();
            $table->unsignedSmallInteger('crop_height')->nullable();
            $table->char('crop_sha256', 64)->nullable();
            $table->string('crop_stored_path', 512)->nullable();
            $table->jsonb('crop_bbox')->nullable();
            $table->string('suggestion_status', 48)->nullable();
            $table->string('suggested_canonical', 16)->nullable();
            $table->string('display_text', 64)->nullable();
            $table->decimal('confidence', 8, 7)->nullable();
            $table->string('suggestion_source', 40)->nullable();
            $table->boolean('fallback_executed')->nullable();
            $table->string('model_name', 64);
            $table->string('result_schema_version', 16);
            $table->string('fallback_version', 16);
            $table->string('operational_effect', 32);
            $table->timestampTz('requested_at')->useCurrent();
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('finished_at')->nullable();

            $table->unique(['tenant_id', 'id'], 'vehicle_plate_runs_tenant_id_unique');
            $table->index(
                ['tenant_id', 'agency_id', 'requested_at'],
                'vehicle_plate_runs_scope_date_idx',
            );
            $table->foreign(['tenant_id', 'agency_id'], 'vehicle_plate_runs_agency_fk')
                ->references(['tenant_id', 'id'])->on('agencies')->restrictOnDelete();
            $table->foreign(
                ['tenant_id', 'agency_id', 'vehicle_id'],
                'vehicle_plate_runs_vehicle_agency_fk',
            )->references(['tenant_id', 'agency_id', 'id'])->on('vehicles')->restrictOnDelete();
            $table->foreign(['tenant_id', 'requested_by'], 'vehicle_plate_runs_requester_fk')
                ->references(['tenant_id', 'id'])->on('users')->restrictOnDelete();
        });

        Schema::create('vehicle_plate_prediction_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('agency_id');
            $table->unsignedBigInteger('vehicle_plate_prediction_run_id');
            $table->unsignedBigInteger('reviewed_by');
            $table->string('decision', 16);
            $table->string('verified_canonical', 16)->nullable();
            $table->string('note', 500)->nullable();
            $table->string('effect', 32);
            $table->timestampTz('reviewed_at')->useCurrent();

            $table->unique(
                'vehicle_plate_prediction_run_id',
                'vehicle_plate_reviews_one_per_run',
            );
            $table->index(
                ['tenant_id', 'agency_id', 'reviewed_at'],
                'vehicle_plate_reviews_scope_date_idx',
            );
            $table->foreign(['tenant_id', 'agency_id'], 'vehicle_plate_reviews_agency_fk')
                ->references(['tenant_id', 'id'])->on('agencies')->restrictOnDelete();
            $table->foreign(
                ['tenant_id', 'vehicle_plate_prediction_run_id'],
                'vehicle_plate_reviews_run_fk',
            )->references(['tenant_id', 'id'])->on('vehicle_plate_prediction_runs')->restrictOnDelete();
            $table->foreign(['tenant_id', 'reviewed_by'], 'vehicle_plate_reviews_reviewer_fk')
                ->references(['tenant_id', 'id'])->on('users')->restrictOnDelete();
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE vehicle_plate_prediction_runs
                ADD CONSTRAINT vehicle_plate_runs_contract_check
                    CHECK (
                        status IN ('queued', 'running', 'succeeded', 'failed')
                        AND input_kind IN ('full_vehicle_image', 'plate_crop')
                        AND input_mime = 'image/jpeg'
                        AND input_extension = 'jpg'
                        AND input_bytes BETWEEN 1 AND 8388608
                        AND input_width BETWEEN 1 AND 4096
                        AND input_height BETWEEN 1 AND 4096
                        AND input_sha256 ~ '^[a-f0-9]{64}$'
                        AND input_stored_path = (
                            'intelligence/plate-hybrid/inputs/'
                            || tenant_id::text
                            || '/'
                            || run_id::text
                            || '.jpg'
                        )
                        AND model_name = 'arabic_PP-OCRv5_mobile_rec'
                        AND result_schema_version = '1.0.0'
                        AND fallback_version = '1.0.0'
                        AND operational_effect = 'NO_OPERATIONAL_ACTION'
                        AND (failure_code IS NULL OR failure_code ~ '^[A-Z][A-Z0-9_]{2,63}$')
                        AND (
                            (input_kind = 'plate_crop'
                                AND detector_model_name IS NULL
                                AND detector_checkpoint_sha256 IS NULL
                                AND detector_threshold IS NULL
                                AND detector_padding_ratio IS NULL)
                            OR (input_kind = 'full_vehicle_image'
                                AND detector_model_name = 'fasterrcnn_resnet50_fpn_v2_e32_selected_private'
                                AND detector_checkpoint_sha256 ~ '^[a-f0-9]{64}$'
                                AND detector_threshold BETWEEN 0.001 AND 0.9999999
                                AND detector_padding_ratio BETWEEN 0 AND 0.25)
                        )
                    ),
                ADD CONSTRAINT vehicle_plate_runs_state_check
                    CHECK (
                        (status = 'queued'
                            AND started_at IS NULL
                            AND finished_at IS NULL
                            AND failure_code IS NULL
                            AND suggestion_status IS NULL
                            AND suggested_canonical IS NULL
                            AND display_text IS NULL
                            AND confidence IS NULL
                            AND suggestion_source IS NULL
                            AND fallback_executed IS NULL
                            AND detector_confidence IS NULL
                            AND detector_candidate_count IS NULL
                            AND detector_bbox IS NULL
                            AND crop_mime IS NULL
                            AND crop_extension IS NULL
                            AND crop_bytes IS NULL
                            AND crop_width IS NULL
                            AND crop_height IS NULL
                            AND crop_sha256 IS NULL
                            AND crop_stored_path IS NULL
                            AND crop_bbox IS NULL)
                        OR (status = 'running'
                            AND started_at IS NOT NULL
                            AND finished_at IS NULL
                            AND failure_code IS NULL
                            AND suggestion_status IS NULL
                            AND suggested_canonical IS NULL
                            AND display_text IS NULL
                            AND confidence IS NULL
                            AND suggestion_source IS NULL
                            AND fallback_executed IS NULL
                            AND detector_confidence IS NULL
                            AND detector_candidate_count IS NULL
                            AND detector_bbox IS NULL
                            AND crop_mime IS NULL
                            AND crop_extension IS NULL
                            AND crop_bytes IS NULL
                            AND crop_width IS NULL
                            AND crop_height IS NULL
                            AND crop_sha256 IS NULL
                            AND crop_stored_path IS NULL
                            AND crop_bbox IS NULL)
                        OR (status = 'succeeded'
                            AND started_at IS NOT NULL
                            AND finished_at IS NOT NULL
                            AND failure_code IS NULL
                            AND suggestion_status IN (
                                'complete_primary_suggestion',
                                'complete_segmented_suggestion',
                                'ambiguous_segmented_suggestion',
                                'partial_segmented_suggestion',
                                'empty_suggestion'
                            )
                            AND char_length(display_text) BETWEEN 1 AND 64
                            AND confidence BETWEEN 0 AND 1
                            AND suggestion_source IN (
                                'full_crop_ppocrv5',
                                'segmented_ppocrv5_fusion'
                            )
                            AND fallback_executed IS NOT NULL
                            AND (
                                (suggestion_status IN (
                                    'complete_primary_suggestion',
                                    'complete_segmented_suggestion',
                                    'ambiguous_segmented_suggestion'
                                ) AND suggested_canonical ~ '^[1-9][0-9]{0,4}\|[أبدهوطيلكمنصفرس]\|[1-9][0-9]?$')
                                OR (suggestion_status IN (
                                    'partial_segmented_suggestion',
                                    'empty_suggestion'
                                ) AND suggested_canonical IS NULL)
                            )
                            AND (
                                (suggestion_status = 'complete_primary_suggestion'
                                    AND suggestion_source = 'full_crop_ppocrv5'
                                    AND fallback_executed = false)
                                OR (suggestion_status IN (
                                    'complete_segmented_suggestion',
                                    'ambiguous_segmented_suggestion',
                                    'partial_segmented_suggestion'
                                )
                                    AND suggestion_source = 'segmented_ppocrv5_fusion'
                                    AND fallback_executed = true)
                                OR (suggestion_status = 'empty_suggestion'
                                    AND suggestion_source = 'segmented_ppocrv5_fusion')
                            )
                            AND (
                                (input_kind = 'plate_crop'
                                    AND detector_confidence IS NULL
                                    AND detector_candidate_count IS NULL
                                    AND detector_bbox IS NULL
                                    AND crop_mime IS NULL
                                    AND crop_extension IS NULL
                                    AND crop_bytes IS NULL
                                    AND crop_width IS NULL
                                    AND crop_height IS NULL
                                    AND crop_sha256 IS NULL
                                    AND crop_stored_path IS NULL
                                    AND crop_bbox IS NULL)
                                OR (input_kind = 'full_vehicle_image'
                                    AND detector_confidence BETWEEN detector_threshold AND 1
                                    AND detector_candidate_count BETWEEN 1 AND 100
                                    AND jsonb_typeof(detector_bbox) = 'array'
                                    AND jsonb_array_length(detector_bbox) = 4
                                    AND jsonb_typeof(detector_bbox -> 0) = 'number'
                                    AND jsonb_typeof(detector_bbox -> 1) = 'number'
                                    AND jsonb_typeof(detector_bbox -> 2) = 'number'
                                    AND jsonb_typeof(detector_bbox -> 3) = 'number'
                                    AND (detector_bbox ->> 0)::numeric >= 0
                                    AND (detector_bbox ->> 1)::numeric >= 0
                                    AND (detector_bbox ->> 2)::numeric <= input_width
                                    AND (detector_bbox ->> 3)::numeric <= input_height
                                    AND (detector_bbox ->> 2)::numeric > (detector_bbox ->> 0)::numeric
                                    AND (detector_bbox ->> 3)::numeric > (detector_bbox ->> 1)::numeric
                                    AND crop_mime = 'image/jpeg'
                                    AND crop_extension = 'jpg'
                                    AND crop_bytes BETWEEN 1 AND 2097152
                                    AND crop_width BETWEEN 1 AND input_width
                                    AND crop_height BETWEEN 1 AND input_height
                                    AND crop_sha256 ~ '^[a-f0-9]{64}$'
                                    AND crop_stored_path = (
                                        'intelligence/plate-hybrid/crops/'
                                        || tenant_id::text
                                        || '/'
                                        || run_id::text
                                        || '.jpg'
                                    )
                                    AND jsonb_typeof(crop_bbox) = 'array'
                                    AND jsonb_array_length(crop_bbox) = 4
                                    AND jsonb_typeof(crop_bbox -> 0) = 'number'
                                    AND jsonb_typeof(crop_bbox -> 1) = 'number'
                                    AND jsonb_typeof(crop_bbox -> 2) = 'number'
                                    AND jsonb_typeof(crop_bbox -> 3) = 'number'
                                    AND (crop_bbox ->> 0)::numeric >= 0
                                    AND (crop_bbox ->> 1)::numeric >= 0
                                    AND (crop_bbox ->> 2)::numeric <= input_width
                                    AND (crop_bbox ->> 3)::numeric <= input_height
                                    AND (crop_bbox ->> 2)::numeric > (crop_bbox ->> 0)::numeric
                                    AND (crop_bbox ->> 3)::numeric > (crop_bbox ->> 1)::numeric
                                    AND crop_width = (crop_bbox ->> 2)::integer - (crop_bbox ->> 0)::integer
                                    AND crop_height = (crop_bbox ->> 3)::integer - (crop_bbox ->> 1)::integer)
                            ))
                        OR (status = 'failed'
                            AND started_at IS NOT NULL
                            AND finished_at IS NOT NULL
                            AND failure_code IS NOT NULL
                            AND suggestion_status IS NULL
                            AND suggested_canonical IS NULL
                            AND display_text IS NULL
                            AND confidence IS NULL
                            AND suggestion_source IS NULL
                            AND fallback_executed IS NULL
                            AND detector_confidence IS NULL
                            AND detector_candidate_count IS NULL
                            AND detector_bbox IS NULL
                            AND crop_mime IS NULL
                            AND crop_extension IS NULL
                            AND crop_bytes IS NULL
                            AND crop_width IS NULL
                            AND crop_height IS NULL
                            AND crop_sha256 IS NULL
                            AND crop_stored_path IS NULL
                            AND crop_bbox IS NULL)
                    );

            CREATE UNIQUE INDEX vehicle_plate_runs_one_active_per_vehicle
            ON vehicle_plate_prediction_runs (tenant_id, vehicle_id)
            WHERE status IN ('queued', 'running');

            CREATE OR REPLACE FUNCTION guard_vehicle_plate_run_transition() RETURNS trigger
            LANGUAGE plpgsql AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    RAISE EXCEPTION 'Vehicle plate prediction runs cannot be deleted' USING ERRCODE = '23514';
                END IF;

                IF NEW.tenant_id <> OLD.tenant_id
                    OR NEW.agency_id <> OLD.agency_id
                    OR NEW.run_id <> OLD.run_id
                    OR NEW.vehicle_id <> OLD.vehicle_id
                    OR NEW.requested_by <> OLD.requested_by
                    OR NEW.input_kind <> OLD.input_kind
                    OR NEW.input_mime <> OLD.input_mime
                    OR NEW.input_extension <> OLD.input_extension
                    OR NEW.input_bytes <> OLD.input_bytes
                    OR NEW.input_width <> OLD.input_width
                    OR NEW.input_height <> OLD.input_height
                    OR NEW.input_sha256 <> OLD.input_sha256
                    OR NEW.input_stored_path <> OLD.input_stored_path
                    OR NEW.detector_model_name IS DISTINCT FROM OLD.detector_model_name
                    OR NEW.detector_checkpoint_sha256 IS DISTINCT FROM OLD.detector_checkpoint_sha256
                    OR NEW.detector_threshold IS DISTINCT FROM OLD.detector_threshold
                    OR NEW.detector_padding_ratio IS DISTINCT FROM OLD.detector_padding_ratio
                    OR NEW.model_name <> OLD.model_name
                    OR NEW.result_schema_version <> OLD.result_schema_version
                    OR NEW.fallback_version <> OLD.fallback_version
                    OR NEW.operational_effect <> OLD.operational_effect
                    OR NEW.requested_at <> OLD.requested_at THEN
                    RAISE EXCEPTION 'Vehicle plate prediction identity is immutable' USING ERRCODE = '23514';
                END IF;

                IF OLD.status = 'queued' AND NEW.status NOT IN ('running', 'failed') THEN
                    RAISE EXCEPTION 'Invalid queued vehicle plate transition' USING ERRCODE = '23514';
                ELSIF OLD.status = 'running' AND NEW.status NOT IN ('succeeded', 'failed') THEN
                    RAISE EXCEPTION 'Invalid running vehicle plate transition' USING ERRCODE = '23514';
                ELSIF OLD.status IN ('succeeded', 'failed') THEN
                    RAISE EXCEPTION 'Terminal vehicle plate prediction is immutable' USING ERRCODE = '23514';
                END IF;

                RETURN NEW;
            END;
            $$;

            CREATE TRIGGER vehicle_plate_runs_transition_guard
            BEFORE UPDATE OR DELETE ON vehicle_plate_prediction_runs
            FOR EACH ROW EXECUTE FUNCTION guard_vehicle_plate_run_transition();

            ALTER TABLE vehicle_plate_prediction_reviews
                ADD CONSTRAINT vehicle_plate_reviews_contract_check
                    CHECK (
                        decision IN ('confirmed', 'corrected', 'ignored')
                        AND effect = 'NO_OPERATIONAL_ACTION'
                        AND (note IS NULL OR char_length(note) BETWEEN 1 AND 500)
                        AND (
                            (decision IN ('confirmed', 'corrected')
                                AND verified_canonical ~ '^[1-9][0-9]{0,4}\|[أبدهوطيلكمنصفرس]\|[1-9][0-9]?$')
                            OR (decision = 'ignored' AND verified_canonical IS NULL)
                        )
                    );

            CREATE OR REPLACE FUNCTION guard_vehicle_plate_review() RETURNS trigger
            LANGUAGE plpgsql AS $$
            DECLARE
                run_suggestion text;
            BEGIN
                IF TG_OP <> 'INSERT' THEN
                    RAISE EXCEPTION 'Vehicle plate prediction reviews are append-only' USING ERRCODE = '23514';
                END IF;

                SELECT run.suggested_canonical
                INTO run_suggestion
                FROM vehicle_plate_prediction_runs AS run
                WHERE run.id = NEW.vehicle_plate_prediction_run_id
                    AND run.tenant_id = NEW.tenant_id
                    AND run.agency_id = NEW.agency_id
                    AND run.status = 'succeeded'
                FOR KEY SHARE;
                IF NOT FOUND THEN
                    RAISE EXCEPTION 'Vehicle plate review scope or state mismatch' USING ERRCODE = '23514';
                END IF;
                IF NEW.decision = 'confirmed'
                    AND NEW.verified_canonical IS DISTINCT FROM run_suggestion THEN
                    RAISE EXCEPTION 'Confirmed plate must equal the suggestion' USING ERRCODE = '23514';
                END IF;
                IF NEW.decision = 'corrected'
                    AND NEW.verified_canonical IS NOT DISTINCT FROM run_suggestion THEN
                    RAISE EXCEPTION 'Corrected plate must differ from the suggestion' USING ERRCODE = '23514';
                END IF;

                RETURN NEW;
            END;
            $$;

            CREATE TRIGGER vehicle_plate_reviews_guard
            BEFORE INSERT OR UPDATE OR DELETE ON vehicle_plate_prediction_reviews
            FOR EACH ROW EXECUTE FUNCTION guard_vehicle_plate_review();
        SQL);

        DB::table('permissions')->insertOrIgnore([
            'slug' => 'prediction.plate.review',
            'name' => 'Analyser et corriger une plaque de véhicule',
            'group' => 'prediction',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('permissions')->where('slug', 'prediction.plate.review')->update([
            'name' => 'Analyser et corriger une plaque de véhicule',
            'group' => 'prediction',
            'updated_at' => now(),
        ]);

        $permissionId = DB::table('permissions')
            ->where('slug', 'prediction.plate.review')
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
            DROP TRIGGER IF EXISTS vehicle_plate_reviews_guard ON vehicle_plate_prediction_reviews;
            DROP FUNCTION IF EXISTS guard_vehicle_plate_review();
            DROP TRIGGER IF EXISTS vehicle_plate_runs_transition_guard ON vehicle_plate_prediction_runs;
            DROP FUNCTION IF EXISTS guard_vehicle_plate_run_transition();
        SQL);

        Schema::dropIfExists('vehicle_plate_prediction_reviews');
        Schema::dropIfExists('vehicle_plate_prediction_runs');

        // Conservative rollback: the permission and later delegations remain.
    }
};
