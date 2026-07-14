<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('payment_id');
            $table->unsignedBigInteger('invoice_id');
            $table->decimal('amount', 14, 2);
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['tenant_id', 'payment_id', 'invoice_id']);
            $table->foreign(['tenant_id', 'payment_id'])->references(['tenant_id', 'id'])->on('payments')->restrictOnDelete();
            $table->foreign(['tenant_id', 'invoice_id'])->references(['tenant_id', 'id'])->on('invoices')->restrictOnDelete();
        });

        DB::statement('ALTER TABLE payment_allocations ADD CONSTRAINT payment_allocations_amount_check CHECK (amount > 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_allocations');
    }
};
