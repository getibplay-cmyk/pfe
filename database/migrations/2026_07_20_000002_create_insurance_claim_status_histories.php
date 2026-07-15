<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE insurance_claims ADD CONSTRAINT insurance_claims_history_scope_unique UNIQUE (tenant_id, agency_id, id)');

        Schema::create('insurance_claim_status_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('agency_id');
            $table->unsignedBigInteger('insurance_claim_id');
            $table->string('from_status')->nullable();
            $table->string('to_status');
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->text('note')->nullable();
            $table->timestampTz('changed_at')->useCurrent();

            $table->index(['tenant_id', 'agency_id', 'insurance_claim_id'], 'insurance_claim_histories_scope_idx');
            $table->foreign(['tenant_id', 'agency_id', 'insurance_claim_id'], 'insurance_claim_histories_claim_scope_fk')
                ->references(['tenant_id', 'agency_id', 'id'])->on('insurance_claims')->restrictOnDelete();
        });

        DB::statement("ALTER TABLE insurance_claim_status_histories ADD CONSTRAINT insurance_claim_histories_from_status_check CHECK (from_status IS NULL OR from_status IN ('reported', 'submitted', 'under_review', 'approved', 'rejected', 'settled', 'closed'))");
        DB::statement("ALTER TABLE insurance_claim_status_histories ADD CONSTRAINT insurance_claim_histories_to_status_check CHECK (to_status IN ('reported', 'submitted', 'under_review', 'approved', 'rejected', 'settled', 'closed'))");
        DB::statement("INSERT INTO insurance_claim_status_histories (tenant_id, agency_id, insurance_claim_id, from_status, to_status, actor_id, note, changed_at) SELECT tenant_id, agency_id, id, NULL, status, created_by, 'Reprise de l''état existant lors du Lot 06B', reported_at FROM insurance_claims");

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION rentfleet_immutable_claim_status_history() RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION 'insurance claim status histories are immutable' USING ERRCODE = '23514';
            END;
            $$ LANGUAGE plpgsql;
            CREATE TRIGGER insurance_claim_histories_immutable
            BEFORE UPDATE OR DELETE ON insurance_claim_status_histories
            FOR EACH ROW EXECUTE FUNCTION rentfleet_immutable_claim_status_history();

            CREATE OR REPLACE FUNCTION rentfleet_enforce_insurance_claim_state() RETURNS trigger AS $$
            DECLARE transition_allowed boolean;
            BEGIN
                IF TG_OP = 'INSERT' THEN
                    IF NEW.status <> 'reported' OR NEW.approved_amount IS NOT NULL OR NEW.settled_amount IS NOT NULL THEN
                        RAISE EXCEPTION 'insurance claims must start as reported without decision amounts' USING ERRCODE = '23514';
                    END IF;
                    RETURN NEW;
                END IF;

                IF NEW.status IS DISTINCT FROM OLD.status THEN
                    transition_allowed := CASE
                        WHEN OLD.status = 'reported' AND NEW.status IN ('submitted', 'under_review') THEN true
                        WHEN OLD.status = 'submitted' AND NEW.status = 'under_review' THEN true
                        WHEN OLD.status = 'under_review' AND NEW.status IN ('approved', 'rejected') THEN true
                        WHEN OLD.status = 'approved' AND NEW.status = 'settled' THEN true
                        WHEN OLD.status = 'settled' AND NEW.status = 'closed' THEN true
                        ELSE false
                    END;
                    IF NOT transition_allowed THEN
                        RAISE EXCEPTION 'invalid insurance claim status transition' USING ERRCODE = '23514';
                    END IF;
                END IF;

                IF NEW.approved_amount IS DISTINCT FROM OLD.approved_amount
                   AND NOT (OLD.status = 'under_review' AND NEW.status = 'approved') THEN
                    RAISE EXCEPTION 'approved amount may only be set during approval' USING ERRCODE = '23514';
                END IF;
                IF NEW.settled_amount IS DISTINCT FROM OLD.settled_amount
                   AND NOT (OLD.status = 'approved' AND NEW.status = 'settled') THEN
                    RAISE EXCEPTION 'settled amount may only be set during settlement' USING ERRCODE = '23514';
                END IF;
                IF NEW.status IN ('reported', 'submitted', 'under_review', 'rejected')
                   AND (NEW.approved_amount IS NOT NULL OR NEW.settled_amount IS NOT NULL) THEN
                    RAISE EXCEPTION 'decision amounts are incompatible with this claim status' USING ERRCODE = '23514';
                END IF;
                IF NEW.status = 'approved' AND (NEW.approved_amount IS NULL OR NEW.settled_amount IS NOT NULL) THEN
                    RAISE EXCEPTION 'approved claims require only an approved amount' USING ERRCODE = '23514';
                END IF;
                IF NEW.status IN ('settled', 'closed')
                   AND (NEW.approved_amount IS NULL OR NEW.settled_amount IS NULL OR NEW.settled_amount > NEW.approved_amount) THEN
                    RAISE EXCEPTION 'settled claims require coherent approved and settled amounts' USING ERRCODE = '23514';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;
            CREATE TRIGGER insurance_claims_state_machine
            BEFORE INSERT OR UPDATE ON insurance_claims
            FOR EACH ROW EXECUTE FUNCTION rentfleet_enforce_insurance_claim_state();
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS insurance_claims_state_machine ON insurance_claims');
        DB::statement('DROP FUNCTION IF EXISTS rentfleet_enforce_insurance_claim_state()');
        Schema::dropIfExists('insurance_claim_status_histories');
        DB::statement('DROP FUNCTION IF EXISTS rentfleet_immutable_claim_status_history()');
        DB::statement('ALTER TABLE insurance_claims DROP CONSTRAINT IF EXISTS insurance_claims_history_scope_unique');
    }
};
