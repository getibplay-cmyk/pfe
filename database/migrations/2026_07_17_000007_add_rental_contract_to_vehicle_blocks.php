<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vehicle_blocks', function (Blueprint $table) {
            $table->unsignedBigInteger('rental_contract_id')->nullable()->after('reservation_id');
            $table->index(['tenant_id', 'rental_contract_id']);
            $table->foreign(['tenant_id', 'agency_id', 'vehicle_id', 'rental_contract_id'], 'vehicle_blocks_rental_contract_scope_fk')->references(['tenant_id', 'agency_id', 'vehicle_id', 'id'])->on('rental_contracts');
        });
    }

    public function down(): void
    {
        Schema::table('vehicle_blocks', function (Blueprint $table) {
            $table->dropForeign('vehicle_blocks_rental_contract_scope_fk');
            $table->dropIndex(['tenant_id', 'rental_contract_id']);
            $table->dropColumn('rental_contract_id');
        });
    }
};
