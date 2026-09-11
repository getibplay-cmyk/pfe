<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('model_training_datasets', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('created_by');
            $table->string('name', 100);
            $table->string('family', 40);
            $table->string('source_note', 300);
            $table->string('stored_path');
            $table->char('sha256', 64);
            $table->unsignedInteger('row_count');
            $table->boolean('shared')->default(false);
            $table->unsignedBigInteger('shared_by')->nullable();
            $table->timestampTz('shared_at')->nullable();
            $table->timestampTz('revoked_at')->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->unique(['tenant_id', 'id']);
            $table->foreign(['tenant_id', 'created_by'])->references(['tenant_id', 'id'])->on('users')->restrictOnDelete();
            $table->foreign(['tenant_id', 'shared_by'])->references(['tenant_id', 'id'])->on('users')->restrictOnDelete();
            $table->index(['tenant_id', 'created_at']);
            $table->index(['family', 'shared', 'revoked_at']);
        });
        Schema::create('model_training_campaigns', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->string('name', 100);
            $table->string('family', 40);
            $table->string('baseline_version', 120);
            $table->string('stored_path');
            $table->char('sha256', 64);
            $table->jsonb('summary');
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestampTz('created_at')->useCurrent();
        });
        Schema::create('model_training_contributions', function (Blueprint $table): void {
            $table->foreignId('campaign_id')->constrained('model_training_campaigns')->restrictOnDelete();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('dataset_id');
            $table->primary(['campaign_id', 'dataset_id']);
            $table->foreign(['tenant_id', 'dataset_id'])->references(['tenant_id', 'id'])->on('model_training_datasets')->restrictOnDelete();
        });
        Schema::create('model_training_results', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('campaign_id')->unique()->constrained('model_training_campaigns')->restrictOnDelete();
            $table->string('candidate_version', 100);
            $table->char('artifact_sha256', 64);
            $table->char('report_sha256', 64);
            $table->string('stored_path');
            $table->jsonb('metrics');
            $table->boolean('eligible');
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestampTz('created_at')->useCurrent();
        });
        Schema::create('model_training_reviews', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('result_id')->unique()->constrained('model_training_results')->restrictOnDelete();
            $table->string('decision', 20);
            $table->string('note', 500);
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestampTz('created_at')->useCurrent();
        });
        DB::unprepared(<<<'SQL'
            ALTER TABLE model_training_datasets ADD CONSTRAINT training_dataset_rows CHECK (row_count BETWEEN 1 AND 20000);
            ALTER TABLE model_training_datasets ADD CONSTRAINT training_dataset_consent CHECK ((shared AND shared_at IS NOT NULL AND shared_by IS NOT NULL) OR (NOT shared AND shared_at IS NULL AND shared_by IS NULL));
            ALTER TABLE model_training_reviews ADD CONSTRAINT training_review_decision CHECK (decision IN ('qualified', 'rejected'));
            CREATE OR REPLACE FUNCTION rentfleet_training_immutable() RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION 'Training records are immutable; create a new attempt' USING ERRCODE = '23514';
            END;
            $$ LANGUAGE plpgsql;
            CREATE OR REPLACE FUNCTION rentfleet_training_dataset_guard() RETURNS trigger AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    RAISE EXCEPTION 'Training dataset records cannot be deleted' USING ERRCODE = '23514';
                END IF;
                IF OLD.revoked_at IS NOT NULL THEN
                    RAISE EXCEPTION 'Revocation is final' USING ERRCODE = '23514';
                END IF;
                IF NEW.revoked_at IS NOT NULL THEN
                    IF (to_jsonb(NEW) - 'revoked_at') IS DISTINCT FROM (to_jsonb(OLD) - 'revoked_at') THEN
                        RAISE EXCEPTION 'Only revocation may change' USING ERRCODE = '23514';
                    END IF;
                ELSIF OLD.shared OR NOT NEW.shared OR NEW.shared_at IS NULL OR NEW.shared_by IS NULL
                    OR (to_jsonb(NEW) - ARRAY['shared','shared_at','shared_by']) IS DISTINCT FROM (to_jsonb(OLD) - ARRAY['shared','shared_at','shared_by']) THEN
                    RAISE EXCEPTION 'Only first explicit sharing is allowed' USING ERRCODE = '23514';
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;
            CREATE TRIGGER training_dataset_guard BEFORE UPDATE OR DELETE ON model_training_datasets
                FOR EACH ROW EXECUTE FUNCTION rentfleet_training_dataset_guard();
        SQL);
        foreach (['campaigns', 'contributions', 'results', 'reviews'] as $suffix) {
            DB::unprepared("CREATE TRIGGER training_{$suffix}_immutable BEFORE UPDATE OR DELETE ON model_training_{$suffix} FOR EACH ROW EXECUTE FUNCTION rentfleet_training_immutable()");
        }
    }

    public function down(): void
    {
        foreach (['reviews', 'results', 'contributions', 'campaigns', 'datasets'] as $suffix) {
            Schema::dropIfExists('model_training_'.$suffix);
        }
        DB::unprepared('DROP FUNCTION IF EXISTS rentfleet_training_immutable(); DROP FUNCTION IF EXISTS rentfleet_training_dataset_guard();');
    }
};
