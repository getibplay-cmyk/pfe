<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contract_drivers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('rental_contract_id');
            $table->unsignedBigInteger('customer_id');
            $table->unsignedBigInteger('driver_id');
            $table->boolean('is_primary')->default(false);
            $table->jsonb('authorization_snapshot')->default('{}');
            $table->timestamps();

            $table->unique(['tenant_id', 'rental_contract_id', 'driver_id'], 'contract_drivers_contract_driver_unique');
            $table->foreign(['tenant_id', 'customer_id', 'rental_contract_id'], 'contract_drivers_contract_customer_fk')->references(['tenant_id', 'customer_id', 'id'])->on('rental_contracts')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'customer_id', 'driver_id'], 'contract_drivers_driver_customer_fk')->references(['tenant_id', 'customer_id', 'id'])->on('drivers');
        });

        DB::statement('CREATE UNIQUE INDEX contract_drivers_one_primary_idx ON contract_drivers (tenant_id, rental_contract_id) WHERE is_primary = true');
    }

    public function down(): void
    {
        Schema::dropIfExists('contract_drivers');
    }
};
