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
                IF EXISTS (SELECT 1 FROM customers WHERE agency_id IS NULL) THEN
                    RAISE EXCEPTION 'Lot 06F-B1 blocked: customers without an agency require manual review.' USING ERRCODE = '23514';
                END IF;

                IF EXISTS (
                    SELECT 1
                    FROM drivers
                    WHERE deleted_at IS NULL AND is_primary = true
                    GROUP BY tenant_id, customer_id
                    HAVING count(*) > 1
                ) THEN
                    RAISE EXCEPTION 'Lot 06F-B1 blocked: a customer has several active primary drivers.' USING ERRCODE = '23514';
                END IF;

                IF EXISTS (SELECT 1 FROM drivers WHERE licence_issued_at IS NOT NULL AND licence_issued_at > licence_expires_at) THEN
                    RAISE EXCEPTION 'Lot 06F-B1 blocked: invalid driving licence dates require manual review.' USING ERRCODE = '23514';
                END IF;
            END
            $$;
        SQL);

        DB::statement('ALTER TABLE customers ALTER COLUMN agency_id SET NOT NULL');
        DB::statement("ALTER TABLE customers ADD CONSTRAINT customers_verification_status_check CHECK (verification_status IN ('pending', 'verified', 'rejected'))");
        DB::statement("ALTER TABLE drivers ADD CONSTRAINT drivers_verification_status_check CHECK (verification_status IN ('pending', 'verified', 'rejected'))");
        DB::statement('ALTER TABLE drivers ADD CONSTRAINT drivers_licence_dates_check CHECK (licence_issued_at IS NULL OR licence_issued_at <= licence_expires_at)');
        DB::statement('CREATE UNIQUE INDEX drivers_one_active_primary_per_customer_idx ON drivers (tenant_id, customer_id) WHERE is_primary = true AND deleted_at IS NULL');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS drivers_one_active_primary_per_customer_idx');
        DB::statement('ALTER TABLE drivers DROP CONSTRAINT IF EXISTS drivers_licence_dates_check');
        DB::statement('ALTER TABLE drivers DROP CONSTRAINT IF EXISTS drivers_verification_status_check');
        DB::statement('ALTER TABLE customers DROP CONSTRAINT IF EXISTS customers_verification_status_check');
        DB::statement('ALTER TABLE customers ALTER COLUMN agency_id DROP NOT NULL');
    }
};
