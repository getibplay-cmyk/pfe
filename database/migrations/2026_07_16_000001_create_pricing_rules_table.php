<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pricing_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('agency_id')->nullable();
            $table->unsignedBigInteger('vehicle_category_id');
            $table->string('name');
            $table->decimal('daily_rate', 14, 2);
            $table->decimal('deposit_amount', 14, 2)->default(0);
            $table->unsignedInteger('included_km_per_day')->nullable();
            $table->decimal('extra_km_rate', 14, 2)->nullable();
            $table->decimal('late_hour_rate', 14, 2)->nullable();
            $table->unsignedSmallInteger('minimum_days')->default(1);
            $table->unsignedSmallInteger('maximum_days')->nullable();
            $table->date('valid_from');
            $table->date('valid_to')->nullable();
            $table->integer('priority')->default(0);
            $table->char('currency', 3)->default('MAD');
            $table->jsonb('conditions')->default('{}');
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'id']);
            $table->index(['tenant_id', 'agency_id', 'vehicle_category_id', 'is_active'], 'pricing_rules_resolution_idx');
            $table->index(['tenant_id', 'valid_from', 'valid_to'], 'pricing_rules_validity_idx');
            $table->foreign(['tenant_id', 'agency_id'])->references(['tenant_id', 'id'])->on('agencies');
            $table->foreign(['tenant_id', 'vehicle_category_id'])->references(['tenant_id', 'id'])->on('vehicle_categories');
        });

        DB::statement('ALTER TABLE pricing_rules ADD CONSTRAINT pricing_rules_amounts_non_negative_check CHECK (daily_rate >= 0 AND deposit_amount >= 0 AND (extra_km_rate IS NULL OR extra_km_rate >= 0) AND (late_hour_rate IS NULL OR late_hour_rate >= 0))');
        DB::statement('ALTER TABLE pricing_rules ADD CONSTRAINT pricing_rules_validity_check CHECK (valid_to IS NULL OR valid_to >= valid_from)');
        DB::statement('ALTER TABLE pricing_rules ADD CONSTRAINT pricing_rules_day_limits_check CHECK (minimum_days >= 1 AND (maximum_days IS NULL OR maximum_days >= minimum_days))');
        DB::statement("ALTER TABLE pricing_rules ADD CONSTRAINT pricing_rules_currency_check CHECK (currency ~ '^[A-Z]{3}$')");
    }

    public function down(): void
    {
        Schema::dropIfExists('pricing_rules');
    }
};
