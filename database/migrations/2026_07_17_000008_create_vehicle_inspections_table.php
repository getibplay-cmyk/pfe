<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vehicle_inspections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('agency_id');
            $table->unsignedBigInteger('rental_contract_id');
            $table->unsignedBigInteger('vehicle_id');
            $table->string('inspection_type');
            $table->string('status')->default('draft');
            $table->timestampTz('inspected_at');
            $table->unsignedBigInteger('mileage');
            $table->decimal('fuel_level', 5, 2);
            $table->text('notes')->nullable();
            $table->foreignId('completed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('completed_at')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->unique(['tenant_id', 'id']);
            $table->unique(['tenant_id', 'rental_contract_id', 'id'], 'vehicle_inspections_contract_id_unique');
            $table->index(['tenant_id', 'agency_id', 'inspection_type', 'status'], 'vehicle_inspections_lookup_idx');
            $table->foreign(['tenant_id', 'agency_id', 'vehicle_id', 'rental_contract_id'], 'vehicle_inspections_contract_scope_fk')->references(['tenant_id', 'agency_id', 'vehicle_id', 'id'])->on('rental_contracts');
        });

        DB::statement("CREATE UNIQUE INDEX vehicle_inspections_one_completed_departure_idx ON vehicle_inspections (tenant_id, rental_contract_id) WHERE inspection_type = 'departure' AND status = 'completed'");
        DB::statement("CREATE UNIQUE INDEX vehicle_inspections_one_completed_return_idx ON vehicle_inspections (tenant_id, rental_contract_id) WHERE inspection_type = 'return' AND status = 'completed'");
        DB::statement("ALTER TABLE vehicle_inspections ADD CONSTRAINT vehicle_inspections_type_check CHECK (inspection_type IN ('departure', 'return'))");
        DB::statement("ALTER TABLE vehicle_inspections ADD CONSTRAINT vehicle_inspections_status_check CHECK (status IN ('draft', 'completed'))");
        DB::statement('ALTER TABLE vehicle_inspections ADD CONSTRAINT vehicle_inspections_values_check CHECK (mileage >= 0 AND fuel_level BETWEEN 0 AND 100)');
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION rentfleet_prevent_completed_inspection_change() RETURNS trigger AS $$
            BEGIN
                IF OLD.status = 'completed' THEN
                    RAISE EXCEPTION 'completed inspections are immutable' USING ERRCODE = '23514';
                END IF;
                IF TG_OP = 'DELETE' THEN RETURN OLD; END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;
            CREATE TRIGGER vehicle_inspections_prevent_completed_update
            BEFORE UPDATE ON vehicle_inspections
            FOR EACH ROW EXECUTE FUNCTION rentfleet_prevent_completed_inspection_change();
            CREATE TRIGGER vehicle_inspections_prevent_completed_delete
            BEFORE DELETE ON vehicle_inspections
            FOR EACH ROW EXECUTE FUNCTION rentfleet_prevent_completed_inspection_change();
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('vehicle_inspections');
        DB::statement('DROP FUNCTION IF EXISTS rentfleet_prevent_completed_inspection_change()');
    }
};
