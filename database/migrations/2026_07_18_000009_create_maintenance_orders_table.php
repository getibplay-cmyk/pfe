<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('maintenance_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('agency_id');
            $table->unsignedBigInteger('vehicle_id');
            $table->string('maintenance_number');
            $table->string('maintenance_type');
            $table->string('priority')->default('normal');
            $table->string('status')->default('planned');
            $table->string('title');
            $table->text('description')->nullable();
            $table->timestampTz('scheduled_start_at')->nullable();
            $table->timestampTz('scheduled_end_at')->nullable();
            $table->timestampTz('actual_start_at')->nullable();
            $table->timestampTz('actual_end_at')->nullable();
            $table->unsignedBigInteger('mileage_at_opening')->nullable();
            $table->decimal('estimated_cost', 14, 2)->default(0);
            $table->decimal('actual_cost', 14, 2)->default(0);
            $table->string('supplier')->nullable();
            $table->date('next_due_date')->nullable();
            $table->unsignedBigInteger('next_due_mileage')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('completed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'maintenance_number']);
            $table->unique(['tenant_id', 'id']);
            $table->unique(['tenant_id', 'agency_id', 'vehicle_id', 'id'], 'maintenance_orders_scope_unique');
            $table->index(['tenant_id', 'agency_id', 'status']);
            $table->foreign(['tenant_id', 'agency_id', 'vehicle_id'])->references(['tenant_id', 'agency_id', 'id'])->on('vehicles')->restrictOnDelete();
        });
        DB::statement("ALTER TABLE maintenance_orders ADD CONSTRAINT maintenance_orders_type_check CHECK (maintenance_type IN ('preventive', 'corrective', 'inspection', 'repair'))");
        DB::statement("ALTER TABLE maintenance_orders ADD CONSTRAINT maintenance_orders_priority_check CHECK (priority IN ('low', 'normal', 'high', 'critical'))");
        DB::statement("ALTER TABLE maintenance_orders ADD CONSTRAINT maintenance_orders_status_check CHECK (status IN ('planned', 'approved', 'in_progress', 'completed', 'cancelled'))");
        DB::statement('ALTER TABLE maintenance_orders ADD CONSTRAINT maintenance_orders_period_check CHECK (scheduled_end_at IS NULL OR (scheduled_start_at IS NOT NULL AND scheduled_end_at > scheduled_start_at))');
        DB::statement('ALTER TABLE maintenance_orders ADD CONSTRAINT maintenance_orders_cost_check CHECK (estimated_cost >= 0 AND actual_cost >= 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('maintenance_orders');
    }
};
