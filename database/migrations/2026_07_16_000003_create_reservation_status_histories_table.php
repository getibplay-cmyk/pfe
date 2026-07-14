<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reservation_status_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('reservation_id');
            $table->string('from_status')->nullable();
            $table->string('to_status');
            $table->text('reason')->nullable();
            $table->foreignId('changed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('created_at')->useCurrent();

            $table->index(['tenant_id', 'reservation_id', 'created_at'], 'reservation_status_history_idx');
            $table->foreign(['tenant_id', 'reservation_id'])->references(['tenant_id', 'id'])->on('reservations')->cascadeOnDelete();
        });

        DB::statement("ALTER TABLE reservation_status_histories ADD CONSTRAINT reservation_status_histories_to_status_check CHECK (to_status IN ('draft', 'pending', 'confirmed', 'converted', 'cancelled', 'expired'))");
        DB::statement("ALTER TABLE reservation_status_histories ADD CONSTRAINT reservation_status_histories_from_status_check CHECK (from_status IS NULL OR from_status IN ('draft', 'pending', 'confirmed', 'converted', 'cancelled', 'expired'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('reservation_status_histories');
    }
};
