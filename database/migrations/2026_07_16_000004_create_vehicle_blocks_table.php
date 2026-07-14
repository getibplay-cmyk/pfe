<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vehicle_blocks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('agency_id');
            $table->unsignedBigInteger('vehicle_id');
            $table->unsignedBigInteger('reservation_id')->nullable();
            $table->string('block_type');
            $table->timestampTz('starts_at');
            $table->timestampTz('ends_at');
            $table->string('status')->default('active');
            $table->text('reason')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('released_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'vehicle_id', 'status']);
            $table->index(['tenant_id', 'starts_at', 'ends_at']);
            $table->foreign(['tenant_id', 'agency_id'])->references(['tenant_id', 'id'])->on('agencies');
            $table->foreign(['tenant_id', 'agency_id', 'vehicle_id'])->references(['tenant_id', 'agency_id', 'id'])->on('vehicles');
            $table->foreign(['tenant_id', 'reservation_id'])->references(['tenant_id', 'id'])->on('reservations');
        });

        DB::statement('ALTER TABLE vehicle_blocks ADD CONSTRAINT vehicle_blocks_period_check CHECK (ends_at > starts_at)');
        DB::statement("ALTER TABLE vehicle_blocks ADD CONSTRAINT vehicle_blocks_type_check CHECK (block_type IN ('reservation', 'manual', 'contract', 'maintenance'))");
        DB::statement("ALTER TABLE vehicle_blocks ADD CONSTRAINT vehicle_blocks_status_check CHECK (status IN ('active', 'released', 'cancelled'))");
        DB::statement("CREATE UNIQUE INDEX vehicle_blocks_one_active_reservation_idx ON vehicle_blocks (tenant_id, reservation_id) WHERE reservation_id IS NOT NULL AND block_type = 'reservation' AND status = 'active'");
    }

    public function down(): void
    {
        Schema::dropIfExists('vehicle_blocks');
    }
};
