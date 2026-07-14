<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vehicle_blocks', function (Blueprint $table) {
            $table->unsignedBigInteger('maintenance_order_id')->nullable();
            $table->foreign(['tenant_id', 'agency_id', 'vehicle_id', 'maintenance_order_id'], 'vehicle_blocks_maintenance_scope_fk')->references(['tenant_id', 'agency_id', 'vehicle_id', 'id'])->on('maintenance_orders')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('vehicle_blocks', function (Blueprint $table) {
            $table->dropForeign('vehicle_blocks_maintenance_scope_fk');
            $table->dropColumn('maintenance_order_id');
        });
    }
};
