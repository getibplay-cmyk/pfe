<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            DO $$
            BEGIN
                IF EXISTS (
                    SELECT 1 FROM vehicle_blocks
                    WHERE maintenance_order_id IS NOT NULL
                    GROUP BY maintenance_order_id HAVING count(*) > 1
                ) THEN
                    RAISE EXCEPTION 'Lot 06F-C1 blocked: duplicate maintenance blocks require review.' USING ERRCODE = '23514';
                END IF;

                IF EXISTS (
                    SELECT 1 FROM expenses
                    WHERE maintenance_order_id IS NOT NULL
                    GROUP BY maintenance_order_id HAVING count(*) > 1
                ) THEN
                    RAISE EXCEPTION 'Lot 06F-C1 blocked: duplicate maintenance expenses require review.' USING ERRCODE = '23514';
                END IF;

                IF EXISTS (
                    SELECT 1
                    FROM vehicle_blocks vb
                    JOIN maintenance_orders mo ON mo.id = vb.maintenance_order_id
                    WHERE vb.tenant_id <> mo.tenant_id
                       OR vb.agency_id <> mo.agency_id
                       OR vb.vehicle_id <> mo.vehicle_id
                       OR vb.block_type <> 'maintenance'
                ) THEN
                    RAISE EXCEPTION 'Lot 06F-C1 blocked: inconsistent maintenance blocks require review.' USING ERRCODE = '23514';
                END IF;

                IF EXISTS (
                    SELECT 1
                    FROM expenses e
                    JOIN maintenance_orders mo ON mo.id = e.maintenance_order_id
                    WHERE e.tenant_id <> mo.tenant_id
                       OR e.agency_id <> mo.agency_id
                       OR e.vehicle_id IS DISTINCT FROM mo.vehicle_id
                       OR e.category <> 'maintenance'
                ) THEN
                    RAISE EXCEPTION 'Lot 06F-C1 blocked: inconsistent maintenance expenses require review.' USING ERRCODE = '23514';
                END IF;
            END
            $$;

            CREATE UNIQUE INDEX vehicle_blocks_one_per_maintenance_unique
                ON vehicle_blocks (maintenance_order_id)
                WHERE maintenance_order_id IS NOT NULL;

            CREATE UNIQUE INDEX expenses_one_per_maintenance_unique
                ON expenses (maintenance_order_id)
                WHERE maintenance_order_id IS NOT NULL;

            CREATE OR REPLACE FUNCTION rentfleet_immutable_maintenance_history()
            RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION 'Maintenance histories are append-only.' USING ERRCODE = '23514';
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER maintenance_histories_append_only
            BEFORE UPDATE OR DELETE ON maintenance_status_histories
            FOR EACH ROW EXECUTE FUNCTION rentfleet_immutable_maintenance_history();

            CREATE OR REPLACE FUNCTION rentfleet_protect_maintenance_order()
            RETURNS trigger AS $$
            DECLARE
                transition_token text;
                expected_transition text;
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    IF OLD.status IN ('completed', 'cancelled') THEN
                        RAISE EXCEPTION 'Terminal maintenance orders are immutable.' USING ERRCODE = '23514';
                    END IF;

                    RETURN OLD;
                END IF;

                IF OLD.status IN ('completed', 'cancelled') THEN
                    RAISE EXCEPTION 'Terminal maintenance orders are immutable.' USING ERRCODE = '23514';
                END IF;

                IF NEW.tenant_id IS DISTINCT FROM OLD.tenant_id
                   OR NEW.agency_id IS DISTINCT FROM OLD.agency_id THEN
                    RAISE EXCEPTION 'Maintenance tenant and agency are immutable.' USING ERRCODE = '23514';
                END IF;

                IF OLD.status IN ('approved', 'in_progress')
                   AND NEW.vehicle_id IS DISTINCT FROM OLD.vehicle_id THEN
                    RAISE EXCEPTION 'Approved maintenance vehicle scope is immutable.' USING ERRCODE = '23514';
                END IF;

                IF NEW.status IS DISTINCT FROM OLD.status THEN
                    transition_token := COALESCE(current_setting('rentfleet.maintenance_transition', true), '');
                    expected_transition := OLD.status || '->' || NEW.status;

                    IF transition_token <> expected_transition
                       OR expected_transition NOT IN (
                           'planned->approved', 'planned->cancelled',
                           'approved->in_progress', 'approved->cancelled',
                           'in_progress->completed'
                       ) THEN
                        RAISE EXCEPTION 'Maintenance status transitions require a domain action.' USING ERRCODE = '23514';
                    END IF;

                    IF expected_transition = 'planned->approved'
                       AND (to_jsonb(NEW) - ARRAY['status','approved_by','updated_at'])
                           IS DISTINCT FROM (to_jsonb(OLD) - ARRAY['status','approved_by','updated_at']) THEN
                        RAISE EXCEPTION 'Unexpected fields changed during maintenance approval.' USING ERRCODE = '23514';
                    ELSIF expected_transition IN ('planned->cancelled', 'approved->cancelled')
                       AND (to_jsonb(NEW) - ARRAY['status','updated_at'])
                           IS DISTINCT FROM (to_jsonb(OLD) - ARRAY['status','updated_at']) THEN
                        RAISE EXCEPTION 'Unexpected fields changed during maintenance cancellation.' USING ERRCODE = '23514';
                    ELSIF expected_transition = 'approved->in_progress'
                       AND (to_jsonb(NEW) - ARRAY['status','actual_start_at','mileage_at_opening','updated_at'])
                           IS DISTINCT FROM (to_jsonb(OLD) - ARRAY['status','actual_start_at','mileage_at_opening','updated_at']) THEN
                        RAISE EXCEPTION 'Unexpected fields changed while starting maintenance.' USING ERRCODE = '23514';
                    ELSIF expected_transition = 'in_progress->completed'
                       AND (to_jsonb(NEW) - ARRAY['status','actual_end_at','actual_cost','next_due_date','next_due_mileage','completed_by','updated_at'])
                           IS DISTINCT FROM (to_jsonb(OLD) - ARRAY['status','actual_end_at','actual_cost','next_due_date','next_due_mileage','completed_by','updated_at']) THEN
                        RAISE EXCEPTION 'Unexpected fields changed while completing maintenance.' USING ERRCODE = '23514';
                    END IF;
                ELSIF OLD.status = 'approved'
                   AND (to_jsonb(NEW) - ARRAY['scheduled_start_at','scheduled_end_at','updated_at'])
                       IS DISTINCT FROM (to_jsonb(OLD) - ARRAY['scheduled_start_at','scheduled_end_at','updated_at']) THEN
                    RAISE EXCEPTION 'Only approved maintenance scheduling may be changed.' USING ERRCODE = '23514';
                ELSIF OLD.status = 'in_progress'
                   AND (to_jsonb(NEW) - ARRAY['updated_at'])
                       IS DISTINCT FROM (to_jsonb(OLD) - ARRAY['updated_at']) THEN
                    RAISE EXCEPTION 'In-progress maintenance business fields are immutable.' USING ERRCODE = '23514';
                ELSIF OLD.status = 'planned'
                   AND (
                       NEW.actual_start_at IS DISTINCT FROM OLD.actual_start_at
                       OR NEW.actual_end_at IS DISTINCT FROM OLD.actual_end_at
                       OR NEW.actual_cost IS DISTINCT FROM OLD.actual_cost
                       OR NEW.next_due_date IS DISTINCT FROM OLD.next_due_date
                       OR NEW.next_due_mileage IS DISTINCT FROM OLD.next_due_mileage
                       OR NEW.created_by IS DISTINCT FROM OLD.created_by
                       OR NEW.approved_by IS DISTINCT FROM OLD.approved_by
                       OR NEW.completed_by IS DISTINCT FROM OLD.completed_by
                       OR NEW.deleted_at IS DISTINCT FROM OLD.deleted_at
                   ) THEN
                    RAISE EXCEPTION 'Protected maintenance fields cannot be edited.' USING ERRCODE = '23514';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER maintenance_orders_cycle_immutability
            BEFORE UPDATE OR DELETE ON maintenance_orders
            FOR EACH ROW EXECUTE FUNCTION rentfleet_protect_maintenance_order();
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS maintenance_orders_cycle_immutability ON maintenance_orders;
            DROP FUNCTION IF EXISTS rentfleet_protect_maintenance_order();
            DROP TRIGGER IF EXISTS maintenance_histories_append_only ON maintenance_status_histories;
            DROP FUNCTION IF EXISTS rentfleet_immutable_maintenance_history();
            DROP INDEX IF EXISTS expenses_one_per_maintenance_unique;
            DROP INDEX IF EXISTS vehicle_blocks_one_per_maintenance_unique;
        SQL);
    }
};
