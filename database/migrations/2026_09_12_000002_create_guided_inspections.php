<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inspection_drafts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('agency_id');
            $table->unsignedBigInteger('vehicle_id');
            $table->unsignedBigInteger('rental_contract_id');
            $table->string('kind', 12);
            $table->jsonb('data')->default('{}');
            $table->unsignedInteger('revision')->default(0);
            $table->unsignedBigInteger('saved_by');
            $table->unsignedBigInteger('vehicle_inspection_id')->nullable();
            $table->jsonb('selected_photo_ids')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->timestampsTz();
            $table->unique(['tenant_id', 'id']);
            $table->unique(['tenant_id', 'rental_contract_id', 'kind'], 'inspection_draft_contract_kind_unique');
            $table->foreign(['tenant_id', 'agency_id', 'vehicle_id', 'rental_contract_id'], 'inspection_draft_contract_fk')->references(['tenant_id', 'agency_id', 'vehicle_id', 'id'])->on('rental_contracts');
            $table->foreign(['tenant_id', 'saved_by'])->references(['tenant_id', 'id'])->on('users');
            $table->foreign(['tenant_id', 'rental_contract_id', 'vehicle_inspection_id'], 'inspection_draft_result_fk')->references(['tenant_id', 'rental_contract_id', 'id'])->on('vehicle_inspections');
        });
        Schema::create('inspection_draft_photos', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('inspection_draft_id');
            $table->string('angle', 20);
            $table->unsignedBigInteger('document_id');
            $table->unsignedBigInteger('document_version_id');
            $table->char('sha256', 64);
            $table->unsignedBigInteger('created_by');
            $table->timestampTz('created_at')->useCurrent();
            $table->unique(['tenant_id', 'id']);
            $table->foreign(['tenant_id', 'inspection_draft_id'])->references(['tenant_id', 'id'])->on('inspection_drafts');
            $table->foreign(['tenant_id', 'document_id', 'document_version_id'], 'inspection_photo_document_fk')->references(['tenant_id', 'document_id', 'id'])->on('document_versions');
            $table->foreign(['tenant_id', 'created_by'])->references(['tenant_id', 'id'])->on('users');
            $table->index(['tenant_id', 'inspection_draft_id', 'angle']);
        });
        DB::unprepared(<<<'SQL'
            ALTER TABLE inspection_drafts ADD CONSTRAINT inspection_draft_kind CHECK (kind IN ('departure', 'return'));
            ALTER TABLE inspection_drafts ADD CONSTRAINT inspection_draft_completion CHECK ((completed_at IS NULL AND vehicle_inspection_id IS NULL AND selected_photo_ids IS NULL) OR (completed_at IS NOT NULL AND vehicle_inspection_id IS NOT NULL AND selected_photo_ids IS NOT NULL));
            ALTER TABLE inspection_draft_photos ADD CONSTRAINT inspection_photo_angle CHECK (angle IN ('front','back','left','right','interior','odometer'));
            CREATE FUNCTION rentfleet_inspection_draft_guard() RETURNS trigger AS $$
            BEGIN
                IF OLD.completed_at IS NOT NULL THEN
                    RAISE EXCEPTION 'Completed guided inspections are immutable' USING ERRCODE = '23514';
                END IF;
                IF TG_OP = 'DELETE' THEN RETURN OLD; END IF;
                IF (NEW.tenant_id, NEW.agency_id, NEW.vehicle_id, NEW.rental_contract_id, NEW.kind) IS DISTINCT FROM (OLD.tenant_id, OLD.agency_id, OLD.vehicle_id, OLD.rental_contract_id, OLD.kind) THEN
                    RAISE EXCEPTION 'Guided inspection scope is immutable' USING ERRCODE = '23514';
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;
            CREATE TRIGGER inspection_draft_guard BEFORE UPDATE OR DELETE ON inspection_drafts FOR EACH ROW EXECUTE FUNCTION rentfleet_inspection_draft_guard();
            CREATE FUNCTION rentfleet_inspection_photo_guard() RETURNS trigger AS $$
            DECLARE draft inspection_drafts%ROWTYPE;
            BEGIN
                IF TG_OP <> 'INSERT' THEN
                    RAISE EXCEPTION 'Inspection photo history is immutable' USING ERRCODE = '23514';
                END IF;
                SELECT * INTO draft FROM inspection_drafts WHERE tenant_id = NEW.tenant_id AND id = NEW.inspection_draft_id FOR UPDATE;
                IF draft.id IS NULL OR draft.completed_at IS NOT NULL THEN
                    RAISE EXCEPTION 'A mutable draft is required' USING ERRCODE = '23514';
                END IF;
                IF NOT EXISTS (SELECT 1 FROM documents d JOIN document_versions v ON v.tenant_id = d.tenant_id AND v.document_id = d.id WHERE d.tenant_id = NEW.tenant_id AND d.id = NEW.document_id AND v.id = NEW.document_version_id AND v.sha256 = NEW.sha256 AND d.agency_id = draft.agency_id AND d.documentable_type = 'rental_contract' AND d.documentable_id = draft.rental_contract_id AND d.document_type = 'inspection_photo' AND d.deleted_at IS NULL) THEN
                    RAISE EXCEPTION 'Photo does not belong to this inspection contract' USING ERRCODE = '23514';
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;
            CREATE TRIGGER inspection_photo_guard BEFORE INSERT OR UPDATE OR DELETE ON inspection_draft_photos FOR EACH ROW EXECUTE FUNCTION rentfleet_inspection_photo_guard();
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('inspection_draft_photos');
        Schema::dropIfExists('inspection_drafts');
        DB::unprepared('DROP FUNCTION IF EXISTS rentfleet_inspection_photo_guard(); DROP FUNCTION IF EXISTS rentfleet_inspection_draft_guard();');
    }
};
