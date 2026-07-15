<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE rental_contracts ADD CONSTRAINT rental_contracts_claim_scope_unique UNIQUE (tenant_id, agency_id, id)');
        DB::statement('ALTER TABLE damage_reports ADD CONSTRAINT damage_reports_claim_scope_unique UNIQUE (tenant_id, agency_id, rental_contract_id, id)');
        DB::statement('ALTER TABLE insurance_claims DROP CONSTRAINT insurance_claims_damage_report_id_foreign');
        DB::statement('ALTER TABLE insurance_claims ADD CONSTRAINT insurance_claims_contract_agency_fk FOREIGN KEY (tenant_id, agency_id, rental_contract_id) REFERENCES rental_contracts (tenant_id, agency_id, id) ON DELETE RESTRICT');
        DB::statement('ALTER TABLE insurance_claims ADD CONSTRAINT insurance_claims_damage_scope_fk FOREIGN KEY (tenant_id, agency_id, rental_contract_id, damage_report_id) REFERENCES damage_reports (tenant_id, agency_id, rental_contract_id, id) ON DELETE RESTRICT');
        DB::statement('ALTER TABLE insurance_claims ADD CONSTRAINT insurance_claims_damage_requires_contract_check CHECK (damage_report_id IS NULL OR rental_contract_id IS NOT NULL)');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE insurance_claims DROP CONSTRAINT IF EXISTS insurance_claims_damage_requires_contract_check');
        DB::statement('ALTER TABLE insurance_claims DROP CONSTRAINT IF EXISTS insurance_claims_damage_scope_fk');
        DB::statement('ALTER TABLE insurance_claims DROP CONSTRAINT IF EXISTS insurance_claims_contract_agency_fk');
        DB::statement('ALTER TABLE insurance_claims ADD CONSTRAINT insurance_claims_damage_report_id_foreign FOREIGN KEY (damage_report_id) REFERENCES damage_reports (id) ON DELETE RESTRICT');
        DB::statement('ALTER TABLE damage_reports DROP CONSTRAINT IF EXISTS damage_reports_claim_scope_unique');
        DB::statement('ALTER TABLE rental_contracts DROP CONSTRAINT IF EXISTS rental_contracts_claim_scope_unique');
    }
};
