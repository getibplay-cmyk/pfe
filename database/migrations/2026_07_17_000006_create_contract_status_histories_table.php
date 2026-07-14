<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contract_status_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('rental_contract_id');
            $table->string('from_status')->nullable();
            $table->string('to_status');
            $table->text('reason')->nullable();
            $table->foreignId('changed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('created_at')->useCurrent();

            $table->index(['tenant_id', 'rental_contract_id', 'created_at'], 'contract_status_histories_lookup_idx');
            $table->foreign(['tenant_id', 'rental_contract_id'])->references(['tenant_id', 'id'])->on('rental_contracts')->cascadeOnDelete();
        });

        DB::statement("ALTER TABLE contract_status_histories ADD CONSTRAINT contract_status_histories_to_check CHECK (to_status IN ('draft', 'ready', 'accepted', 'active', 'return_pending', 'returned', 'closed', 'cancelled'))");
        DB::statement("ALTER TABLE contract_status_histories ADD CONSTRAINT contract_status_histories_from_check CHECK (from_status IS NULL OR from_status IN ('draft', 'ready', 'accepted', 'active', 'return_pending', 'returned', 'closed', 'cancelled'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('contract_status_histories');
    }
};
