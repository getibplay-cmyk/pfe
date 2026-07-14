<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('drivers', function (Blueprint $table) {
            $table->unique(['tenant_id', 'id']);
            $table->unique(['tenant_id', 'customer_id', 'id']);
        });
        Schema::table('vehicles', function (Blueprint $table) {
            $table->unique(['tenant_id', 'agency_id', 'id']);
        });

        Schema::create('reservation_number_counters', function (Blueprint $table) {
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('year');
            $table->unsignedBigInteger('last_number')->default(0);
            $table->primary(['tenant_id', 'year']);
        });

        Schema::create('reservations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('agency_id');
            $table->unsignedBigInteger('customer_id');
            $table->unsignedBigInteger('driver_id')->nullable();
            $table->unsignedBigInteger('vehicle_category_id');
            $table->unsignedBigInteger('vehicle_id')->nullable();
            $table->string('reservation_number');
            $table->timestampTz('starts_at');
            $table->timestampTz('ends_at');
            $table->string('status')->default('draft');
            $table->unsignedBigInteger('pricing_rule_id')->nullable();
            $table->unsignedSmallInteger('billed_days')->nullable();
            $table->decimal('daily_rate', 14, 2)->nullable();
            $table->decimal('subtotal', 14, 2)->default(0);
            $table->decimal('options_total', 14, 2)->default(0);
            $table->decimal('total_amount', 14, 2)->default(0);
            $table->decimal('deposit_amount', 14, 2)->default(0);
            $table->char('currency', 3)->default('MAD');
            $table->jsonb('pricing_snapshot')->default('{}');
            $table->text('notes')->nullable();
            $table->timestampTz('expires_at')->nullable();
            $table->timestampTz('confirmed_at')->nullable();
            $table->timestampTz('cancelled_at')->nullable();
            $table->text('cancellation_reason')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'reservation_number']);
            $table->unique(['tenant_id', 'id']);
            $table->index(['tenant_id', 'agency_id', 'status']);
            $table->index(['tenant_id', 'starts_at', 'ends_at']);
            $table->index(['tenant_id', 'vehicle_id', 'starts_at']);
            $table->foreign(['tenant_id', 'agency_id'])->references(['tenant_id', 'id'])->on('agencies');
            $table->foreign(['tenant_id', 'customer_id'])->references(['tenant_id', 'id'])->on('customers');
            $table->foreign(['tenant_id', 'customer_id', 'driver_id'])->references(['tenant_id', 'customer_id', 'id'])->on('drivers');
            $table->foreign(['tenant_id', 'vehicle_category_id'])->references(['tenant_id', 'id'])->on('vehicle_categories');
            $table->foreign(['tenant_id', 'agency_id', 'vehicle_id'])->references(['tenant_id', 'agency_id', 'id'])->on('vehicles');
            $table->foreign(['tenant_id', 'pricing_rule_id'])->references(['tenant_id', 'id'])->on('pricing_rules');
        });

        DB::statement('ALTER TABLE reservations ADD CONSTRAINT reservations_period_check CHECK (ends_at > starts_at)');
        DB::statement('ALTER TABLE reservations ADD CONSTRAINT reservations_amounts_non_negative_check CHECK ((daily_rate IS NULL OR daily_rate >= 0) AND subtotal >= 0 AND options_total >= 0 AND total_amount >= 0 AND deposit_amount >= 0)');
        DB::statement("ALTER TABLE reservations ADD CONSTRAINT reservations_status_check CHECK (status IN ('draft', 'pending', 'confirmed', 'converted', 'cancelled', 'expired'))");
        DB::statement("ALTER TABLE reservations ADD CONSTRAINT reservations_currency_check CHECK (currency ~ '^[A-Z]{3}$')");
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION rentfleet_prevent_confirmed_reservation_delete() RETURNS trigger AS $$
            BEGIN
                IF OLD.status IN ('confirmed', 'converted') THEN
                    RAISE EXCEPTION 'confirmed reservations cannot be physically deleted' USING ERRCODE = '23514';
                END IF;
                RETURN OLD;
            END;
            $$ LANGUAGE plpgsql;
            CREATE TRIGGER reservations_prevent_confirmed_delete
            BEFORE DELETE ON reservations
            FOR EACH ROW EXECUTE FUNCTION rentfleet_prevent_confirmed_reservation_delete();
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('reservations');
        DB::statement('DROP FUNCTION IF EXISTS rentfleet_prevent_confirmed_reservation_delete()');
        Schema::dropIfExists('reservation_number_counters');
        Schema::table('vehicles', fn (Blueprint $table) => $table->dropUnique(['tenant_id', 'agency_id', 'id']));
        Schema::table('drivers', function (Blueprint $table) {
            $table->dropUnique(['tenant_id', 'customer_id', 'id']);
            $table->dropUnique(['tenant_id', 'id']);
        });
    }
};
