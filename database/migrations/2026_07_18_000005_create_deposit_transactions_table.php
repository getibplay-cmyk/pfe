<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('deposit_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('agency_id');
            $table->unsignedBigInteger('rental_contract_id');
            $table->string('transaction_number');
            $table->string('transaction_type');
            $table->decimal('amount', 14, 2);
            $table->char('currency', 3)->default('MAD');
            $table->unsignedBigInteger('payment_id')->nullable();
            $table->unsignedBigInteger('related_charge_id')->nullable();
            $table->unsignedBigInteger('reversal_of_id')->nullable();
            $table->string('idempotency_key');
            $table->timestampTz('occurred_at');
            $table->text('reason')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['tenant_id', 'transaction_number']);
            $table->unique(['tenant_id', 'idempotency_key']);
            $table->unique(['tenant_id', 'id']);
            $table->unique(['tenant_id', 'reversal_of_id']);
            $table->index(['tenant_id', 'rental_contract_id', 'occurred_at']);
            $table->foreign(['tenant_id', 'agency_id'])->references(['tenant_id', 'id'])->on('agencies');
            $table->foreign(['tenant_id', 'rental_contract_id'])->references(['tenant_id', 'id'])->on('rental_contracts')->restrictOnDelete();
            $table->foreign(['tenant_id', 'payment_id'])->references(['tenant_id', 'id'])->on('payments')->restrictOnDelete();
            $table->foreign(['tenant_id', 'reversal_of_id'])->references(['tenant_id', 'id'])->on('deposit_transactions')->restrictOnDelete();
            $table->foreign('related_charge_id')->references('id')->on('contract_charges')->restrictOnDelete();
        });

        DB::statement("ALTER TABLE deposit_transactions ADD CONSTRAINT deposit_transactions_type_check CHECK (transaction_type IN ('received', 'retained', 'refunded', 'adjustment_in', 'adjustment_out', 'reversal'))");
        DB::statement("ALTER TABLE deposit_transactions ADD CONSTRAINT deposit_transactions_currency_check CHECK (currency ~ '^[A-Z]{3}$')");
        DB::statement('ALTER TABLE deposit_transactions ADD CONSTRAINT deposit_transactions_amount_check CHECK (amount > 0)');
        DB::statement("ALTER TABLE deposit_transactions ADD CONSTRAINT deposit_transactions_reversal_check CHECK ((transaction_type = 'reversal') = (reversal_of_id IS NOT NULL))");
    }

    public function down(): void
    {
        Schema::dropIfExists('deposit_transactions');
    }
};
