<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('agency_id')->nullable()->constrained()->nullOnDelete();
            $table->string('customer_type');
            $table->string('first_name')->nullable();
            $table->string('last_name')->nullable();
            $table->string('company_name')->nullable();
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->text('address')->nullable();
            $table->string('city')->nullable();
            $table->string('nationality')->nullable();
            $table->date('birth_date')->nullable();
            $table->string('identity_type')->nullable();
            $table->text('identity_number_encrypted')->nullable();
            $table->string('identity_number_hash', 64)->nullable();
            $table->string('verification_status')->default('pending');
            $table->text('notes')->nullable();
            $table->jsonb('custom_values')->default('{}');
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['tenant_id', 'id']);
            $table->index(['tenant_id', 'agency_id']);
            $table->index(['tenant_id', 'identity_number_hash']);
            $table->index(['tenant_id', 'customer_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customers');
    }
};
