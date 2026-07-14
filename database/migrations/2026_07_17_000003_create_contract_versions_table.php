<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contract_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('rental_contract_id');
            $table->unsignedInteger('version_number');
            $table->jsonb('terms_snapshot');
            $table->jsonb('pricing_snapshot');
            $table->jsonb('customer_snapshot');
            $table->jsonb('vehicle_snapshot');
            $table->char('content_hash', 64);
            $table->text('change_reason')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestampTz('locked_at')->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->unique(['tenant_id', 'rental_contract_id', 'version_number'], 'contract_versions_number_unique');
            $table->unique(['tenant_id', 'id']);
            $table->unique(['tenant_id', 'rental_contract_id', 'id'], 'contract_versions_contract_id_unique');
            $table->foreign(['tenant_id', 'rental_contract_id'])->references(['tenant_id', 'id'])->on('rental_contracts')->cascadeOnDelete();
        });

        DB::statement("ALTER TABLE contract_versions ADD CONSTRAINT contract_versions_hash_check CHECK (content_hash ~ '^[0-9a-f]{64}$')");
        DB::statement('ALTER TABLE rental_contracts ADD CONSTRAINT rental_contracts_current_version_fk FOREIGN KEY (tenant_id, id, current_version_id) REFERENCES contract_versions (tenant_id, rental_contract_id, id)');
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION rentfleet_prevent_locked_contract_version_change() RETURNS trigger AS $$
            BEGIN
                IF OLD.locked_at IS NOT NULL THEN
                    RAISE EXCEPTION 'locked contract versions are immutable' USING ERRCODE = '23514';
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;
            CREATE TRIGGER contract_versions_prevent_locked_update
            BEFORE UPDATE ON contract_versions
            FOR EACH ROW EXECUTE FUNCTION rentfleet_prevent_locked_contract_version_change();
            CREATE TRIGGER contract_versions_prevent_locked_delete
            BEFORE DELETE ON contract_versions
            FOR EACH ROW EXECUTE FUNCTION rentfleet_prevent_locked_contract_version_change();
        SQL);
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE rental_contracts DROP CONSTRAINT IF EXISTS rental_contracts_current_version_fk');
        Schema::dropIfExists('contract_versions');
        DB::statement('DROP FUNCTION IF EXISTS rentfleet_prevent_locked_contract_version_change()');
    }
};
