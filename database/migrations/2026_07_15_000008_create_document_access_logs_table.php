<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_access_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('document_id');
            $table->unsignedBigInteger('document_version_id')->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('action');
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->index(['tenant_id', 'document_id', 'created_at']);
            $table->foreign(['tenant_id', 'document_id'])->references(['tenant_id', 'id'])->on('documents')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'document_version_id'])->references(['tenant_id', 'id'])->on('document_versions')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_access_logs');
    }
};
