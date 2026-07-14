<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoice_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('invoice_id');
            $table->string('source_type');
            $table->unsignedBigInteger('source_id')->nullable();
            $table->string('line_type');
            $table->text('description');
            $table->decimal('quantity', 12, 2);
            $table->decimal('unit_amount', 14, 2);
            $table->decimal('subtotal', 14, 2);
            $table->decimal('tax_rate', 7, 4)->default(0);
            $table->decimal('tax_amount', 14, 2)->default(0);
            $table->decimal('total_amount', 14, 2);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['tenant_id', 'invoice_id', 'source_type', 'source_id'], 'invoice_lines_unique_source');
            $table->foreign(['tenant_id', 'invoice_id'])->references(['tenant_id', 'id'])->on('invoices')->cascadeOnDelete();
        });

        DB::statement("ALTER TABLE invoice_lines ADD CONSTRAINT invoice_lines_type_check CHECK (line_type IN ('rental', 'late_fee', 'extra_kilometre', 'fuel', 'cleaning', 'damage', 'other'))");
        DB::statement('ALTER TABLE invoice_lines ADD CONSTRAINT invoice_lines_amounts_check CHECK (quantity > 0 AND unit_amount >= 0 AND subtotal >= 0 AND tax_rate >= 0 AND tax_rate <= 100 AND tax_amount >= 0 AND total_amount >= 0 AND total_amount = subtotal + tax_amount)');
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_lines');
    }
};
