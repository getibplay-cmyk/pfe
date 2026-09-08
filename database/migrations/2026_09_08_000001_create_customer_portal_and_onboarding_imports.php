<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_portal_accesses', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignId('agency_id');
            $table->foreignId('customer_id');
            $table->foreignId('issued_by')->constrained('users')->restrictOnDelete();
            $table->timestampTz('expires_at');
            $table->timestampTz('consumed_at')->nullable();
            $table->timestampTz('revoked_at')->nullable();
            $table->timestampTz('session_expires_at')->nullable();
            $table->string('session_hash', 64)->nullable();
            $table->timestampsTz();
            $table->unique(['tenant_id', 'id']);
            $table->index(['tenant_id', 'customer_id', 'revoked_at']);
            $table->foreign(['tenant_id', 'customer_id'])->references(['tenant_id', 'id'])->on('customers')->restrictOnDelete();
            $table->foreign(['tenant_id', 'agency_id'])->references(['tenant_id', 'id'])->on('agencies')->restrictOnDelete();
            $table->foreign(['tenant_id', 'issued_by'])->references(['tenant_id', 'id'])->on('users')->restrictOnDelete();
        });
        Schema::create('customer_portal_uploads', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->uuid('access_id');
            $table->foreignId('customer_id');
            $table->foreignId('document_id');
            $table->timestampsTz();
            $table->foreign(['tenant_id', 'access_id'])->references(['tenant_id', 'id'])->on('customer_portal_accesses')->restrictOnDelete();
            $table->foreign(['tenant_id', 'document_id'])->references(['tenant_id', 'id'])->on('documents')->restrictOnDelete();
            $table->foreign(['tenant_id', 'customer_id'])->references(['tenant_id', 'id'])->on('customers')->restrictOnDelete();
            $table->index(['tenant_id', 'customer_id', 'created_at']);
        });
        Schema::create('onboarding_imports', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignId('agency_id');
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->string('kind', 20);
            $table->text('payload')->nullable();
            $table->unsignedInteger('row_count');
            $table->timestampTz('expires_at');
            $table->timestampTz('completed_at')->nullable();
            $table->timestampsTz();
            $table->foreign(['tenant_id', 'agency_id'])->references(['tenant_id', 'id'])->on('agencies')->restrictOnDelete();
            $table->foreign(['tenant_id', 'created_by'])->references(['tenant_id', 'id'])->on('users')->restrictOnDelete();
            $table->index(['tenant_id', 'created_by', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('onboarding_imports');
        Schema::dropIfExists('customer_portal_uploads');
        Schema::dropIfExists('customer_portal_accesses');
    }
};
