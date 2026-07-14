<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('insurance_claims', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('agency_id');
            $table->unsignedBigInteger('insurance_policy_id');
            $table->foreignId('damage_report_id')->nullable()->constrained('damage_reports')->restrictOnDelete();
            $table->unsignedBigInteger('rental_contract_id')->nullable();
            $table->string('claim_number');
            $table->string('status')->default('reported');
            $table->timestampTz('reported_at');
            $table->timestampTz('submitted_at')->nullable();
            $table->timestampTz('reviewed_at')->nullable();
            $table->decimal('claimed_amount', 14, 2);
            $table->decimal('approved_amount', 14, 2)->nullable();
            $table->decimal('settled_amount', 14, 2)->nullable();
            $table->text('insurer_reference_encrypted')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->unique(['tenant_id', 'claim_number']);
            $table->index(['tenant_id', 'agency_id', 'status']);
            $table->foreign(['tenant_id', 'agency_id', 'insurance_policy_id'])->references(['tenant_id', 'agency_id', 'id'])->on('insurance_policies')->restrictOnDelete();
            $table->foreign(['tenant_id', 'rental_contract_id'])->references(['tenant_id', 'id'])->on('rental_contracts')->restrictOnDelete();
        });
        DB::statement("ALTER TABLE insurance_claims ADD CONSTRAINT insurance_claims_status_check CHECK (status IN ('reported', 'submitted', 'under_review', 'approved', 'rejected', 'settled', 'closed'))");
        DB::statement('ALTER TABLE insurance_claims ADD CONSTRAINT insurance_claims_amounts_check CHECK (claimed_amount >= 0 AND (approved_amount IS NULL OR approved_amount >= 0) AND (settled_amount IS NULL OR settled_amount >= 0))');
    }

    public function down(): void
    {
        Schema::dropIfExists('insurance_claims');
    }
};
