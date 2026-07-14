<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rental_contracts', function (Blueprint $table) {
            $table->unsignedBigInteger('invoice_id')->nullable();
            $table->decimal('amount_paid', 14, 2)->default(0);
            $table->decimal('balance_due', 14, 2)->default(0);
            $table->decimal('deposit_received', 14, 2)->default(0);
            $table->decimal('deposit_retained', 14, 2)->default(0);
            $table->decimal('deposit_refunded', 14, 2)->default(0);
            $table->timestampTz('financially_settled_at')->nullable();
            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreign(['tenant_id', 'invoice_id'])->references(['tenant_id', 'id'])->on('invoices')->restrictOnDelete();
        });

        DB::statement('ALTER TABLE rental_contracts ADD CONSTRAINT rental_contracts_financial_summaries_check CHECK (amount_paid >= 0 AND balance_due >= 0 AND deposit_received >= 0 AND deposit_retained >= 0 AND deposit_refunded >= 0)');
    }

    public function down(): void
    {
        Schema::table('rental_contracts', function (Blueprint $table) {
            $table->dropForeign(['closed_by']);
            $table->dropForeign(['tenant_id', 'invoice_id']);
            $table->dropColumn(['invoice_id', 'amount_paid', 'balance_due', 'deposit_received', 'deposit_retained', 'deposit_refunded', 'financially_settled_at', 'closed_by']);
        });
    }
};
