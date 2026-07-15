<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE document_versions ADD CONSTRAINT document_versions_document_scope_unique UNIQUE (tenant_id, document_id, id)');
        DB::statement('ALTER TABLE documents DROP CONSTRAINT documents_current_version_id_foreign');
        DB::statement('ALTER TABLE documents ADD CONSTRAINT documents_current_version_scope_fk FOREIGN KEY (tenant_id, id, current_version_id) REFERENCES document_versions (tenant_id, document_id, id) ON DELETE RESTRICT');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE documents DROP CONSTRAINT IF EXISTS documents_current_version_scope_fk');
        DB::statement('ALTER TABLE documents ADD CONSTRAINT documents_current_version_id_foreign FOREIGN KEY (current_version_id) REFERENCES document_versions (id) ON DELETE SET NULL');
        DB::statement('ALTER TABLE document_versions DROP CONSTRAINT IF EXISTS document_versions_document_scope_unique');
    }
};
