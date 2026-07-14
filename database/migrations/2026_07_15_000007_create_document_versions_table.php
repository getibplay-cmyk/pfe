<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('document_id');
            $table->unsignedInteger('version_number');
            $table->string('original_name');
            $table->string('stored_path');
            $table->string('mime_type');
            $table->unsignedBigInteger('size_bytes');
            $table->string('sha256', 64);
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('created_at')->useCurrent();
            $table->unique(['document_id', 'version_number']);
            $table->unique(['tenant_id', 'id']);
            $table->index(['tenant_id', 'document_id']);
            $table->foreign(['tenant_id', 'document_id'])->references(['tenant_id', 'id'])->on('documents')->cascadeOnDelete();
        });
        Schema::table('documents', fn (Blueprint $table) => $table->foreign('current_version_id')->references('id')->on('document_versions')->nullOnDelete());
    }

    public function down(): void
    {
        Schema::table('documents', fn (Blueprint $table) => $table->dropForeign(['current_version_id']));
        Schema::dropIfExists('document_versions');
    }
};
