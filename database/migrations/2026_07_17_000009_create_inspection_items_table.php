<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inspection_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('vehicle_inspection_id');
            $table->string('item_code');
            $table->string('label');
            $table->string('condition');
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'vehicle_inspection_id', 'item_code'], 'inspection_items_code_unique');
            $table->foreign(['tenant_id', 'vehicle_inspection_id'])->references(['tenant_id', 'id'])->on('vehicle_inspections')->cascadeOnDelete();
        });

        DB::statement("ALTER TABLE inspection_items ADD CONSTRAINT inspection_items_condition_check CHECK (condition IN ('good', 'damaged', 'missing', 'not_checked'))");
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION rentfleet_prevent_completed_inspection_item_change() RETURNS trigger AS $$
            DECLARE target_inspection_id bigint;
            BEGIN
                target_inspection_id := COALESCE(NEW.vehicle_inspection_id, OLD.vehicle_inspection_id);
                IF EXISTS (SELECT 1 FROM vehicle_inspections WHERE id = target_inspection_id AND status = 'completed') THEN
                    RAISE EXCEPTION 'items of completed inspections are immutable' USING ERRCODE = '23514';
                END IF;
                IF TG_OP = 'DELETE' THEN RETURN OLD; END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;
            CREATE TRIGGER inspection_items_prevent_completed_change
            BEFORE INSERT OR UPDATE OR DELETE ON inspection_items
            FOR EACH ROW EXECUTE FUNCTION rentfleet_prevent_completed_inspection_item_change();
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('inspection_items');
        DB::statement('DROP FUNCTION IF EXISTS rentfleet_prevent_completed_inspection_item_change()');
    }
};
