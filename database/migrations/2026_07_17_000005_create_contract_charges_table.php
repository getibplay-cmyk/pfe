<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contract_charges', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('rental_contract_id');
            $table->unsignedBigInteger('damage_report_id')->nullable();
            $table->string('charge_type');
            $table->text('description');
            $table->decimal('quantity', 12, 2);
            $table->decimal('unit_amount', 14, 2);
            $table->decimal('total_amount', 14, 2);
            $table->string('status')->default('proposed');
            $table->jsonb('calculation_details')->default('{}');
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('approved_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'rental_contract_id', 'status']);
            $table->foreign(['tenant_id', 'rental_contract_id'])->references(['tenant_id', 'id'])->on('rental_contracts')->cascadeOnDelete();
        });

        DB::statement("ALTER TABLE contract_charges ADD CONSTRAINT contract_charges_type_check CHECK (charge_type IN ('base_rental', 'late_fee', 'extra_kilometre', 'missing_fuel', 'cleaning', 'damage', 'other'))");
        DB::statement("ALTER TABLE contract_charges ADD CONSTRAINT contract_charges_status_check CHECK (status IN ('proposed', 'approved', 'rejected'))");
        DB::statement('ALTER TABLE contract_charges ADD CONSTRAINT contract_charges_amounts_check CHECK (quantity >= 0 AND unit_amount >= 0 AND total_amount >= 0 AND total_amount = ROUND(quantity * unit_amount, 2))');
    }

    public function down(): void
    {
        Schema::dropIfExists('contract_charges');
    }
};
