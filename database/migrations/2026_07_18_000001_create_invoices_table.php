<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('agency_id');
            $table->unsignedBigInteger('rental_contract_id');
            $table->unsignedBigInteger('customer_id');
            $table->string('invoice_number');
            $table->string('status')->default('draft');
            $table->timestampTz('issued_at')->nullable();
            $table->timestampTz('due_at')->nullable();
            $table->char('currency', 3)->default('MAD');
            $table->string('tax_mode')->default('none');
            $table->decimal('tax_rate', 7, 4)->default(0);
            $table->decimal('subtotal', 14, 2);
            $table->decimal('tax_amount', 14, 2)->default(0);
            $table->decimal('total_amount', 14, 2);
            $table->decimal('paid_amount', 14, 2)->default(0);
            $table->decimal('balance_due', 14, 2);
            $table->jsonb('customer_snapshot');
            $table->jsonb('contract_snapshot');
            $table->char('content_hash', 64)->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('issued_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'invoice_number']);
            $table->unique(['tenant_id', 'id']);
            $table->index(['tenant_id', 'agency_id', 'status']);
            $table->foreign(['tenant_id', 'agency_id'])->references(['tenant_id', 'id'])->on('agencies');
            $table->foreign(['tenant_id', 'rental_contract_id'])->references(['tenant_id', 'id'])->on('rental_contracts')->restrictOnDelete();
            $table->foreign(['tenant_id', 'customer_id'])->references(['tenant_id', 'id'])->on('customers')->restrictOnDelete();
        });

        DB::statement("CREATE UNIQUE INDEX invoices_one_current_per_contract_idx ON invoices (tenant_id, rental_contract_id) WHERE status <> 'void' AND deleted_at IS NULL");
        DB::statement("ALTER TABLE invoices ADD CONSTRAINT invoices_status_check CHECK (status IN ('draft', 'issued', 'partially_paid', 'paid', 'void'))");
        DB::statement("ALTER TABLE invoices ADD CONSTRAINT invoices_tax_mode_check CHECK (tax_mode IN ('none', 'inclusive', 'exclusive'))");
        DB::statement("ALTER TABLE invoices ADD CONSTRAINT invoices_currency_check CHECK (currency ~ '^[A-Z]{3}$')");
        DB::statement('ALTER TABLE invoices ADD CONSTRAINT invoices_amounts_check CHECK (subtotal >= 0 AND tax_amount >= 0 AND total_amount >= 0 AND paid_amount >= 0 AND balance_due >= 0 AND paid_amount <= total_amount AND balance_due = total_amount - paid_amount)');
        DB::statement('ALTER TABLE invoices ADD CONSTRAINT invoices_tax_rate_check CHECK (tax_rate >= 0 AND tax_rate <= 100)');
    }

    public function down(): void
    {
        Schema::dropIfExists('invoices');
    }
};
