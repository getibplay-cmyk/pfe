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
                    SELECT 1
                    FROM reservations r
                    JOIN customers c ON c.tenant_id = r.tenant_id AND c.id = r.customer_id
                    WHERE c.agency_id IS DISTINCT FROM r.agency_id
                ) THEN
                    RAISE EXCEPTION 'Lot 06F-A blocked: reservations contain customers from another agency; manual cleanup is required.' USING ERRCODE = '23514';
                END IF;

                IF EXISTS (
                    SELECT 1
                    FROM reservations r
                    JOIN vehicles v ON v.tenant_id = r.tenant_id AND v.id = r.vehicle_id
                    WHERE r.vehicle_id IS NOT NULL
                      AND (v.agency_id IS DISTINCT FROM r.agency_id OR v.vehicle_category_id IS DISTINCT FROM r.vehicle_category_id)
                ) THEN
                    RAISE EXCEPTION 'Lot 06F-A blocked: reservations contain vehicles from another agency or category; manual cleanup is required.' USING ERRCODE = '23514';
                END IF;

                IF EXISTS (
                    SELECT 1
                    FROM expenses e
                    JOIN rental_contracts c ON c.tenant_id = e.tenant_id AND c.id = e.rental_contract_id
                    WHERE e.rental_contract_id IS NOT NULL
                      AND (c.agency_id IS DISTINCT FROM e.agency_id OR c.currency IS DISTINCT FROM e.currency)
                ) THEN
                    RAISE EXCEPTION 'Lot 06F-A blocked: expenses contain contracts from another agency or currency; manual cleanup is required.' USING ERRCODE = '23514';
                END IF;

                IF EXISTS (
                    SELECT 1
                    FROM expenses e
                    JOIN maintenance_orders m ON m.tenant_id = e.tenant_id AND m.id = e.maintenance_order_id
                    WHERE e.maintenance_order_id IS NOT NULL
                      AND m.agency_id IS DISTINCT FROM e.agency_id
                ) THEN
                    RAISE EXCEPTION 'Lot 06F-A blocked: expenses contain maintenance orders from another agency; manual cleanup is required.' USING ERRCODE = '23514';
                END IF;
            END
            $$;
        SQL);

        DB::statement('ALTER TABLE customers ADD CONSTRAINT customers_tenant_agency_id_unique UNIQUE (tenant_id, agency_id, id)');
        DB::statement('ALTER TABLE vehicles ADD CONSTRAINT vehicles_reservation_scope_unique UNIQUE (tenant_id, agency_id, vehicle_category_id, id)');
        DB::statement('ALTER TABLE rental_contracts ADD CONSTRAINT rental_contracts_expense_scope_unique UNIQUE (tenant_id, agency_id, id, currency)');
        DB::statement('ALTER TABLE maintenance_orders ADD CONSTRAINT maintenance_orders_expense_scope_unique UNIQUE (tenant_id, agency_id, id)');

        DB::statement('ALTER TABLE reservations ADD CONSTRAINT reservations_customer_agency_fk FOREIGN KEY (tenant_id, agency_id, customer_id) REFERENCES customers (tenant_id, agency_id, id) ON DELETE RESTRICT');
        DB::statement('ALTER TABLE reservations ADD CONSTRAINT reservations_vehicle_category_scope_fk FOREIGN KEY (tenant_id, agency_id, vehicle_category_id, vehicle_id) REFERENCES vehicles (tenant_id, agency_id, vehicle_category_id, id) ON DELETE RESTRICT');
        DB::statement('ALTER TABLE expenses ADD CONSTRAINT expenses_contract_scope_currency_fk FOREIGN KEY (tenant_id, agency_id, rental_contract_id, currency) REFERENCES rental_contracts (tenant_id, agency_id, id, currency) ON DELETE RESTRICT');
        DB::statement('ALTER TABLE expenses ADD CONSTRAINT expenses_maintenance_agency_fk FOREIGN KEY (tenant_id, agency_id, maintenance_order_id) REFERENCES maintenance_orders (tenant_id, agency_id, id) ON DELETE RESTRICT');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE expenses DROP CONSTRAINT IF EXISTS expenses_maintenance_agency_fk');
        DB::statement('ALTER TABLE expenses DROP CONSTRAINT IF EXISTS expenses_contract_scope_currency_fk');
        DB::statement('ALTER TABLE reservations DROP CONSTRAINT IF EXISTS reservations_vehicle_category_scope_fk');
        DB::statement('ALTER TABLE reservations DROP CONSTRAINT IF EXISTS reservations_customer_agency_fk');

        DB::statement('ALTER TABLE maintenance_orders DROP CONSTRAINT IF EXISTS maintenance_orders_expense_scope_unique');
        DB::statement('ALTER TABLE rental_contracts DROP CONSTRAINT IF EXISTS rental_contracts_expense_scope_unique');
        DB::statement('ALTER TABLE vehicles DROP CONSTRAINT IF EXISTS vehicles_reservation_scope_unique');
        DB::statement('ALTER TABLE customers DROP CONSTRAINT IF EXISTS customers_tenant_agency_id_unique');
    }
};
