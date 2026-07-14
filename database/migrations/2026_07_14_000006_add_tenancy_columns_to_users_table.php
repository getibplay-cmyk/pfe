<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('tenant_id')->nullable()->after('id')->constrained()->nullOnDelete();
            $table->foreignId('agency_id')->nullable()->after('tenant_id')->constrained()->nullOnDelete();
            $table->foreignId('role_id')->nullable()->after('agency_id')->constrained()->nullOnDelete();
            $table->boolean('is_platform_admin')->default(false)->after('password');
            $table->boolean('is_active')->default(true)->after('is_platform_admin');
            $table->timestampTz('last_login_at')->nullable()->after('is_active');

            $table->index(['tenant_id', 'is_active']);
            $table->index(['tenant_id', 'agency_id']);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['tenant_id']);
            $table->dropForeign(['agency_id']);
            $table->dropForeign(['role_id']);
            $table->dropIndex(['tenant_id', 'is_active']);
            $table->dropIndex(['tenant_id', 'agency_id']);
            $table->dropColumn(['tenant_id', 'agency_id', 'role_id', 'is_platform_admin', 'is_active', 'last_login_at']);
        });
    }
};
