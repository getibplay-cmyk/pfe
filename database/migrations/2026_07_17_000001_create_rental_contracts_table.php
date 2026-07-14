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
            $table->unique(['tenant_id', 'agency_id', 'vehicle_id', 'id'], 'reservations_tenant_agency_vehicle_id_unique');
        });

        Schema::create('business_number_counters', function (Blueprint $table) {
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('document_type', 30);
            $table->unsignedSmallInteger('year');
            $table->unsignedBigInteger('last_number')->default(0);
            $table->primary(['tenant_id', 'document_type', 'year']);
        });

        Schema::create('rental_contracts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('agency_id');
            $table->unsignedBigInteger('reservation_id');
            $table->unsignedBigInteger('customer_id');
            $table->unsignedBigInteger('vehicle_id');
            $table->string('contract_number');
            $table->string('status')->default('draft');
            $table->unsignedBigInteger('current_version_id')->nullable();
            $table->timestampTz('expected_start_at');
            $table->timestampTz('expected_return_at');
            $table->timestampTz('actual_start_at')->nullable();
            $table->timestampTz('actual_return_at')->nullable();
            $table->unsignedBigInteger('start_mileage')->nullable();
            $table->unsignedBigInteger('return_mileage')->nullable();
            $table->decimal('start_fuel_level', 5, 2)->nullable();
            $table->decimal('return_fuel_level', 5, 2)->nullable();
            $table->decimal('rental_subtotal', 14, 2);
            $table->decimal('additional_charges_total', 14, 2)->default(0);
            $table->decimal('total_amount', 14, 2);
            $table->decimal('deposit_required', 14, 2)->default(0);
            $table->char('currency', 3)->default('MAD');
            $table->timestampTz('accepted_at')->nullable();
            $table->timestampTz('activated_at')->nullable();
            $table->timestampTz('returned_at')->nullable();
            $table->timestampTz('closed_at')->nullable();
            $table->timestampTz('cancelled_at')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'contract_number']);
            $table->unique(['tenant_id', 'id']);
            $table->unique(['tenant_id', 'customer_id', 'id'], 'rental_contracts_tenant_customer_id_unique');
            $table->unique(['tenant_id', 'agency_id', 'vehicle_id', 'id'], 'rental_contracts_tenant_agency_vehicle_id_unique');
            $table->index(['tenant_id', 'agency_id', 'status']);
            $table->index(['tenant_id', 'vehicle_id', 'status']);
            $table->foreign(['tenant_id', 'agency_id'])->references(['tenant_id', 'id'])->on('agencies');
            $table->foreign(['tenant_id', 'customer_id'])->references(['tenant_id', 'id'])->on('customers');
            $table->foreign(['tenant_id', 'agency_id', 'vehicle_id', 'reservation_id'], 'rental_contracts_reservation_scope_fk')->references(['tenant_id', 'agency_id', 'vehicle_id', 'id'])->on('reservations');
        });

        DB::statement("CREATE UNIQUE INDEX rental_contracts_one_active_per_reservation_idx ON rental_contracts (tenant_id, reservation_id) WHERE status <> 'cancelled' AND deleted_at IS NULL");
        DB::statement('ALTER TABLE rental_contracts ADD CONSTRAINT rental_contracts_period_check CHECK (expected_return_at > expected_start_at)');
        DB::statement('ALTER TABLE rental_contracts ADD CONSTRAINT rental_contracts_mileage_check CHECK (return_mileage IS NULL OR start_mileage IS NULL OR return_mileage >= start_mileage)');
        DB::statement('ALTER TABLE rental_contracts ADD CONSTRAINT rental_contracts_fuel_check CHECK ((start_fuel_level IS NULL OR start_fuel_level BETWEEN 0 AND 100) AND (return_fuel_level IS NULL OR return_fuel_level BETWEEN 0 AND 100))');
        DB::statement('ALTER TABLE rental_contracts ADD CONSTRAINT rental_contracts_amounts_check CHECK (rental_subtotal >= 0 AND additional_charges_total >= 0 AND total_amount >= 0 AND deposit_required >= 0)');
        DB::statement("ALTER TABLE rental_contracts ADD CONSTRAINT rental_contracts_status_check CHECK (status IN ('draft', 'ready', 'accepted', 'active', 'return_pending', 'returned', 'closed', 'cancelled'))");
        DB::statement("ALTER TABLE rental_contracts ADD CONSTRAINT rental_contracts_currency_check CHECK (currency ~ '^[A-Z]{3}$')");
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION rentfleet_prevent_protected_contract_delete() RETURNS trigger AS $$
            BEGIN
                IF OLD.status IN ('accepted', 'active', 'return_pending', 'returned', 'closed') THEN
                    RAISE EXCEPTION 'protected rental contracts cannot be physically deleted' USING ERRCODE = '23514';
                END IF;
                RETURN OLD;
            END;
            $$ LANGUAGE plpgsql;
            CREATE TRIGGER rental_contracts_prevent_protected_delete
            BEFORE DELETE ON rental_contracts
            FOR EACH ROW EXECUTE FUNCTION rentfleet_prevent_protected_contract_delete();
            CREATE OR REPLACE FUNCTION rentfleet_prevent_contract_closed_before_finance() RETURNS trigger AS $$
            BEGIN
                IF NEW.status = 'closed' THEN
                    RAISE EXCEPTION 'closed status is reserved for the financial lot' USING ERRCODE = '23514';
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;
            CREATE TRIGGER rental_contracts_prevent_closed_before_finance
            BEFORE INSERT OR UPDATE ON rental_contracts
            FOR EACH ROW EXECUTE FUNCTION rentfleet_prevent_contract_closed_before_finance();
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('rental_contracts');
        DB::statement('DROP FUNCTION IF EXISTS rentfleet_prevent_protected_contract_delete()');
        DB::statement('DROP FUNCTION IF EXISTS rentfleet_prevent_contract_closed_before_finance()');
        Schema::dropIfExists('business_number_counters');
        Schema::table('reservations', fn (Blueprint $table) => $table->dropUnique('reservations_tenant_agency_vehicle_id_unique'));
    }
};
