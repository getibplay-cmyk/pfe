<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            ALTER TABLE vehicle_color_prediction_runs
            ALTER COLUMN vehicle_id DROP NOT NULL
        SQL);

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION guard_vehicle_color_run_transition() RETURNS trigger
            LANGUAGE plpgsql AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    RAISE EXCEPTION 'Vehicle color prediction runs cannot be deleted' USING ERRCODE = '23514';
                END IF;

                IF OLD.status = 'succeeded'
                    AND OLD.vehicle_id IS NULL
                    AND NEW.vehicle_id IS NOT NULL
                    AND NEW.status = OLD.status
                    AND NEW.tenant_id = OLD.tenant_id
                    AND NEW.agency_id = OLD.agency_id
                    AND NEW.run_id = OLD.run_id
                    AND NEW.requested_by = OLD.requested_by
                    AND NEW.input_mime = OLD.input_mime
                    AND NEW.input_extension = OLD.input_extension
                    AND NEW.input_bytes = OLD.input_bytes
                    AND NEW.input_sha256 = OLD.input_sha256
                    AND NEW.input_stored_path = OLD.input_stored_path
                    AND NEW.suggested_color = OLD.suggested_color
                    AND NEW.confidence = OLD.confidence
                    AND NEW.model_accepted = OLD.model_accepted
                    AND NEW.probabilities = OLD.probabilities
                    AND NEW.model_name = OLD.model_name
                    AND NEW.model_version = OLD.model_version
                    AND NEW.model_artifact_sha256 = OLD.model_artifact_sha256
                    AND NEW.metadata_sha256 = OLD.metadata_sha256
                    AND NEW.accepted_threshold = OLD.accepted_threshold
                    AND NEW.operational_effect = OLD.operational_effect
                    AND NEW.requested_at = OLD.requested_at
                    AND NEW.started_at = OLD.started_at
                    AND NEW.finished_at = OLD.finished_at
                    AND NEW.failure_code IS NOT DISTINCT FROM OLD.failure_code THEN
                    RETURN NEW;
                END IF;

                IF NEW.tenant_id <> OLD.tenant_id
                    OR NEW.agency_id <> OLD.agency_id
                    OR NEW.run_id <> OLD.run_id
                    OR NEW.vehicle_id IS DISTINCT FROM OLD.vehicle_id
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
        SQL);
    }

    public function down(): void
    {
        if (DB::table('vehicle_color_prediction_runs')->whereNull('vehicle_id')->exists()) {
            throw new RuntimeException(
                'Cannot restore required vehicle_id while preparatory color runs exist.',
            );
        }

        DB::statement(<<<'SQL'
            ALTER TABLE vehicle_color_prediction_runs
            ALTER COLUMN vehicle_id SET NOT NULL
        SQL);

        DB::unprepared(<<<'SQL'
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
        SQL);
    }
};
