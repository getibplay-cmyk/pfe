<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            DO $$
            BEGIN
                IF EXISTS (
                    SELECT 1
                    FROM vehicle_blocks
                    WHERE block_type = 'manual'
                      AND (
                          reservation_id IS NOT NULL
                          OR rental_contract_id IS NOT NULL
                          OR maintenance_order_id IS NOT NULL
                          OR reason IS NULL
                          OR btrim(reason) = ''
                          OR created_by IS NULL
                      )
                ) THEN
                    RAISE EXCEPTION 'Lot 06F-B2 blocked: invalid manual vehicle blocks require review.' USING ERRCODE = '23514';
                END IF;

                IF EXISTS (
                    SELECT 1
                    FROM vehicle_blocks
                    WHERE (status = 'active' AND released_at IS NOT NULL)
                       OR (status IN ('released', 'cancelled') AND released_at IS NULL)
                ) THEN
                    RAISE EXCEPTION 'Lot 06F-B2 blocked: inconsistent vehicle block release dates require review.' USING ERRCODE = '23514';
                END IF;

                IF EXISTS (SELECT 1 FROM expenses WHERE status = 'rejected') THEN
                    RAISE EXCEPTION 'Lot 06F-B2 blocked: historical rejected expenses require review.' USING ERRCODE = '23514';
                END IF;
            END
            $$;
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE vehicle_blocks
            ADD CONSTRAINT vehicle_blocks_manual_source_check
            CHECK (
                block_type <> 'manual'
                OR (
                    reservation_id IS NULL
                    AND rental_contract_id IS NULL
                    AND maintenance_order_id IS NULL
                    AND reason IS NOT NULL
                    AND btrim(reason) <> ''
                    AND created_by IS NOT NULL
                )
            )
        SQL);
        DB::statement(<<<'SQL'
            ALTER TABLE vehicle_blocks
            ADD CONSTRAINT vehicle_blocks_release_state_check
            CHECK (
                (status = 'active' AND released_at IS NULL)
                OR (status IN ('released', 'cancelled') AND released_at IS NOT NULL)
            )
        SQL);

        Schema::table('expenses', function (Blueprint $table) {
            $table->foreignId('rejected_by')->nullable()->after('approved_by')->constrained('users')->restrictOnDelete();
            $table->timestampTz('rejected_at')->nullable()->after('rejected_by');
            $table->text('rejection_reason')->nullable()->after('rejected_at');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE expenses
            ADD CONSTRAINT expenses_rejection_state_check
            CHECK (
                (
                    status = 'rejected'
                    AND rejected_by IS NOT NULL
                    AND rejected_at IS NOT NULL
                    AND rejection_reason IS NOT NULL
                    AND btrim(rejection_reason) <> ''
                    AND approved_by IS NULL
                )
                OR (
                    status <> 'rejected'
                    AND rejected_by IS NULL
                    AND rejected_at IS NULL
                    AND rejection_reason IS NULL
                )
            )
        SQL);

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION expenses_prevent_terminal_mutation()
            RETURNS trigger AS $$
            BEGIN
                IF TG_OP = 'DELETE' AND OLD.status IN ('approved', 'rejected') THEN
                    RAISE EXCEPTION 'Terminal expenses cannot be deleted.' USING ERRCODE = '23514';
                END IF;

                IF TG_OP = 'UPDATE' AND OLD.status IN ('approved', 'rejected') THEN
                    RAISE EXCEPTION 'Terminal expenses cannot be mutated.' USING ERRCODE = '23514';
                END IF;

                IF TG_OP = 'UPDATE'
                   AND OLD.status = 'draft'
                   AND NEW.status IN ('approved', 'rejected')
                   AND (
                       NEW.amount IS DISTINCT FROM OLD.amount
                       OR NEW.tax_amount IS DISTINCT FROM OLD.tax_amount
                       OR NEW.currency IS DISTINCT FROM OLD.currency
                   ) THEN
                    RAISE EXCEPTION 'Expense amounts cannot change during a terminal transition.' USING ERRCODE = '23514';
                END IF;

                RETURN COALESCE(NEW, OLD);
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER expenses_terminal_immutability
            BEFORE UPDATE OR DELETE ON expenses
            FOR EACH ROW EXECUTE FUNCTION expenses_prevent_terminal_mutation();
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS expenses_terminal_immutability ON expenses');
        DB::statement('DROP FUNCTION IF EXISTS expenses_prevent_terminal_mutation()');
        DB::statement('ALTER TABLE expenses DROP CONSTRAINT IF EXISTS expenses_rejection_state_check');

        Schema::table('expenses', function (Blueprint $table) {
            $table->dropForeign(['rejected_by']);
            $table->dropColumn(['rejected_by', 'rejected_at', 'rejection_reason']);
        });

        DB::statement('ALTER TABLE vehicle_blocks DROP CONSTRAINT IF EXISTS vehicle_blocks_release_state_check');
        DB::statement('ALTER TABLE vehicle_blocks DROP CONSTRAINT IF EXISTS vehicle_blocks_manual_source_check');
    }
};
