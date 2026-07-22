<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->unique(['tenant_id', 'id'], 'users_tenant_id_id_unique');
        });

        Schema::create('internal_notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('agency_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('category', 32);
            $table->string('priority', 16);
            $table->string('title', 160);
            $table->string('summary', 500);
            $table->string('resource_type', 48);
            $table->unsignedBigInteger('resource_id');
            $table->string('required_permission', 100);
            $table->string('deduplication_key', 190);
            $table->timestampTz('occurred_at');
            $table->timestampsTz();

            $table->unique(['tenant_id', 'deduplication_key'], 'internal_notifications_tenant_dedup_unique');
            $table->unique(['tenant_id', 'id'], 'internal_notifications_tenant_id_unique');
            $table->index(['tenant_id', 'agency_id', 'occurred_at'], 'internal_notifications_scope_date_idx');
            $table->index(['tenant_id', 'category', 'priority'], 'internal_notifications_filters_idx');
        });

        Schema::create('internal_notification_recipients', function (Blueprint $table) {
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('internal_notification_id');
            $table->unsignedBigInteger('user_id');
            $table->timestampTz('read_at')->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->primary(['internal_notification_id', 'user_id'], 'internal_notification_recipients_primary');
            $table->foreign(['tenant_id', 'internal_notification_id'], 'notification_recipients_notification_fk')
                ->references(['tenant_id', 'id'])->on('internal_notifications')->cascadeOnDelete();
            $table->foreign(['tenant_id', 'user_id'], 'notification_recipients_user_fk')
                ->references(['tenant_id', 'id'])->on('users')->cascadeOnDelete();
            $table->index(['user_id', 'read_at', 'created_at'], 'internal_notification_recipients_inbox_idx');
        });

        DB::statement("ALTER TABLE internal_notifications ADD CONSTRAINT internal_notifications_priority_check CHECK (priority IN ('information', 'warning', 'urgent'))");
        DB::statement("ALTER TABLE internal_notifications ADD CONSTRAINT internal_notifications_category_check CHECK (category IN ('reservation', 'contract', 'fleet', 'insurance', 'maintenance', 'finance'))");
        DB::statement("ALTER TABLE internal_notifications ADD CONSTRAINT internal_notifications_resource_type_check CHECK (resource_type IN ('reservation', 'rental_contract', 'insurance_policy', 'maintenance_order', 'invoice'))");

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION enforce_internal_notification_scope() RETURNS trigger
            LANGUAGE plpgsql AS $$
            BEGIN
                IF NEW.agency_id IS NOT NULL AND NOT EXISTS (
                    SELECT 1 FROM agencies
                    WHERE id = NEW.agency_id AND tenant_id = NEW.tenant_id AND deleted_at IS NULL
                ) THEN
                    RAISE EXCEPTION 'internal notification agency scope mismatch' USING ERRCODE = '23514';
                END IF;

                RETURN NEW;
            END;
            $$;

            CREATE TRIGGER internal_notifications_scope_guard
            BEFORE INSERT OR UPDATE OF tenant_id, agency_id ON internal_notifications
            FOR EACH ROW EXECUTE FUNCTION enforce_internal_notification_scope();

            CREATE OR REPLACE FUNCTION enforce_internal_notification_recipient_scope() RETURNS trigger
            LANGUAGE plpgsql AS $$
            DECLARE
                notification_agency_id bigint;
                recipient_agency_id bigint;
            BEGIN
                SELECT agency_id INTO notification_agency_id
                FROM internal_notifications
                WHERE id = NEW.internal_notification_id AND tenant_id = NEW.tenant_id;

                SELECT agency_id INTO recipient_agency_id
                FROM users
                WHERE id = NEW.user_id AND tenant_id = NEW.tenant_id AND is_active = true;

                IF NOT FOUND THEN
                    RAISE EXCEPTION 'internal notification recipient scope mismatch' USING ERRCODE = '23514';
                END IF;

                IF notification_agency_id IS NOT NULL
                    AND recipient_agency_id IS NOT NULL
                    AND notification_agency_id <> recipient_agency_id THEN
                    RAISE EXCEPTION 'internal notification recipient agency mismatch' USING ERRCODE = '23514';
                END IF;

                RETURN NEW;
            END;
            $$;

            CREATE TRIGGER internal_notification_recipients_scope_guard
            BEFORE INSERT OR UPDATE OF tenant_id, internal_notification_id, user_id ON internal_notification_recipients
            FOR EACH ROW EXECUTE FUNCTION enforce_internal_notification_recipient_scope();
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS internal_notification_recipients_scope_guard ON internal_notification_recipients;
            DROP FUNCTION IF EXISTS enforce_internal_notification_recipient_scope();
            DROP TRIGGER IF EXISTS internal_notifications_scope_guard ON internal_notifications;
            DROP FUNCTION IF EXISTS enforce_internal_notification_scope();
        SQL);

        Schema::dropIfExists('internal_notification_recipients');
        Schema::dropIfExists('internal_notifications');

        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique('users_tenant_id_id_unique');
        });
    }
};
