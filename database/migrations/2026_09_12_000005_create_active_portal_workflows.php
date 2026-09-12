<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contract_extensions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('agency_id');
            $table->unsignedBigInteger('customer_id');
            $table->unsignedBigInteger('rental_contract_id');
            $table->uuid('requested_access_id');
            $table->timestampTz('requested_return_at');
            $table->text('customer_note')->nullable();
            $table->string('status', 20)->default('requested');
            $table->unsignedBigInteger('base_version_id')->nullable();
            $table->timestampTz('original_return_at')->nullable();
            $table->decimal('additional_amount', 14, 2)->nullable();
            $table->string('currency', 3)->nullable();
            $table->text('offer_snapshot')->nullable();
            $table->char('offer_hash', 64)->nullable();
            $table->unsignedBigInteger('document_id')->nullable();
            $table->unsignedBigInteger('document_version_id')->nullable();
            $table->char('document_hash', 64)->nullable();
            $table->unsignedBigInteger('reviewed_by')->nullable();
            $table->text('agency_note')->nullable();
            $table->text('resolution_note')->nullable();
            $table->timestampTz('offered_at')->nullable();
            $table->timestampTz('expires_at')->nullable();
            $table->timestampTz('resolved_at')->nullable();
            $table->unsignedBigInteger('accepted_version_id')->nullable();
            $table->timestampsTz();
            $table->unique(['tenant_id', 'id']);
            $table->index(['tenant_id', 'agency_id', 'status', 'created_at']);
            $table->foreign(['tenant_id', 'agency_id', 'rental_contract_id'], 'extension_contract_agency_fk')->references(['tenant_id', 'agency_id', 'id'])->on('rental_contracts');
            $table->foreign(['tenant_id', 'customer_id', 'rental_contract_id'], 'extension_contract_customer_fk')->references(['tenant_id', 'customer_id', 'id'])->on('rental_contracts');
            $table->foreign(['tenant_id', 'requested_access_id'])->references(['tenant_id', 'id'])->on('customer_portal_accesses');
            foreach (['base_version_id', 'accepted_version_id'] as $column) {
                $table->foreign(['tenant_id', 'rental_contract_id', $column], 'extension_'.$column.'_fk')->references(['tenant_id', 'rental_contract_id', 'id'])->on('contract_versions');
            }
            $table->foreign(['tenant_id', 'agency_id', 'document_id'], 'extension_document_scope_fk')->references(['tenant_id', 'agency_id', 'id'])->on('documents');
            $table->foreign(['tenant_id', 'document_id', 'document_version_id'], 'extension_document_version_fk')->references(['tenant_id', 'document_id', 'id'])->on('document_versions');
            $table->foreign(['tenant_id', 'reviewed_by'])->references(['tenant_id', 'id'])->on('users');
        });
        Schema::create('portal_contract_acceptances', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('customer_id');
            $table->unsignedBigInteger('rental_contract_id');
            $table->unsignedBigInteger('contract_version_id');
            $table->uuid('access_id');
            $table->timestampTz('created_at')->useCurrent();
            $table->unique(['tenant_id', 'contract_version_id']);
            $table->foreign(['tenant_id', 'customer_id', 'rental_contract_id'], 'portal_acceptance_customer_fk')->references(['tenant_id', 'customer_id', 'id'])->on('rental_contracts');
            $table->foreign(['tenant_id', 'contract_version_id'], 'portal_acceptance_record_fk')->references(['tenant_id', 'contract_version_id'])->on('contract_acceptances');
            $table->foreign(['tenant_id', 'access_id'])->references(['tenant_id', 'id'])->on('customer_portal_accesses');
        });
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION rentfleet_contract_document_file_guard() RETURNS trigger AS $$
            BEGIN
                IF OLD.document_type = 'contract_acceptance' AND OLD.current_version_id IS NOT NULL
                    AND (NEW.current_version_id IS DISTINCT FROM OLD.current_version_id OR NEW.document_type IS DISTINCT FROM OLD.document_type) THEN
                    RAISE EXCEPTION 'A contract document file cannot be replaced; create a contract version' USING ERRCODE = '23514';
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;
            CREATE TRIGGER contract_document_file_guard BEFORE UPDATE ON documents FOR EACH ROW EXECUTE FUNCTION rentfleet_contract_document_file_guard();
            CREATE UNIQUE INDEX extension_one_open_per_contract ON contract_extensions (tenant_id, rental_contract_id) WHERE status IN ('requested', 'offered');
            ALTER TABLE contract_extensions ADD CONSTRAINT extension_status_check CHECK (status IN ('requested', 'offered', 'accepted', 'rejected', 'cancelled'));
            ALTER TABLE contract_extensions ADD CONSTRAINT extension_amount_check CHECK (additional_amount IS NULL OR additional_amount >= 0);
            ALTER TABLE contract_extensions ADD CONSTRAINT extension_offer_check CHECK (status NOT IN ('offered', 'accepted') OR (base_version_id IS NOT NULL AND original_return_at IS NOT NULL AND requested_return_at > original_return_at AND additional_amount IS NOT NULL AND currency IS NOT NULL AND currency ~ '^[A-Z]{3}$' AND offer_snapshot IS NOT NULL AND offer_hash IS NOT NULL AND offer_hash ~ '^[0-9a-f]{64}$' AND document_id IS NOT NULL AND document_hash IS NOT NULL AND document_hash ~ '^[0-9a-f]{64}$' AND document_version_id IS NOT NULL AND reviewed_by IS NOT NULL AND expires_at IS NOT NULL));
            ALTER TABLE contract_extensions ADD CONSTRAINT extension_accepted_check CHECK ((status = 'accepted') = (accepted_version_id IS NOT NULL));
            CREATE OR REPLACE FUNCTION rentfleet_extension_guard() RETURNS trigger AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN RAISE EXCEPTION 'Extension history cannot be deleted' USING ERRCODE = '23514'; END IF;
                IF TG_OP = 'INSERT' THEN
                    IF NEW.status <> 'requested' OR NOT EXISTS (SELECT 1 FROM customer_portal_accesses WHERE id = NEW.requested_access_id AND tenant_id = NEW.tenant_id AND agency_id = NEW.agency_id AND customer_id = NEW.customer_id) THEN
                        RAISE EXCEPTION 'Invalid extension request scope' USING ERRCODE = '23514';
                    END IF;
                ELSE
                    IF ROW(NEW.tenant_id, NEW.agency_id, NEW.customer_id, NEW.rental_contract_id, NEW.requested_access_id, NEW.requested_return_at, NEW.customer_note, NEW.created_at) IS DISTINCT FROM ROW(OLD.tenant_id, OLD.agency_id, OLD.customer_id, OLD.rental_contract_id, OLD.requested_access_id, OLD.requested_return_at, OLD.customer_note, OLD.created_at)
                        OR NOT ((OLD.status = 'requested' AND NEW.status IN ('offered', 'rejected', 'cancelled')) OR (OLD.status = 'offered' AND NEW.status IN ('accepted', 'rejected', 'cancelled'))) THEN
                        RAISE EXCEPTION 'Invalid extension transition' USING ERRCODE = '23514';
                    END IF;
                    IF OLD.status = 'offered' AND (to_jsonb(NEW) - ARRAY['status', 'resolved_at', 'resolution_note', 'accepted_version_id', 'updated_at']) IS DISTINCT FROM (to_jsonb(OLD) - ARRAY['status', 'resolved_at', 'resolution_note', 'accepted_version_id', 'updated_at']) THEN
                        RAISE EXCEPTION 'An offered extension is immutable' USING ERRCODE = '23514';
                    END IF;
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;
            CREATE TRIGGER extension_guard BEFORE INSERT OR UPDATE OR DELETE ON contract_extensions FOR EACH ROW EXECUTE FUNCTION rentfleet_extension_guard();
            CREATE OR REPLACE FUNCTION rentfleet_portal_acceptance_guard() RETURNS trigger AS $$
            BEGIN
                IF NOT EXISTS (SELECT 1 FROM customer_portal_accesses WHERE id = NEW.access_id AND tenant_id = NEW.tenant_id AND customer_id = NEW.customer_id)
                    OR NOT EXISTS (SELECT 1 FROM contract_acceptances WHERE tenant_id = NEW.tenant_id AND rental_contract_id = NEW.rental_contract_id AND contract_version_id = NEW.contract_version_id AND created_by IS NULL) THEN
                    RAISE EXCEPTION 'Invalid portal acceptance scope' USING ERRCODE = '23514';
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;
            CREATE TRIGGER portal_acceptance_guard BEFORE INSERT ON portal_contract_acceptances FOR EACH ROW EXECUTE FUNCTION rentfleet_portal_acceptance_guard();
            CREATE TRIGGER portal_acceptance_immutable BEFORE UPDATE OR DELETE ON portal_contract_acceptances FOR EACH ROW EXECUTE FUNCTION rentfleet_training_immutable();
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS contract_document_file_guard ON documents');
        DB::statement('DROP FUNCTION IF EXISTS rentfleet_contract_document_file_guard()');
        Schema::dropIfExists('portal_contract_acceptances');
        Schema::dropIfExists('contract_extensions');
        DB::statement('DROP FUNCTION IF EXISTS rentfleet_extension_guard()');
        DB::statement('DROP FUNCTION IF EXISTS rentfleet_portal_acceptance_guard()');
    }
};
