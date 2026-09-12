<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reservations', function (Blueprint $table) {
            $table->unique(['tenant_id', 'agency_id', 'id'], 'reservations_booking_agency_unique');
        });
        Schema::create('public_booking_profiles', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->unique();
            $table->unsignedBigInteger('agency_id');
            $table->string('slug', 60)->unique();
            $table->boolean('enabled')->default(false);
            $table->string('public_name', 120);
            $table->text('description')->nullable();
            $table->string('public_phone', 30)->nullable();
            $table->string('public_email')->nullable();
            $table->timestampsTz();
            $table->unique(['tenant_id', 'id']);
            $table->foreign(['tenant_id', 'agency_id'])->references(['tenant_id', 'id'])->on('agencies');
        });
        Schema::create('public_vehicle_listings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('profile_id');
            $table->unsignedBigInteger('agency_id');
            $table->unsignedBigInteger('vehicle_id');
            $table->boolean('published')->default(false);
            $table->timestampsTz();
            $table->unique(['tenant_id', 'id']);
            $table->unique(['tenant_id', 'vehicle_id']);
            $table->foreign(['tenant_id', 'profile_id'])->references(['tenant_id', 'id'])->on('public_booking_profiles');
            $table->foreign(['tenant_id', 'agency_id', 'vehicle_id'])->references(['tenant_id', 'agency_id', 'id'])->on('vehicles');
            $table->index(['tenant_id', 'profile_id', 'published']);
        });
        Schema::create('public_booking_requests', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('agency_id');
            $table->unsignedBigInteger('profile_id');
            $table->uuid('listing_id');
            $table->unsignedBigInteger('vehicle_id');
            $table->timestampTz('starts_at');
            $table->timestampTz('ends_at');
            $table->text('contact');
            $table->jsonb('quote_snapshot');
            $table->char('session_hash', 64);
            $table->string('status', 20)->default('pending');
            $table->unsignedBigInteger('reservation_id')->nullable();
            $table->unsignedBigInteger('reviewed_by')->nullable();
            $table->text('review_note')->nullable();
            $table->timestampTz('reviewed_at')->nullable();
            $table->timestampsTz();
            $table->unique(['tenant_id', 'id']);
            $table->foreign(['tenant_id', 'profile_id'])->references(['tenant_id', 'id'])->on('public_booking_profiles');
            $table->foreign(['tenant_id', 'listing_id'])->references(['tenant_id', 'id'])->on('public_vehicle_listings');
            $table->foreign(['tenant_id', 'agency_id', 'vehicle_id'], 'booking_request_vehicle_fk')->references(['tenant_id', 'agency_id', 'id'])->on('vehicles');
            $table->foreign(['tenant_id', 'agency_id', 'reservation_id'], 'booking_request_reservation_fk')->references(['tenant_id', 'agency_id', 'id'])->on('reservations');
            $table->foreign(['tenant_id', 'reviewed_by'])->references(['tenant_id', 'id'])->on('users');
            $table->index(['tenant_id', 'agency_id', 'status', 'created_at']);
        });
        DB::unprepared(<<<'SQL'
            ALTER TABLE public_booking_profiles ADD CONSTRAINT booking_slug_check CHECK (slug ~ '^[a-z0-9]+(-[a-z0-9]+)*$');
            ALTER TABLE public_booking_requests ADD CONSTRAINT booking_period_check CHECK (ends_at > starts_at);
            ALTER TABLE public_booking_requests ADD CONSTRAINT booking_status_check CHECK (status IN ('pending', 'converted', 'rejected'));
            ALTER TABLE public_booking_requests ADD CONSTRAINT booking_conversion_check CHECK ((status = 'converted') = (reservation_id IS NOT NULL));
            CREATE OR REPLACE FUNCTION rentfleet_booking_request_guard() RETURNS trigger AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN RAISE EXCEPTION 'Booking history cannot be deleted' USING ERRCODE = '23514'; END IF;
                IF TG_OP = 'INSERT' THEN
                    IF NEW.status <> 'pending' OR NOT EXISTS (SELECT 1 FROM public_vehicle_listings l JOIN public_booking_profiles p ON p.id = l.profile_id AND p.tenant_id = l.tenant_id WHERE l.tenant_id = NEW.tenant_id AND l.id = NEW.listing_id AND l.profile_id = NEW.profile_id AND l.agency_id = NEW.agency_id AND l.vehicle_id = NEW.vehicle_id AND l.published AND p.enabled AND p.agency_id = NEW.agency_id) THEN
                        RAISE EXCEPTION 'Invalid public booking scope' USING ERRCODE = '23514';
                    END IF;
                ELSE
                    IF OLD.status <> 'pending' OR NEW.status NOT IN ('converted', 'rejected') OR (to_jsonb(NEW) - ARRAY['status', 'reservation_id', 'reviewed_by', 'review_note', 'reviewed_at', 'updated_at']) IS DISTINCT FROM (to_jsonb(OLD) - ARRAY['status', 'reservation_id', 'reviewed_by', 'review_note', 'reviewed_at', 'updated_at']) THEN
                        RAISE EXCEPTION 'Invalid booking review transition' USING ERRCODE = '23514';
                    END IF;
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;
            CREATE TRIGGER booking_request_guard BEFORE INSERT OR UPDATE OR DELETE ON public_booking_requests FOR EACH ROW EXECUTE FUNCTION rentfleet_booking_request_guard();
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('public_booking_requests');
        Schema::dropIfExists('public_vehicle_listings');
        Schema::dropIfExists('public_booking_profiles');
        DB::statement('DROP FUNCTION IF EXISTS rentfleet_booking_request_guard()');
        Schema::table('reservations', function (Blueprint $table) {
            $table->dropUnique('reservations_booking_agency_unique');
        });
    }
};
