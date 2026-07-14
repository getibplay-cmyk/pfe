<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('expenses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('agency_id');
            $table->unsignedBigInteger('vehicle_id')->nullable();
            $table->unsignedBigInteger('rental_contract_id')->nullable();
            $table->string('expense_number');
            $table->string('category');
            $table->text('description');
            $table->decimal('amount', 14, 2);
            $table->decimal('tax_amount', 14, 2)->default(0);
            $table->char('currency', 3)->default('MAD');
            $table->date('expense_date');
            $table->string('supplier')->nullable();
            $table->foreignId('document_id')->nullable()->constrained('documents')->nullOnDelete();
            $table->string('status')->default('draft');
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'expense_number']);
            $table->unique(['tenant_id', 'id']);
            $table->index(['tenant_id', 'agency_id', 'status']);
            $table->foreign(['tenant_id', 'agency_id'])->references(['tenant_id', 'id'])->on('agencies');
            $table->foreign(['tenant_id', 'agency_id', 'vehicle_id'])->references(['tenant_id', 'agency_id', 'id'])->on('vehicles')->restrictOnDelete();
            $table->foreign(['tenant_id', 'rental_contract_id'])->references(['tenant_id', 'id'])->on('rental_contracts')->restrictOnDelete();
        });

        DB::statement("ALTER TABLE expenses ADD CONSTRAINT expenses_category_check CHECK (category IN ('maintenance', 'insurance', 'fuel', 'cleaning', 'administration', 'other'))");
        DB::statement("ALTER TABLE expenses ADD CONSTRAINT expenses_status_check CHECK (status IN ('draft', 'approved', 'rejected'))");
        DB::statement("ALTER TABLE expenses ADD CONSTRAINT expenses_currency_check CHECK (currency ~ '^[A-Z]{3}$')");
        DB::statement('ALTER TABLE expenses ADD CONSTRAINT expenses_amounts_check CHECK (amount > 0 AND tax_amount >= 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('expenses');
    }
};
