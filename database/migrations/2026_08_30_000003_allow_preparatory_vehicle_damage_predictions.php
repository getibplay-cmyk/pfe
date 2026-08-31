<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vehicle_damage_prediction_runs', function (Blueprint $table) {
            $table->unsignedBigInteger('rental_contract_id')->nullable()->after('agency_id');
        });

        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS vehicle_damage_runs_transition_guard
                ON vehicle_damage_prediction_runs;
            DROP FUNCTION IF EXISTS guard_vehicle_damage_run_transition();
            SQL);

        DB::statement(<<<'SQL'
            UPDATE vehicle_damage_prediction_runs AS run
            SET rental_contract_id = inspection.rental_contract_id
            FROM vehicle_inspections AS inspection
            WHERE inspection.id = run.vehicle_inspection_id
                AND inspection.tenant_id = run.tenant_id
                AND run.rental_contract_id IS NULL
            SQL);

        if (DB::table('vehicle_damage_prediction_runs')->whereNull('rental_contract_id')->exists()) {
            throw new RuntimeException('Every vehicle damage run must resolve to a rental contract.');
        }

        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS vehicle_damage_runs_transition_guard
                ON vehicle_damage_prediction_runs;
            DROP FUNCTION IF EXISTS guard_vehicle_damage_run_transition();

            ALTER TABLE vehicle_damage_prediction_runs
                ALTER COLUMN rental_contract_id SET NOT NULL,
                ALTER COLUMN vehicle_inspection_id DROP NOT NULL,
                ADD CONSTRAINT vehicle_damage_runs_contract_scope_fk
                    FOREIGN KEY (tenant_id, agency_id, vehicle_id, rental_contract_id)
                    REFERENCES rental_contracts (tenant_id, agency_id, vehicle_id, id)
                    ON DELETE RESTRICT,
                ADD CONSTRAINT vehicle_damage_runs_inspection_contract_fk
                    FOREIGN KEY (tenant_id, rental_contract_id, vehicle_inspection_id)
                    REFERENCES vehicle_inspections (tenant_id, rental_contract_id, id)
                    ON DELETE RESTRICT;

            CREATE OR REPLACE FUNCTION guard_vehicle_damage_run_transition() RETURNS trigger
            LANGUAGE plpgsql AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    RAISE EXCEPTION 'Vehicle damage prediction runs cannot be deleted' USING ERRCODE = '23514';
                END IF;

                IF NEW.tenant_id IS DISTINCT FROM OLD.tenant_id
                    OR NEW.agency_id IS DISTINCT FROM OLD.agency_id
                    OR NEW.rental_contract_id IS DISTINCT FROM OLD.rental_contract_id
                    OR NEW.run_id IS DISTINCT FROM OLD.run_id
                    OR NEW.vehicle_id IS DISTINCT FROM OLD.vehicle_id
                    OR NEW.requested_by IS DISTINCT FROM OLD.requested_by
                    OR NEW.input_mime IS DISTINCT FROM OLD.input_mime
                    OR NEW.input_extension IS DISTINCT FROM OLD.input_extension
                    OR NEW.input_bytes IS DISTINCT FROM OLD.input_bytes
                    OR NEW.input_sha256 IS DISTINCT FROM OLD.input_sha256
                    OR NEW.input_stored_path IS DISTINCT FROM OLD.input_stored_path
                    OR NEW.input_width IS DISTINCT FROM OLD.input_width
                    OR NEW.input_height IS DISTINCT FROM OLD.input_height
                    OR NEW.model_name IS DISTINCT FROM OLD.model_name
                    OR NEW.model_version IS DISTINCT FROM OLD.model_version
                    OR NEW.model_artifact_sha256 IS DISTINCT FROM OLD.model_artifact_sha256
                    OR NEW.model_card_sha256 IS DISTINCT FROM OLD.model_card_sha256
                    OR NEW.decision_threshold IS DISTINCT FROM OLD.decision_threshold
                    OR NEW.operational_effect IS DISTINCT FROM OLD.operational_effect
                    OR NEW.requested_at IS DISTINCT FROM OLD.requested_at THEN
                    RAISE EXCEPTION 'Vehicle damage prediction identity is immutable' USING ERRCODE = '23514';
                END IF;

                IF OLD.status IN ('succeeded', 'failed') THEN
                    IF NOT (
                        OLD.status = 'succeeded'
                        AND NEW.status = 'succeeded'
                        AND OLD.vehicle_inspection_id IS NULL
                        AND NEW.vehicle_inspection_id IS NOT NULL
                        AND NEW.failure_code IS NOT DISTINCT FROM OLD.failure_code
                        AND NEW.quality_status IS NOT DISTINCT FROM OLD.quality_status
                        AND NEW.quality_reasons IS NOT DISTINCT FROM OLD.quality_reasons
                        AND NEW.quality_metrics IS NOT DISTINCT FROM OLD.quality_metrics
                        AND NEW.evaluated_patches IS NOT DISTINCT FROM OLD.evaluated_patches
                        AND NEW.max_probability_damage IS NOT DISTINCT FROM OLD.max_probability_damage
                        AND NEW.suggested_damage IS NOT DISTINCT FROM OLD.suggested_damage
                        AND NEW.candidate_regions IS NOT DISTINCT FROM OLD.candidate_regions
                        AND NEW.started_at IS NOT DISTINCT FROM OLD.started_at
                        AND NEW.finished_at IS NOT DISTINCT FROM OLD.finished_at
                    ) THEN
                        RAISE EXCEPTION 'Terminal vehicle damage prediction is immutable' USING ERRCODE = '23514';
                    END IF;
                ELSIF NEW.vehicle_inspection_id IS DISTINCT FROM OLD.vehicle_inspection_id THEN
                    RAISE EXCEPTION 'Vehicle damage inspection link requires a succeeded run' USING ERRCODE = '23514';
                ELSIF OLD.status = 'queued' AND NEW.status NOT IN ('running', 'failed') THEN
                    RAISE EXCEPTION 'Invalid queued vehicle damage transition' USING ERRCODE = '23514';
                ELSIF OLD.status = 'running' AND NEW.status NOT IN ('succeeded', 'failed') THEN
                    RAISE EXCEPTION 'Invalid running vehicle damage transition' USING ERRCODE = '23514';
                END IF;

                RETURN NEW;
            END;
            $$;

            CREATE TRIGGER vehicle_damage_runs_transition_guard
            BEFORE UPDATE OR DELETE ON vehicle_damage_prediction_runs
            FOR EACH ROW EXECUTE FUNCTION guard_vehicle_damage_run_transition();
            SQL);
    }

    public function down(): void
    {
        if (DB::table('vehicle_damage_prediction_runs')->whereNull('vehicle_inspection_id')->exists()) {
            throw new RuntimeException(
                'Cannot remove preparatory damage support while unattached runs exist.',
            );
        }

        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS vehicle_damage_runs_transition_guard
                ON vehicle_damage_prediction_runs;
            DROP FUNCTION IF EXISTS guard_vehicle_damage_run_transition();

            ALTER TABLE vehicle_damage_prediction_runs
                DROP CONSTRAINT vehicle_damage_runs_inspection_contract_fk,
                DROP CONSTRAINT vehicle_damage_runs_contract_scope_fk,
                ALTER COLUMN vehicle_inspection_id SET NOT NULL;
            SQL);

        Schema::table('vehicle_damage_prediction_runs', function (Blueprint $table) {
            $table->dropColumn('rental_contract_id');
        });

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION guard_vehicle_damage_run_transition() RETURNS trigger
            LANGUAGE plpgsql AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    RAISE EXCEPTION 'Vehicle damage prediction runs cannot be deleted' USING ERRCODE = '23514';
                END IF;

                IF NEW.tenant_id IS DISTINCT FROM OLD.tenant_id
                    OR NEW.agency_id IS DISTINCT FROM OLD.agency_id
                    OR NEW.run_id IS DISTINCT FROM OLD.run_id
                    OR NEW.vehicle_inspection_id IS DISTINCT FROM OLD.vehicle_inspection_id
                    OR NEW.vehicle_id IS DISTINCT FROM OLD.vehicle_id
                    OR NEW.requested_by IS DISTINCT FROM OLD.requested_by
                    OR NEW.input_mime IS DISTINCT FROM OLD.input_mime
                    OR NEW.input_extension IS DISTINCT FROM OLD.input_extension
                    OR NEW.input_bytes IS DISTINCT FROM OLD.input_bytes
                    OR NEW.input_sha256 IS DISTINCT FROM OLD.input_sha256
                    OR NEW.input_stored_path IS DISTINCT FROM OLD.input_stored_path
                    OR NEW.input_width IS DISTINCT FROM OLD.input_width
                    OR NEW.input_height IS DISTINCT FROM OLD.input_height
                    OR NEW.model_name IS DISTINCT FROM OLD.model_name
                    OR NEW.model_version IS DISTINCT FROM OLD.model_version
                    OR NEW.model_artifact_sha256 IS DISTINCT FROM OLD.model_artifact_sha256
                    OR NEW.model_card_sha256 IS DISTINCT FROM OLD.model_card_sha256
                    OR NEW.decision_threshold IS DISTINCT FROM OLD.decision_threshold
                    OR NEW.operational_effect IS DISTINCT FROM OLD.operational_effect
                    OR NEW.requested_at IS DISTINCT FROM OLD.requested_at THEN
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
            SQL);
    }
};
