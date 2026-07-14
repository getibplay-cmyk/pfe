<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('agency_id');
            $table->unsignedBigInteger('rental_contract_id')->nullable();
            $table->unsignedBigInteger('customer_id');
            $table->string('payment_number');
            $table->string('direction');
            $table->string('payment_method');
            $table->string('status')->default('pending');
            $table->decimal('amount', 14, 2);
            $table->char('currency', 3)->default('MAD');
            $table->string('external_reference')->nullable();
            $table->string('idempotency_key');
            $table->timestampTz('paid_at')->nullable();
            $table->timestampTz('posted_at')->nullable();
            $table->unsignedBigInteger('reversal_of_id')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('posted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['tenant_id', 'payment_number']);
            $table->unique(['tenant_id', 'idempotency_key']);
            $table->unique(['tenant_id', 'id']);
            $table->unique(['tenant_id', 'reversal_of_id']);
            $table->index(['tenant_id', 'agency_id', 'status']);
            $table->foreign(['tenant_id', 'agency_id'])->references(['tenant_id', 'id'])->on('agencies');
            $table->foreign(['tenant_id', 'customer_id'])->references(['tenant_id', 'id'])->on('customers')->restrictOnDelete();
            $table->foreign(['tenant_id', 'rental_contract_id'])->references(['tenant_id', 'id'])->on('rental_contracts')->restrictOnDelete();
            $table->foreign(['tenant_id', 'reversal_of_id'])->references(['tenant_id', 'id'])->on('payments')->restrictOnDelete();
        });

        DB::statement("ALTER TABLE payments ADD CONSTRAINT payments_direction_check CHECK (direction IN ('incoming', 'outgoing'))");
        DB::statement("ALTER TABLE payments ADD CONSTRAINT payments_method_check CHECK (payment_method IN ('cash', 'card', 'bank_transfer', 'cheque', 'other'))");
        DB::statement("ALTER TABLE payments ADD CONSTRAINT payments_status_check CHECK (status IN ('pending', 'posted', 'reversed', 'void'))");
        DB::statement("ALTER TABLE payments ADD CONSTRAINT payments_currency_check CHECK (currency ~ '^[A-Z]{3}$')");
        DB::statement('ALTER TABLE payments ADD CONSTRAINT payments_amount_check CHECK (amount > 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
