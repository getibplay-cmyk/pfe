<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE vehicle_damage_prediction_runs
                DROP CONSTRAINT vehicle_damage_runs_contract_check,
                ALTER COLUMN decision_threshold TYPE numeric(12, 10),
                ALTER COLUMN max_probability_damage TYPE numeric(12, 10);

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
                        AND (
                            (model_name = 'rentfleet_vehicle_damage_efficientnetv2s'
                                AND model_version = 's7-damage-efficientnetv2s-v1.1'
                                AND decision_threshold = 0.495)
                            OR
                            (model_name = 'rentfleet_vehicle_damage_rtdetrv2_s'
                                AND model_version = 's7-damage-rtdetrv2-s-soup192429-v1.0'
                                AND decision_threshold = 0.8236151338)
                        )
                        AND model_artifact_sha256 ~ '^[a-f0-9]{64}$'
                        AND model_card_sha256 ~ '^[a-f0-9]{64}$'
                        AND operational_effect = 'NO_OPERATIONAL_ACTION'
                        AND (failure_code IS NULL OR failure_code ~ '^[A-Z][A-Z0-9_]{2,63}$')
                    );
            SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DO $$
            BEGIN
                IF EXISTS (
                    SELECT 1
                    FROM vehicle_damage_prediction_runs
                    WHERE model_name = 'rentfleet_vehicle_damage_rtdetrv2_s'
                ) THEN
                    RAISE EXCEPTION 'Cannot remove RT-DETRv2-S contract while its immutable runs exist';
                END IF;
            END;
            $$;

            ALTER TABLE vehicle_damage_prediction_runs
                DROP CONSTRAINT vehicle_damage_runs_contract_check,
                ALTER COLUMN decision_threshold TYPE numeric(4, 3),
                ALTER COLUMN max_probability_damage TYPE numeric(8, 7);

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
                    );
            SQL);
    }
};
