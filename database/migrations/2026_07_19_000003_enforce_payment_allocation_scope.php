<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE payment_allocations ADD COLUMN agency_id BIGINT');
        DB::statement('ALTER TABLE payment_allocations ADD COLUMN customer_id BIGINT');
        DB::statement('ALTER TABLE payment_allocations ADD COLUMN currency CHAR(3)');
        DB::statement('ALTER TABLE payment_allocations DISABLE TRIGGER payment_allocations_financial_immutability');
        DB::statement('UPDATE payment_allocations AS allocation SET agency_id = payment.agency_id, customer_id = payment.customer_id, currency = payment.currency FROM payments AS payment WHERE payment.id = allocation.payment_id AND payment.tenant_id = allocation.tenant_id');
        DB::statement('ALTER TABLE payment_allocations ENABLE TRIGGER payment_allocations_financial_immutability');
        DB::statement('ALTER TABLE payment_allocations ALTER COLUMN agency_id SET NOT NULL');
        DB::statement('ALTER TABLE payment_allocations ALTER COLUMN customer_id SET NOT NULL');
        DB::statement('ALTER TABLE payment_allocations ALTER COLUMN currency SET NOT NULL');

        DB::statement('ALTER TABLE payments ADD CONSTRAINT payments_allocation_scope_unique UNIQUE (tenant_id, agency_id, customer_id, currency, id)');
        DB::statement('ALTER TABLE invoices ADD CONSTRAINT invoices_allocation_scope_unique UNIQUE (tenant_id, agency_id, customer_id, currency, id)');
        DB::statement('ALTER TABLE payment_allocations ADD CONSTRAINT payment_allocations_payment_scope_fk FOREIGN KEY (tenant_id, agency_id, customer_id, currency, payment_id) REFERENCES payments (tenant_id, agency_id, customer_id, currency, id) ON DELETE RESTRICT');
        DB::statement('ALTER TABLE payment_allocations ADD CONSTRAINT payment_allocations_invoice_scope_fk FOREIGN KEY (tenant_id, agency_id, customer_id, currency, invoice_id) REFERENCES invoices (tenant_id, agency_id, customer_id, currency, id) ON DELETE RESTRICT');
        DB::statement('CREATE INDEX payment_allocations_scope_idx ON payment_allocations (tenant_id, agency_id, customer_id)');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS payment_allocations_scope_idx');
        DB::statement('ALTER TABLE payment_allocations DROP CONSTRAINT IF EXISTS payment_allocations_invoice_scope_fk');
        DB::statement('ALTER TABLE payment_allocations DROP CONSTRAINT IF EXISTS payment_allocations_payment_scope_fk');
        DB::statement('ALTER TABLE invoices DROP CONSTRAINT IF EXISTS invoices_allocation_scope_unique');
        DB::statement('ALTER TABLE payments DROP CONSTRAINT IF EXISTS payments_allocation_scope_unique');
        DB::statement('ALTER TABLE payment_allocations DROP COLUMN currency');
        DB::statement('ALTER TABLE payment_allocations DROP COLUMN customer_id');
        DB::statement('ALTER TABLE payment_allocations DROP COLUMN agency_id');
    }
};
