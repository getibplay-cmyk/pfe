<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE users ADD CONSTRAINT users_tenant_agency_scope_fk FOREIGN KEY (tenant_id, agency_id) REFERENCES agencies (tenant_id, id)');
        DB::statement('ALTER TABLE customers ADD CONSTRAINT customers_tenant_agency_scope_fk FOREIGN KEY (tenant_id, agency_id) REFERENCES agencies (tenant_id, id)');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE customers DROP CONSTRAINT IF EXISTS customers_tenant_agency_scope_fk');
        DB::statement('ALTER TABLE users DROP CONSTRAINT IF EXISTS users_tenant_agency_scope_fk');
    }
};
