<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agencies', fn (Blueprint $table) => $table->unique(['tenant_id', 'id']));
        Schema::create('vehicles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('agency_id');
            $table->unsignedBigInteger('vehicle_category_id');
            $table->string('registration_number');
            $table->string('vin')->nullable();
            $table->string('brand');
            $table->string('model');
            $table->unsignedSmallInteger('production_year')->nullable();
            $table->string('fuel_type');
            $table->string('transmission');
            $table->string('color')->nullable();
            $table->unsignedBigInteger('current_mileage')->default(0);
            $table->string('operational_status')->default('active');
            $table->date('first_registration_date')->nullable();
            $table->jsonb('custom_values')->default('{}');
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['tenant_id', 'registration_number']);
            $table->unique(['tenant_id', 'vin']);
            $table->unique(['tenant_id', 'id']);
            $table->index(['tenant_id', 'agency_id', 'operational_status']);
            $table->foreign(['tenant_id', 'agency_id'])->references(['tenant_id', 'id'])->on('agencies');
            $table->foreign(['tenant_id', 'vehicle_category_id'])->references(['tenant_id', 'id'])->on('vehicle_categories');
        });
        DB::statement('ALTER TABLE vehicles ADD CONSTRAINT vehicles_production_year_check CHECK (production_year IS NULL OR production_year BETWEEN 1900 AND 2100)');
    }

    public function down(): void
    {
        Schema::dropIfExists('vehicles');
        Schema::table('agencies', fn (Blueprint $table) => $table->dropUnique(['tenant_id', 'id']));
    }
};
