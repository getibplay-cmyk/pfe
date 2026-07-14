<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('expenses', function (Blueprint $table) {
            $table->unsignedBigInteger('maintenance_order_id')->nullable();
            $table->foreign(['tenant_id', 'maintenance_order_id'])->references(['tenant_id', 'id'])->on('maintenance_orders')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('expenses', function (Blueprint $table) {
            $table->dropForeign(['tenant_id', 'maintenance_order_id']);
            $table->dropColumn('maintenance_order_id');
        });
    }
};
