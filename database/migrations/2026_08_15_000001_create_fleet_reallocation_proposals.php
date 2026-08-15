<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fleet_reallocation_proposals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->uuid('proposal_id')->unique();
            $table->uuid('idempotency_key');
            $table->string('schema_version', 16);
            $table->string('source_kind', 32);
            $table->string('solver_name', 64);
            $table->string('solver_version', 16);
            $table->string('solver_status', 16);
            $table->string('qualification_decision', 96);
            $table->char('qualification_commit', 40);
            $table->char('evidence_commit', 40);
            $table->timestampTz('generated_at');
            $table->date('as_of_date');
            $table->date('target_date');
            $table->unsignedSmallInteger('forecast_horizon');
            $table->string('distance_unit', 8);
            $table->string('data_status', 64);
            $table->string('forecast_model_name', 64);
            $table->string('forecast_model_version', 16);
            $table->char('forecast_reference_sha256', 64);
            $table->string('forecast_local_status', 64);
            $table->string('cancellation_model_name', 64);
            $table->string('cancellation_gate_decision', 96);
            $table->decimal('presence_probability', 7, 6);
            $table->string('presence_reason', 96);
            $table->unsignedSmallInteger('node_count');
            $table->unsignedInteger('move_line_count');
            $table->unsignedInteger('relocated_vehicle_count');
            $table->unsignedInteger('total_demand');
            $table->unsignedInteger('served_demand');
            $table->unsignedInteger('unserved_demand');
            $table->decimal('service_rate', 7, 6);
            $table->unsignedBigInteger('relocation_cost_centimes');
            $table->unsignedBigInteger('decision_cost_centimes');
            $table->decimal('solver_runtime_ms', 12, 6);
            $table->char('canonical_payload_sha256', 64);
            $table->char('content_sha256', 64);
            $table->unsignedBigInteger('byte_size');
            $table->string('stored_path', 500);
            $table->string('original_name', 255);
            $table->string('validation_status', 24);
            $table->string('local_validation_status', 64);
            $table->string('operational_effect', 32);
            $table->unsignedBigInteger('imported_by');
            $table->timestampTz('imported_at')->useCurrent();

            $table->unique(['tenant_id', 'id'], 'fleet_reallocation_proposals_tenant_id_unique');
            $table->unique(['tenant_id', 'idempotency_key'], 'fleet_reallocation_proposals_idempotency_unique');
            $table->unique('stored_path', 'fleet_reallocation_proposals_path_unique');
            $table->index(['tenant_id', 'imported_at'], 'fleet_reallocation_proposals_scope_date_idx');
            $table->foreign(['tenant_id', 'imported_by'], 'fleet_reallocation_proposals_importer_fk')
                ->references(['tenant_id', 'id'])->on('users')->restrictOnDelete();
        });

        Schema::create('fleet_reallocation_moves', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('fleet_reallocation_proposal_id');
            $table->unsignedInteger('row_position');
            $table->string('from_node_ref', 32);
            $table->string('to_node_ref', 32);
            $table->unsignedInteger('vehicles');
            $table->decimal('distance_km', 10, 3);
            $table->unsignedInteger('unit_cost_centimes');
            $table->unsignedBigInteger('total_cost_centimes');
            $table->string('reason_code', 64);
            $table->string('operational_effect', 32);
            $table->timestampTz('created_at')->useCurrent();

            $table->unique(['tenant_id', 'id'], 'fleet_reallocation_moves_tenant_id_unique');
            $table->unique(
                ['fleet_reallocation_proposal_id', 'row_position'],
                'fleet_reallocation_moves_proposal_position_unique',
            );
            $table->unique(
                ['fleet_reallocation_proposal_id', 'from_node_ref', 'to_node_ref'],
                'fleet_reallocation_moves_proposal_lane_unique',
            );
            $table->index(
                ['tenant_id', 'fleet_reallocation_proposal_id'],
                'fleet_reallocation_moves_scope_proposal_idx',
            );
            $table->foreign(
                ['tenant_id', 'fleet_reallocation_proposal_id'],
                'fleet_reallocation_moves_proposal_fk',
            )->references(['tenant_id', 'id'])->on('fleet_reallocation_proposals')->restrictOnDelete();
        });

        Schema::create('fleet_reallocation_decisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('fleet_reallocation_proposal_id');
            $table->unsignedBigInteger('actor_user_id');
            $table->string('decision', 32);
            $table->string('reason_code', 64);
            $table->string('effect', 32);
            $table->timestampTz('created_at')->useCurrent();

            $table->unique(
                'fleet_reallocation_proposal_id',
                'fleet_reallocation_decisions_proposal_unique',
            );
            $table->index(['tenant_id', 'created_at'], 'fleet_reallocation_decisions_scope_date_idx');
            $table->foreign(
                ['tenant_id', 'fleet_reallocation_proposal_id'],
                'fleet_reallocation_decisions_proposal_fk',
            )->references(['tenant_id', 'id'])->on('fleet_reallocation_proposals')->restrictOnDelete();
            $table->foreign(['tenant_id', 'actor_user_id'], 'fleet_reallocation_decisions_actor_fk')
                ->references(['tenant_id', 'id'])->on('users')->restrictOnDelete();
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE fleet_reallocation_proposals
                ADD CONSTRAINT fleet_reallocation_proposals_source_check
                    CHECK (
                        schema_version = '1.0.0'
                        AND source_kind = 'synthetic_demo'
                        AND solver_name = 'ortools_simple_min_cost_flow'
                        AND solver_version = '9.15.6755'
                        AND solver_status = 'OPTIMAL'
                        AND qualification_decision = 'QUALIFIED_FOR_CONSULTATIVE_SAAS_INTEGRATION_REVIEW'
                        AND qualification_commit = 'f71a80ac657c5ed58a8147e8535bdba60dddde0d'
                        AND evidence_commit = '77479105049fa183f9e032e3207017b5348f6f1b'
                    ),
                ADD CONSTRAINT fleet_reallocation_proposals_lineage_check
                    CHECK (
                        forecast_horizon BETWEEN 1 AND 7
                        AND target_date = as_of_date + forecast_horizon
                        AND distance_unit = 'km'
                        AND data_status = 'SYNTHETIC_DEMO_NOT_RENTFLEET_HISTORY'
                        AND forecast_model_name = 'hgb_poisson::regularized'
                        AND forecast_model_version = 'j5-v1'
                        AND forecast_reference_sha256 ~ '^[0-9a-f]{64}$'
                        AND forecast_local_status = 'not_available_pending_real_history'
                        AND cancellation_model_name = 'cancellation_risk_catboost'
                        AND cancellation_gate_decision = 'RESEARCH_GATE_NOT_PASSED_NO_SAAS_INTEGRATION'
                        AND presence_probability = 1.000000
                        AND presence_reason = 'CATBOOST_RESEARCH_GATE_NOT_PASSED_CONSERVATIVE_NO_DISCOUNT'
                    ),
                ADD CONSTRAINT fleet_reallocation_proposals_metrics_check
                    CHECK (
                        node_count BETWEEN 2 AND 20
                        AND move_line_count BETWEEN 1 AND 100
                        AND relocated_vehicle_count > 0
                        AND total_demand > 0
                        AND served_demand + unserved_demand = total_demand
                        AND service_rate BETWEEN 0.800000 AND 1.000000
                        AND decision_cost_centimes = relocation_cost_centimes + unserved_demand * 1000000::bigint
                        AND solver_runtime_ms BETWEEN 0.000000 AND 5000.000000
                    ),
                ADD CONSTRAINT fleet_reallocation_proposals_digest_check
                    CHECK (
                        canonical_payload_sha256 ~ '^[0-9a-f]{64}$'
                        AND content_sha256 ~ '^[0-9a-f]{64}$'
                    ),
                ADD CONSTRAINT fleet_reallocation_proposals_storage_check
                    CHECK (
                        byte_size > 0
                        AND stored_path ~ '^intelligence/fleet-reallocation/[0-9a-f-]{36}\.json$'
                        AND original_name ~ '^rentfleet_fleet_reallocation_[0-9a-f-]{36}\.json$'
                    ),
                ADD CONSTRAINT fleet_reallocation_proposals_safety_check
                    CHECK (
                        validation_status = 'validated'
                        AND local_validation_status = 'NOT_VALIDATED_NO_REAL_HISTORY'
                        AND operational_effect = 'NO_OPERATIONAL_ACTION'
                    );

            ALTER TABLE fleet_reallocation_moves
                ADD CONSTRAINT fleet_reallocation_moves_ref_check
                    CHECK (
                        row_position BETWEEN 0 AND 99
                        AND from_node_ref ~ '^SYNTH-NODE-[0-9]{3}$'
                        AND to_node_ref ~ '^SYNTH-NODE-[0-9]{3}$'
                        AND from_node_ref <> to_node_ref
                    ),
                ADD CONSTRAINT fleet_reallocation_moves_value_check
                    CHECK (
                        vehicles > 0
                        AND distance_km > 0.000
                        AND unit_cost_centimes > 0
                        AND total_cost_centimes = vehicles * unit_cost_centimes::bigint
                        AND reason_code = 'EFFECTIVE_DEMAND_IMBALANCE'
                        AND operational_effect = 'NO_OPERATIONAL_ACTION'
                    );

            ALTER TABLE fleet_reallocation_decisions
                ADD CONSTRAINT fleet_reallocation_decisions_value_check
                    CHECK (decision IN ('accepted_for_demo_review', 'rejected')),
                ADD CONSTRAINT fleet_reallocation_decisions_reason_check
                    CHECK (reason_code ~ '^[A-Z][A-Z0-9_]{2,63}$'),
                ADD CONSTRAINT fleet_reallocation_decisions_effect_check
                    CHECK (effect = 'NO_OPERATIONAL_ACTION');

            CREATE OR REPLACE FUNCTION enforce_fleet_reallocation_scope() RETURNS trigger
            LANGUAGE plpgsql AS $$
            BEGIN
                IF TG_TABLE_NAME = 'fleet_reallocation_moves' THEN
                    PERFORM 1
                    FROM fleet_reallocation_proposals AS proposal
                    WHERE proposal.id = NEW.fleet_reallocation_proposal_id
                        AND proposal.tenant_id = NEW.tenant_id
                        AND proposal.validation_status = 'validated'
                    FOR KEY SHARE;

                    IF NOT FOUND THEN
                        RAISE EXCEPTION 'Fleet reallocation move scope mismatch' USING ERRCODE = '23514';
                    END IF;
                ELSIF TG_TABLE_NAME = 'fleet_reallocation_decisions' THEN
                    PERFORM 1
                    FROM fleet_reallocation_proposals AS proposal
                    WHERE proposal.id = NEW.fleet_reallocation_proposal_id
                        AND proposal.tenant_id = NEW.tenant_id
                    FOR KEY SHARE;

                    IF NOT FOUND THEN
                        RAISE EXCEPTION 'Fleet reallocation decision scope mismatch' USING ERRCODE = '23514';
                    END IF;

                    PERFORM 1
                    FROM users
                    WHERE id = NEW.actor_user_id
                        AND tenant_id = NEW.tenant_id
                        AND agency_id IS NULL
                        AND is_active = true
                    FOR KEY SHARE;

                    IF NOT FOUND THEN
                        RAISE EXCEPTION 'Fleet reallocation decision actor mismatch' USING ERRCODE = '23514';
                    END IF;
                END IF;

                RETURN NEW;
            END;
            $$;

            CREATE TRIGGER fleet_reallocation_moves_scope_guard
            BEFORE INSERT ON fleet_reallocation_moves
            FOR EACH ROW EXECUTE FUNCTION enforce_fleet_reallocation_scope();

            CREATE TRIGGER fleet_reallocation_decisions_scope_guard
            BEFORE INSERT ON fleet_reallocation_decisions
            FOR EACH ROW EXECUTE FUNCTION enforce_fleet_reallocation_scope();

            CREATE OR REPLACE FUNCTION enforce_fleet_reallocation_completeness() RETURNS trigger
            LANGUAGE plpgsql AS $$
            DECLARE
                persisted_count integer;
                minimum_position integer;
                maximum_position integer;
                persisted_vehicles bigint;
                persisted_cost bigint;
            BEGIN
                SELECT count(*), min(row_position), max(row_position), sum(vehicles), sum(total_cost_centimes)
                INTO persisted_count, minimum_position, maximum_position, persisted_vehicles, persisted_cost
                FROM fleet_reallocation_moves
                WHERE fleet_reallocation_proposal_id = NEW.id
                    AND tenant_id = NEW.tenant_id;

                IF persisted_count <> NEW.move_line_count
                    OR minimum_position <> 0
                    OR maximum_position <> persisted_count - 1
                    OR persisted_vehicles <> NEW.relocated_vehicle_count
                    OR persisted_cost <> NEW.relocation_cost_centimes THEN
                    RAISE EXCEPTION 'Fleet reallocation proposal moves are incomplete' USING ERRCODE = '23514';
                END IF;

                RETURN NEW;
            END;
            $$;

            CREATE CONSTRAINT TRIGGER fleet_reallocation_proposals_completeness_guard
            AFTER INSERT ON fleet_reallocation_proposals
            DEFERRABLE INITIALLY DEFERRED
            FOR EACH ROW EXECUTE FUNCTION enforce_fleet_reallocation_completeness();

            CREATE OR REPLACE FUNCTION prevent_fleet_reallocation_mutation() RETURNS trigger
            LANGUAGE plpgsql AS $$
            BEGIN
                RAISE EXCEPTION 'Fleet reallocation evidence is append-only' USING ERRCODE = '23514';
            END;
            $$;

            CREATE TRIGGER fleet_reallocation_proposals_append_only
            BEFORE UPDATE OR DELETE ON fleet_reallocation_proposals
            FOR EACH ROW EXECUTE FUNCTION prevent_fleet_reallocation_mutation();

            CREATE TRIGGER fleet_reallocation_moves_append_only
            BEFORE UPDATE OR DELETE ON fleet_reallocation_moves
            FOR EACH ROW EXECUTE FUNCTION prevent_fleet_reallocation_mutation();

            CREATE TRIGGER fleet_reallocation_decisions_append_only
            BEFORE UPDATE OR DELETE ON fleet_reallocation_decisions
            FOR EACH ROW EXECUTE FUNCTION prevent_fleet_reallocation_mutation();
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS fleet_reallocation_decisions_append_only ON fleet_reallocation_decisions;
            DROP TRIGGER IF EXISTS fleet_reallocation_moves_append_only ON fleet_reallocation_moves;
            DROP TRIGGER IF EXISTS fleet_reallocation_proposals_append_only ON fleet_reallocation_proposals;
            DROP TRIGGER IF EXISTS fleet_reallocation_decisions_scope_guard ON fleet_reallocation_decisions;
            DROP TRIGGER IF EXISTS fleet_reallocation_moves_scope_guard ON fleet_reallocation_moves;
            DROP TRIGGER IF EXISTS fleet_reallocation_proposals_completeness_guard ON fleet_reallocation_proposals;
            DROP FUNCTION IF EXISTS prevent_fleet_reallocation_mutation();
            DROP FUNCTION IF EXISTS enforce_fleet_reallocation_completeness();
            DROP FUNCTION IF EXISTS enforce_fleet_reallocation_scope();
        SQL);

        Schema::dropIfExists('fleet_reallocation_decisions');
        Schema::dropIfExists('fleet_reallocation_moves');
        Schema::dropIfExists('fleet_reallocation_proposals');
    }
};
