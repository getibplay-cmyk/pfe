<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vision_annotations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('agency_id');
            $table->string('family', 12);
            foreach (['color', 'plate', 'damage'] as $family) {
                $table->unsignedBigInteger($family.'_run_id')->nullable();
                $table->foreign(['tenant_id', $family.'_run_id'])->references(['tenant_id', 'id'])->on('vehicle_'.$family.'_prediction_runs');
                $table->unique([$family.'_run_id', 'revision'], 'vision_'.$family.'_revision_unique');
            }
            $table->unsignedInteger('revision');
            $table->text('truth');
            $table->char('source_sha256', 64);
            $table->unsignedInteger('image_width');
            $table->unsignedInteger('image_height');
            $table->string('model_version', 200);
            $table->boolean('matches_prediction')->nullable();
            $table->boolean('was_abstained');
            $table->unsignedInteger('box_tp')->nullable();
            $table->unsignedInteger('box_fp')->nullable();
            $table->unsignedInteger('box_fn')->nullable();
            $table->unsignedBigInteger('validated_by');
            $table->timestampTz('created_at')->useCurrent();
            $table->unique(['tenant_id', 'id']);
            $table->foreign(['tenant_id', 'agency_id'])->references(['tenant_id', 'id'])->on('agencies');
            $table->foreign(['tenant_id', 'validated_by'])->references(['tenant_id', 'id'])->on('users');
            $table->index(['tenant_id', 'agency_id', 'family', 'created_at']);
        });
        Schema::create('training_annotation_exports', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('dataset_id')->unique();
            $table->string('stored_path');
            $table->char('sha256', 64);
            $table->timestampTz('created_at')->useCurrent();
            $table->foreign(['tenant_id', 'dataset_id'])->references(['tenant_id', 'id'])->on('model_training_datasets');
        });
        Schema::create('training_annotation_export_rows', function (Blueprint $table) {
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('dataset_id');
            $table->unsignedBigInteger('annotation_id');
            $table->primary(['dataset_id', 'annotation_id']);
            $table->foreign(['tenant_id', 'dataset_id'])->references(['tenant_id', 'id'])->on('model_training_datasets');
            $table->foreign(['tenant_id', 'annotation_id'])->references(['tenant_id', 'id'])->on('vision_annotations');
        });
        DB::unprepared(<<<'SQL'
            ALTER TABLE vision_annotations ADD CONSTRAINT vision_annotation_family CHECK (
                (family = 'color' AND color_run_id IS NOT NULL AND plate_run_id IS NULL AND damage_run_id IS NULL)
                OR (family = 'plate' AND color_run_id IS NULL AND plate_run_id IS NOT NULL AND damage_run_id IS NULL)
                OR (family = 'damage' AND color_run_id IS NULL AND plate_run_id IS NULL AND damage_run_id IS NOT NULL)
            );
            ALTER TABLE vision_annotations ADD CONSTRAINT vision_annotation_dimensions CHECK (image_width > 0 AND image_height > 0 AND revision > 0);
            CREATE OR REPLACE FUNCTION rentfleet_vision_annotation_scope() RETURNS trigger AS $$
            DECLARE run_agency bigint; run_status varchar;
            BEGIN
                IF NEW.family = 'color' THEN
                    SELECT agency_id, status INTO run_agency, run_status FROM vehicle_color_prediction_runs WHERE tenant_id = NEW.tenant_id AND id = NEW.color_run_id FOR UPDATE;
                ELSIF NEW.family = 'plate' THEN
                    SELECT agency_id, status INTO run_agency, run_status FROM vehicle_plate_prediction_runs WHERE tenant_id = NEW.tenant_id AND id = NEW.plate_run_id FOR UPDATE;
                ELSE
                    SELECT agency_id, status INTO run_agency, run_status FROM vehicle_damage_prediction_runs WHERE tenant_id = NEW.tenant_id AND id = NEW.damage_run_id FOR UPDATE;
                END IF;
                IF run_agency IS NULL OR run_agency <> NEW.agency_id OR run_status <> 'succeeded' THEN
                    RAISE EXCEPTION 'Annotation requires a completed run in the same scope' USING ERRCODE = '23514';
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;
            CREATE TRIGGER vision_annotation_scope BEFORE INSERT ON vision_annotations FOR EACH ROW EXECUTE FUNCTION rentfleet_vision_annotation_scope();
        SQL);
        foreach (['vision_annotations', 'training_annotation_exports', 'training_annotation_export_rows'] as $table) {
            DB::statement("CREATE TRIGGER {$table}_immutable BEFORE UPDATE OR DELETE ON {$table} FOR EACH ROW EXECUTE FUNCTION rentfleet_training_immutable()");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('training_annotation_export_rows');
        Schema::dropIfExists('training_annotation_exports');
        Schema::dropIfExists('vision_annotations');
        DB::statement('DROP FUNCTION IF EXISTS rentfleet_vision_annotation_scope()');
    }
};
