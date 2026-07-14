<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contract_acceptances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('rental_contract_id');
            $table->unsignedBigInteger('contract_version_id');
            $table->string('accepted_by_name');
            $table->string('acceptance_method');
            $table->string('consent_text_version');
            $table->timestampTz('accepted_at');
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->unsignedBigInteger('signature_document_id')->nullable();
            $table->char('content_hash', 64);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('created_at')->useCurrent();

            $table->unique(['tenant_id', 'contract_version_id']);
            $table->foreign(['tenant_id', 'rental_contract_id', 'contract_version_id'], 'contract_acceptances_version_fk')->references(['tenant_id', 'rental_contract_id', 'id'])->on('contract_versions');
            $table->foreign(['tenant_id', 'signature_document_id'])->references(['tenant_id', 'id'])->on('documents');
        });

        DB::statement("ALTER TABLE contract_acceptances ADD CONSTRAINT contract_acceptances_method_check CHECK (acceptance_method IN ('checkbox', 'typed_name', 'handwritten_capture'))");
        DB::statement("ALTER TABLE contract_acceptances ADD CONSTRAINT contract_acceptances_hash_check CHECK (content_hash ~ '^[0-9a-f]{64}$')");
    }

    public function down(): void
    {
        Schema::dropIfExists('contract_acceptances');
    }
};
