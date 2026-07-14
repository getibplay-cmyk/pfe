<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('damage_status_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('damage_report_id');
            $table->string('from_status')->nullable();
            $table->string('to_status');
            $table->string('responsibility')->nullable();
            $table->text('reason')->nullable();
            $table->foreignId('changed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('created_at')->useCurrent();

            $table->index(['tenant_id', 'damage_report_id', 'created_at'], 'damage_status_histories_lookup_idx');
            $table->foreign(['tenant_id', 'damage_report_id'])->references(['tenant_id', 'id'])->on('damage_reports')->cascadeOnDelete();
        });

        DB::statement("ALTER TABLE damage_status_histories ADD CONSTRAINT damage_status_histories_status_check CHECK (to_status IN ('reported', 'under_review', 'resolved', 'dismissed'))");
        DB::statement("ALTER TABLE damage_status_histories ADD CONSTRAINT damage_status_histories_responsibility_check CHECK (responsibility IS NULL OR responsibility IN ('pending', 'customer', 'agency', 'insurance', 'unknown'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('damage_status_histories');
    }
};
