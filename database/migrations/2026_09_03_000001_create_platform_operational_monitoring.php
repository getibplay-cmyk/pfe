<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_operational_incidents', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 80)->unique();
            $table->string('severity', 20);
            $table->string('status', 20);
            $table->string('title', 160);
            $table->text('summary');
            $table->unsignedInteger('occurrence_count')->default(1);
            $table->timestampTz('first_detected_at');
            $table->timestampTz('last_detected_at');
            $table->timestampTz('resolved_at')->nullable();
            $table->timestampsTz();

            $table->index(['status', 'severity', 'last_detected_at'], 'platform_operational_incidents_status_idx');
        });

        Schema::create('platform_operational_incident_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('platform_operational_incident_id')
                ->constrained('platform_operational_incidents')
                ->restrictOnDelete();
            $table->string('event_type', 20);
            $table->string('severity', 20);
            $table->text('summary');
            $table->timestampTz('observed_at');
            $table->timestampTz('created_at')->useCurrent();

            $table->index(
                ['platform_operational_incident_id', 'observed_at'],
                'platform_operational_incident_events_incident_idx',
            );
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE platform_operational_incidents
                ADD CONSTRAINT platform_operational_incidents_code_check
                    CHECK (code ~ '^[a-z][a-z0-9_.-]{2,79}$'),
                ADD CONSTRAINT platform_operational_incidents_severity_check
                    CHECK (severity IN ('warning', 'critical')),
                ADD CONSTRAINT platform_operational_incidents_status_check
                    CHECK (status IN ('open', 'resolved')),
                ADD CONSTRAINT platform_operational_incidents_count_check
                    CHECK (occurrence_count > 0),
                ADD CONSTRAINT platform_operational_incidents_period_check
                    CHECK (last_detected_at >= first_detected_at),
                ADD CONSTRAINT platform_operational_incidents_resolution_check
                    CHECK ((status = 'resolved') = (resolved_at IS NOT NULL));

            ALTER TABLE platform_operational_incident_events
                ADD CONSTRAINT platform_operational_incident_events_type_check
                    CHECK (event_type IN ('opened', 'reopened', 'resolved')),
                ADD CONSTRAINT platform_operational_incident_events_severity_check
                    CHECK (severity IN ('warning', 'critical'));

            CREATE OR REPLACE FUNCTION belkhir_guard_platform_operational_incident() RETURNS trigger AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    RAISE EXCEPTION 'Operational incidents cannot be deleted' USING ERRCODE = '23514';
                END IF;

                IF NEW.code IS DISTINCT FROM OLD.code
                   OR NEW.first_detected_at IS DISTINCT FROM OLD.first_detected_at
                   OR NEW.created_at IS DISTINCT FROM OLD.created_at THEN
                    RAISE EXCEPTION 'Operational incident identity is immutable' USING ERRCODE = '23514';
                END IF;

                IF NEW.occurrence_count < OLD.occurrence_count THEN
                    RAISE EXCEPTION 'Operational incident occurrences cannot decrease' USING ERRCODE = '23514';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER platform_operational_incidents_guard
                BEFORE UPDATE OR DELETE ON platform_operational_incidents
                FOR EACH ROW EXECUTE FUNCTION belkhir_guard_platform_operational_incident();

            CREATE OR REPLACE FUNCTION belkhir_guard_platform_operational_incident_event() RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION 'Operational incident events are append-only' USING ERRCODE = '23514';
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER platform_operational_incident_events_guard
                BEFORE UPDATE OR DELETE ON platform_operational_incident_events
                FOR EACH ROW EXECUTE FUNCTION belkhir_guard_platform_operational_incident_event();
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS platform_operational_incident_events_guard ON platform_operational_incident_events;
            DROP TRIGGER IF EXISTS platform_operational_incidents_guard ON platform_operational_incidents;
            DROP FUNCTION IF EXISTS belkhir_guard_platform_operational_incident_event();
            DROP FUNCTION IF EXISTS belkhir_guard_platform_operational_incident();
        SQL);

        Schema::dropIfExists('platform_operational_incident_events');
        Schema::dropIfExists('platform_operational_incidents');
    }
};
