<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reservation_replans', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('agency_id');
            $table->unsignedBigInteger('previous_reservation_id')->unique();
            $table->unsignedBigInteger('replacement_reservation_id')->unique();
            $table->uuid('request_id')->unique();
            $table->string('reason', 500);
            $table->unsignedBigInteger('confirmed_by');
            $table->timestampTz('created_at')->useCurrent();
            $table->foreign(['tenant_id', 'agency_id'])->references(['tenant_id', 'id'])->on('agencies');
            $table->foreign(['tenant_id', 'previous_reservation_id'], 'reservation_replan_previous_fk')->references(['tenant_id', 'id'])->on('reservations');
            $table->foreign(['tenant_id', 'replacement_reservation_id'], 'reservation_replan_replacement_fk')->references(['tenant_id', 'id'])->on('reservations');
            $table->foreign(['tenant_id', 'confirmed_by'])->references(['tenant_id', 'id'])->on('users');
        });
        DB::unprepared(<<<'SQL'
            ALTER TABLE reservation_replans ADD CONSTRAINT reservation_replan_distinct CHECK (previous_reservation_id <> replacement_reservation_id);
            CREATE FUNCTION rentfleet_reservation_replan_guard() RETURNS trigger AS $$
            BEGIN
                IF NOT EXISTS (SELECT 1 FROM reservations WHERE tenant_id = NEW.tenant_id AND agency_id = NEW.agency_id AND id = NEW.previous_reservation_id AND status = 'cancelled')
                    OR NOT EXISTS (SELECT 1 FROM reservations WHERE tenant_id = NEW.tenant_id AND agency_id = NEW.agency_id AND id = NEW.replacement_reservation_id AND status = 'confirmed') THEN
                    RAISE EXCEPTION 'Reservation replacement scope or state is invalid' USING ERRCODE = '23514';
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;
            CREATE TRIGGER reservation_replan_guard BEFORE INSERT ON reservation_replans FOR EACH ROW EXECUTE FUNCTION rentfleet_reservation_replan_guard();
            CREATE TRIGGER reservation_replan_immutable BEFORE UPDATE OR DELETE ON reservation_replans FOR EACH ROW EXECUTE FUNCTION rentfleet_training_immutable();
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('reservation_replans');
        DB::statement('DROP FUNCTION IF EXISTS rentfleet_reservation_replan_guard()');
    }
};
