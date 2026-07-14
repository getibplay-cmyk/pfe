<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('maintenance_status_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('maintenance_order_id');
            $table->string('from_status')->nullable();
            $table->string('to_status');
            $table->text('reason')->nullable();
            $table->foreignId('changed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('created_at')->useCurrent();
            $table->foreign(['tenant_id', 'maintenance_order_id'])->references(['tenant_id', 'id'])->on('maintenance_orders')->cascadeOnDelete();
        });
        DB::statement("ALTER TABLE maintenance_status_histories ADD CONSTRAINT maintenance_histories_from_check CHECK (from_status IS NULL OR from_status IN ('planned', 'approved', 'in_progress', 'completed', 'cancelled'))");
        DB::statement("ALTER TABLE maintenance_status_histories ADD CONSTRAINT maintenance_histories_to_check CHECK (to_status IN ('planned', 'approved', 'in_progress', 'completed', 'cancelled'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('maintenance_status_histories');
    }
};
