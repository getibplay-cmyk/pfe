<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE contract_versions ADD COLUMN agency_id BIGINT');
        DB::statement('ALTER TABLE contract_versions ADD COLUMN document_id BIGINT');
        DB::statement('UPDATE contract_versions AS version SET agency_id = contract.agency_id FROM rental_contracts AS contract WHERE contract.id = version.rental_contract_id AND contract.tenant_id = version.tenant_id');
        DB::statement('ALTER TABLE contract_versions ALTER COLUMN agency_id SET NOT NULL');
        DB::statement('ALTER TABLE documents ADD CONSTRAINT documents_contract_version_scope_unique UNIQUE (tenant_id, agency_id, id)');
        DB::statement('ALTER TABLE contract_versions ADD CONSTRAINT contract_versions_contract_agency_fk FOREIGN KEY (tenant_id, agency_id, rental_contract_id) REFERENCES rental_contracts (tenant_id, agency_id, id) ON DELETE CASCADE');
        DB::statement('ALTER TABLE contract_versions ADD CONSTRAINT contract_versions_document_scope_fk FOREIGN KEY (tenant_id, agency_id, document_id) REFERENCES documents (tenant_id, agency_id, id) ON DELETE RESTRICT');
        DB::statement('CREATE INDEX contract_versions_document_idx ON contract_versions (tenant_id, agency_id, document_id)');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS contract_versions_document_idx');
        DB::statement('ALTER TABLE contract_versions DROP CONSTRAINT IF EXISTS contract_versions_document_scope_fk');
        DB::statement('ALTER TABLE contract_versions DROP CONSTRAINT IF EXISTS contract_versions_contract_agency_fk');
        DB::statement('ALTER TABLE documents DROP CONSTRAINT IF EXISTS documents_contract_version_scope_unique');
        DB::statement('ALTER TABLE contract_versions DROP COLUMN document_id');
        DB::statement('ALTER TABLE contract_versions DROP COLUMN agency_id');
    }
};
