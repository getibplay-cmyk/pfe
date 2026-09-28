<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->text('pending_email')->nullable();
            $table->string('pending_email_token_hash', 64)->nullable();
            $table->timestampTz('pending_email_expires_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn(['pending_email', 'pending_email_token_hash', 'pending_email_expires_at']));
    }
};
