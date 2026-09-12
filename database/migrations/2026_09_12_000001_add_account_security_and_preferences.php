<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->text('mfa_secret')->nullable();
            $table->text('mfa_pending_secret')->nullable();
            $table->timestampTz('mfa_pending_at')->nullable();
            $table->timestampTz('mfa_confirmed_at')->nullable();
            $table->bigInteger('mfa_last_counter')->nullable();
            $table->text('mfa_recovery_hashes')->nullable();
            $table->unsignedInteger('security_version')->default(0);
            $table->string('locale', 2)->default('fr');
            $table->jsonb('workspace_preferences')->default('{}');
        });
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn([
            'mfa_secret', 'mfa_pending_secret', 'mfa_pending_at', 'mfa_confirmed_at',
            'mfa_last_counter', 'mfa_recovery_hashes', 'security_version', 'locale', 'workspace_preferences',
        ]));
    }
};
