<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('insurance_policies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('agency_id');
            $table->unsignedBigInteger('vehicle_id');
            $table->unsignedBigInteger('insurance_company_id');
            $table->text('policy_number_encrypted');
            $table->char('policy_number_hash', 64);
            $table->string('policy_type');
            $table->date('starts_at');
            $table->date('ends_at');
            $table->decimal('premium_amount', 14, 2)->default(0);
            $table->decimal('deductible_amount', 14, 2)->default(0);
            $table->char('currency', 3)->default('MAD');
            $table->string('status')->default('draft');
            $table->foreignId('document_id')->nullable()->constrained('documents')->nullOnDelete();
            $table->timestamps();

            $table->unique(['tenant_id', 'policy_number_hash']);
            $table->unique(['tenant_id', 'id']);
            $table->unique(['tenant_id', 'agency_id', 'id'], 'insurance_policies_agency_scope_unique');
            $table->index(['tenant_id', 'agency_id', 'status', 'ends_at']);
            $table->foreign(['tenant_id', 'agency_id', 'vehicle_id'])->references(['tenant_id', 'agency_id', 'id'])->on('vehicles')->restrictOnDelete();
            $table->foreign(['tenant_id', 'insurance_company_id'])->references(['tenant_id', 'id'])->on('insurance_companies')->restrictOnDelete();
        });
        DB::statement("ALTER TABLE insurance_policies ADD CONSTRAINT insurance_policies_type_check CHECK (policy_type IN ('mandatory_liability', 'comprehensive', 'third_party', 'other'))");
        DB::statement("ALTER TABLE insurance_policies ADD CONSTRAINT insurance_policies_status_check CHECK (status IN ('draft', 'active', 'expired', 'cancelled'))");
        DB::statement('ALTER TABLE insurance_policies ADD CONSTRAINT insurance_policies_period_check CHECK (ends_at >= starts_at)');
        DB::statement('ALTER TABLE insurance_policies ADD CONSTRAINT insurance_policies_amounts_check CHECK (premium_amount >= 0 AND deductible_amount >= 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('insurance_policies');
    }
};
