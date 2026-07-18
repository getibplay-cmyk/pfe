<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('CREATE INDEX reservations_reporting_created_idx ON reservations (tenant_id, agency_id, created_at) WHERE deleted_at IS NULL');
        DB::statement('CREATE INDEX reservation_status_histories_reporting_events_idx ON reservation_status_histories (tenant_id, created_at, to_status, reservation_id)');
        DB::statement('CREATE INDEX rental_contracts_reporting_returns_idx ON rental_contracts (tenant_id, agency_id, expected_return_at, status) WHERE deleted_at IS NULL');
        DB::statement("CREATE INDEX vehicle_blocks_reporting_period_idx ON vehicle_blocks (tenant_id, agency_id, starts_at, ends_at, block_type) WHERE status = 'active'");
        DB::statement("CREATE INDEX invoices_reporting_issued_idx ON invoices (tenant_id, agency_id, issued_at, currency) WHERE issued_at IS NOT NULL AND status <> 'void' AND deleted_at IS NULL");
        DB::statement("CREATE INDEX payments_reporting_posted_idx ON payments (tenant_id, agency_id, posted_at, currency, id) WHERE status IN ('posted', 'reversed')");
        DB::statement('CREATE INDEX deposit_transactions_reporting_occurred_idx ON deposit_transactions (tenant_id, agency_id, occurred_at, currency)');
        DB::statement('CREATE INDEX expenses_reporting_date_idx ON expenses (tenant_id, agency_id, expense_date, status, currency) WHERE deleted_at IS NULL');
        DB::statement("CREATE INDEX maintenance_orders_reporting_schedule_idx ON maintenance_orders (tenant_id, agency_id, scheduled_start_at, status) WHERE deleted_at IS NULL AND status IN ('planned', 'approved', 'in_progress')");
        DB::statement('CREATE INDEX insurance_claims_reporting_open_idx ON insurance_claims (tenant_id, agency_id, reported_at, status)');
        DB::statement('CREATE INDEX documents_reporting_expiry_idx ON documents (tenant_id, agency_id, retention_until) WHERE deleted_at IS NULL AND retention_until IS NOT NULL');
        DB::statement('CREATE INDEX drivers_reporting_licence_expiry_idx ON drivers (tenant_id, licence_expires_at, customer_id) WHERE deleted_at IS NULL');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS drivers_reporting_licence_expiry_idx');
        DB::statement('DROP INDEX IF EXISTS documents_reporting_expiry_idx');
        DB::statement('DROP INDEX IF EXISTS insurance_claims_reporting_open_idx');
        DB::statement('DROP INDEX IF EXISTS maintenance_orders_reporting_schedule_idx');
        DB::statement('DROP INDEX IF EXISTS expenses_reporting_date_idx');
        DB::statement('DROP INDEX IF EXISTS deposit_transactions_reporting_occurred_idx');
        DB::statement('DROP INDEX IF EXISTS payments_reporting_posted_idx');
        DB::statement('DROP INDEX IF EXISTS invoices_reporting_issued_idx');
        DB::statement('DROP INDEX IF EXISTS vehicle_blocks_reporting_period_idx');
        DB::statement('DROP INDEX IF EXISTS rental_contracts_reporting_returns_idx');
        DB::statement('DROP INDEX IF EXISTS reservation_status_histories_reporting_events_idx');
        DB::statement('DROP INDEX IF EXISTS reservations_reporting_created_idx');
    }
};
