<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vehicle_economic_profiles', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('agency_id');
            $table->unsignedBigInteger('vehicle_id');
            $table->unsignedInteger('revision');
            $table->date('effective_from');
            $table->date('acquired_on');
            $table->decimal('acquisition_cost', 14, 2);
            $table->decimal('residual_value', 14, 2);
            $table->unsignedSmallInteger('depreciation_months');
            $table->decimal('annual_insurance', 14, 2);
            $table->decimal('monthly_unrecorded_costs', 14, 2);
            $table->string('currency', 3);
            $table->unsignedBigInteger('acquisition_expense_id')->nullable();
            $table->string('reason', 500);
            $table->unsignedBigInteger('created_by');
            $table->timestampTz('created_at')->useCurrent();
            $table->unique(['tenant_id', 'vehicle_id', 'revision']);
            $table->foreign(['tenant_id', 'agency_id', 'vehicle_id'])->references(['tenant_id', 'agency_id', 'id'])->on('vehicles');
            $table->foreign(['tenant_id', 'acquisition_expense_id'])->references(['tenant_id', 'id'])->on('expenses');
            $table->foreign(['tenant_id', 'created_by'])->references(['tenant_id', 'id'])->on('users');
            $table->index(['tenant_id', 'vehicle_id', 'effective_from']);
        });
        DB::unprepared(<<<'SQL'
            ALTER TABLE vehicle_economic_profiles ADD CONSTRAINT vehicle_economic_values CHECK (revision > 0 AND acquisition_cost >= 0 AND residual_value >= 0 AND residual_value <= acquisition_cost AND annual_insurance >= 0 AND monthly_unrecorded_costs >= 0 AND depreciation_months BETWEEN 1 AND 240 AND acquired_on <= effective_from AND currency ~ '^[A-Z]{3}$');
            CREATE OR REPLACE FUNCTION rentfleet_economic_profile_guard() RETURNS trigger AS $$
            BEGIN
                IF NEW.acquisition_expense_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM expenses WHERE id = NEW.acquisition_expense_id AND tenant_id = NEW.tenant_id AND agency_id = NEW.agency_id AND vehicle_id = NEW.vehicle_id AND status = 'approved' AND deleted_at IS NULL AND currency = NEW.currency AND category = 'other') THEN
                    RAISE EXCEPTION 'Invalid acquisition expense scope' USING ERRCODE = '23514';
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;
            CREATE TRIGGER economic_profile_guard BEFORE INSERT ON vehicle_economic_profiles FOR EACH ROW EXECUTE FUNCTION rentfleet_economic_profile_guard();
            CREATE TRIGGER economic_profile_immutable BEFORE UPDATE OR DELETE ON vehicle_economic_profiles FOR EACH ROW EXECUTE FUNCTION rentfleet_training_immutable();
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('vehicle_economic_profiles');
        DB::statement('DROP FUNCTION IF EXISTS rentfleet_economic_profile_guard()');
    }
};
