<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('internal_notifications', function (Blueprint $table) {
            $table->timestampTz('due_at')->nullable()->after('occurred_at');
            $table->timestampTz('last_detected_at')->useCurrent()->after('due_at');
            $table->timestampTz('resolved_at')->nullable()->after('last_detected_at');
            $table->string('resolution_reason', 64)->nullable()->after('resolved_at');
            $table->unsignedInteger('occurrence_count')->default(1)->after('resolution_reason');
        });

        DB::table('internal_notifications')->update([
            'last_detected_at' => DB::raw('occurred_at'),
        ]);

        DB::statement('ALTER TABLE internal_notifications ALTER COLUMN last_detected_at SET NOT NULL');
        DB::statement('ALTER TABLE internal_notifications ADD CONSTRAINT internal_notifications_occurrence_count_check CHECK (occurrence_count >= 1)');
        DB::statement("ALTER TABLE internal_notifications ADD CONSTRAINT internal_notifications_resolution_check CHECK ((resolved_at IS NULL AND resolution_reason IS NULL) OR (resolved_at IS NOT NULL AND resolution_reason = 'cause_disparue'))");
        DB::statement('CREATE INDEX internal_notifications_active_inbox_idx ON internal_notifications (tenant_id, agency_id, priority, due_at) WHERE resolved_at IS NULL');
        DB::statement('CREATE INDEX internal_notifications_history_idx ON internal_notifications (tenant_id, resolved_at DESC, id DESC) WHERE resolved_at IS NOT NULL');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS internal_notifications_history_idx');
        DB::statement('DROP INDEX IF EXISTS internal_notifications_active_inbox_idx');
        DB::statement('ALTER TABLE internal_notifications DROP CONSTRAINT IF EXISTS internal_notifications_resolution_check');
        DB::statement('ALTER TABLE internal_notifications DROP CONSTRAINT IF EXISTS internal_notifications_occurrence_count_check');

        Schema::table('internal_notifications', function (Blueprint $table) {
            $table->dropColumn(['due_at', 'last_detected_at', 'resolved_at', 'resolution_reason', 'occurrence_count']);
        });
    }
};
