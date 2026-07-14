<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('insurance_policy_coverages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('insurance_policy_id');
            $table->string('coverage_type');
            $table->string('label');
            $table->decimal('limit_amount', 14, 2)->nullable();
            $table->decimal('deductible_amount', 14, 2)->nullable();
            $table->jsonb('terms')->default('{}');
            $table->timestamps();
            $table->unique(['tenant_id', 'insurance_policy_id', 'coverage_type', 'label'], 'insurance_coverages_unique');
            $table->foreign(['tenant_id', 'insurance_policy_id'])->references(['tenant_id', 'id'])->on('insurance_policies')->cascadeOnDelete();
        });
        DB::statement("ALTER TABLE insurance_policy_coverages ADD CONSTRAINT insurance_coverages_type_check CHECK (coverage_type IN ('liability', 'collision', 'theft', 'fire', 'glass', 'assistance', 'legal_defence', 'other'))");
        DB::statement('ALTER TABLE insurance_policy_coverages ADD CONSTRAINT insurance_coverages_amounts_check CHECK ((limit_amount IS NULL OR limit_amount >= 0) AND (deductible_amount IS NULL OR deductible_amount >= 0))');
    }

    public function down(): void
    {
        Schema::dropIfExists('insurance_policy_coverages');
    }
};
