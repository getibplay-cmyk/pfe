<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('damage_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('agency_id');
            $table->unsignedBigInteger('rental_contract_id');
            $table->unsignedBigInteger('vehicle_id');
            $table->unsignedBigInteger('departure_inspection_id')->nullable();
            $table->unsignedBigInteger('return_inspection_id');
            $table->string('damage_number');
            $table->text('description');
            $table->string('vehicle_area')->nullable();
            $table->string('severity');
            $table->string('status')->default('reported');
            $table->string('responsibility')->default('pending');
            $table->decimal('estimated_cost', 14, 2)->default(0);
            $table->decimal('approved_cost', 14, 2)->nullable();
            $table->foreignId('reported_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('reviewed_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'damage_number']);
            $table->unique(['tenant_id', 'id']);
            $table->unique(['tenant_id', 'rental_contract_id', 'id'], 'damage_reports_contract_id_unique');
            $table->index(['tenant_id', 'agency_id', 'status']);
            $table->foreign(['tenant_id', 'agency_id', 'vehicle_id', 'rental_contract_id'], 'damage_reports_contract_scope_fk')->references(['tenant_id', 'agency_id', 'vehicle_id', 'id'])->on('rental_contracts');
            $table->foreign(['tenant_id', 'rental_contract_id', 'departure_inspection_id'], 'damage_reports_departure_inspection_fk')->references(['tenant_id', 'rental_contract_id', 'id'])->on('vehicle_inspections');
            $table->foreign(['tenant_id', 'rental_contract_id', 'return_inspection_id'], 'damage_reports_return_inspection_fk')->references(['tenant_id', 'rental_contract_id', 'id'])->on('vehicle_inspections');
        });

        DB::statement("ALTER TABLE damage_reports ADD CONSTRAINT damage_reports_severity_check CHECK (severity IN ('minor', 'moderate', 'major', 'critical'))");
        DB::statement("ALTER TABLE damage_reports ADD CONSTRAINT damage_reports_status_check CHECK (status IN ('reported', 'under_review', 'resolved', 'dismissed'))");
        DB::statement("ALTER TABLE damage_reports ADD CONSTRAINT damage_reports_responsibility_check CHECK (responsibility IN ('pending', 'customer', 'agency', 'insurance', 'unknown'))");
        DB::statement('ALTER TABLE damage_reports ADD CONSTRAINT damage_reports_amounts_check CHECK (estimated_cost >= 0 AND (approved_cost IS NULL OR approved_cost >= 0))');
        DB::statement('ALTER TABLE contract_charges ADD CONSTRAINT contract_charges_damage_report_fk FOREIGN KEY (tenant_id, rental_contract_id, damage_report_id) REFERENCES damage_reports (tenant_id, rental_contract_id, id)');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE contract_charges DROP CONSTRAINT IF EXISTS contract_charges_damage_report_fk');
        Schema::dropIfExists('damage_reports');
    }
};
