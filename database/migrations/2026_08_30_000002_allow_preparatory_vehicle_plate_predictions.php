<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            ALTER TABLE vehicle_plate_prediction_runs
            ALTER COLUMN vehicle_id DROP NOT NULL
        SQL);

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION guard_vehicle_plate_run_transition() RETURNS trigger
            LANGUAGE plpgsql AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    RAISE EXCEPTION 'Vehicle plate prediction runs cannot be deleted' USING ERRCODE = '23514';
                END IF;

                IF OLD.status = 'succeeded'
                    AND OLD.vehicle_id IS NULL
                    AND NEW.vehicle_id IS NOT NULL
                    AND OLD.suggestion_status IN (
                        'complete_primary_suggestion',
                        'complete_segmented_suggestion',
                        'ambiguous_segmented_suggestion'
                    )
                    AND OLD.suggested_canonical IS NOT NULL
                    AND OLD.confidence BETWEEN 0 AND 1
                    AND (to_jsonb(NEW) - 'vehicle_id') = (to_jsonb(OLD) - 'vehicle_id') THEN
                    RETURN NEW;
                END IF;

                IF NEW.tenant_id <> OLD.tenant_id
                    OR NEW.agency_id <> OLD.agency_id
                    OR NEW.run_id <> OLD.run_id
                    OR NEW.vehicle_id IS DISTINCT FROM OLD.vehicle_id
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
        SQL);
    }

    public function down(): void
    {
        if (DB::table('vehicle_plate_prediction_runs')->whereNull('vehicle_id')->exists()) {
            throw new RuntimeException(
                'Cannot restore required vehicle_id while preparatory plate runs exist.',
            );
        }

        DB::statement(<<<'SQL'
            ALTER TABLE vehicle_plate_prediction_runs
            ALTER COLUMN vehicle_id SET NOT NULL
        SQL);

        DB::unprepared(<<<'SQL'
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
        SQL);
    }
};
