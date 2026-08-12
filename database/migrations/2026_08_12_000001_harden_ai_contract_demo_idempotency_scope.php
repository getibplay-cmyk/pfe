<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            LOCK TABLE ai_advisory_records_demo, ai_idempotency_keys_demo IN ACCESS EXCLUSIVE MODE;

            DROP TRIGGER IF EXISTS ai_idempotency_demo_append_only ON ai_idempotency_keys_demo;

            DO $$
            BEGIN
                IF EXISTS (
                    SELECT 1
                    FROM ai_idempotency_keys_demo
                    GROUP BY ai_advisory_record_demo_id
                    HAVING count(*) > 1
                ) THEN
                    RAISE EXCEPTION 'AI contract demo advisory has multiple idempotency keys; no automatic correction was applied'
                        USING ERRCODE = '23514';
                END IF;
            END;
            $$;

            ALTER TABLE ai_idempotency_keys_demo
                ADD COLUMN agency_id bigint NULL;

            UPDATE ai_idempotency_keys_demo AS idempotency
            SET agency_id = advisory.agency_id
            FROM ai_advisory_records_demo AS advisory
            WHERE advisory.id = idempotency.ai_advisory_record_demo_id
                AND advisory.tenant_id = idempotency.tenant_id;

            DO $$
            BEGIN
                IF EXISTS (
                    SELECT 1
                    FROM ai_idempotency_keys_demo AS idempotency
                    JOIN ai_advisory_records_demo AS advisory
                        ON advisory.id = idempotency.ai_advisory_record_demo_id
                        AND advisory.tenant_id = idempotency.tenant_id
                    WHERE advisory.agency_id IS DISTINCT FROM idempotency.agency_id
                        OR advisory.fingerprint IS DISTINCT FROM idempotency.fingerprint
                        OR advisory.payload#>>'{idempotency,key}'
                            IS DISTINCT FROM idempotency.idempotency_key::text
                ) THEN
                    RAISE EXCEPTION 'AI contract demo contains an inconsistent idempotency link; no automatic correction was applied'
                        USING ERRCODE = '23514';
                END IF;
            END;
            $$;

            ALTER TABLE ai_advisory_records_demo
                DROP CONSTRAINT ai_advisory_demo_external_unique,
                ADD CONSTRAINT ai_advisory_demo_external_unique
                    UNIQUE NULLS NOT DISTINCT (tenant_id, agency_id, module_id, external_record_id);

            ALTER TABLE ai_idempotency_keys_demo
                DROP CONSTRAINT ai_idempotency_demo_tenant_key_unique,
                ADD CONSTRAINT ai_idempotency_demo_tenant_key_unique
                    UNIQUE NULLS NOT DISTINCT (tenant_id, agency_id, idempotency_key),
                ADD CONSTRAINT ai_idempotency_demo_advisory_unique
                    UNIQUE (ai_advisory_record_demo_id),
                ADD CONSTRAINT ai_idempotency_demo_agency_fk
                    FOREIGN KEY (tenant_id, agency_id)
                    REFERENCES agencies (tenant_id, id)
                    ON DELETE CASCADE;

            CREATE OR REPLACE FUNCTION enforce_ai_idempotency_demo_scope() RETURNS trigger
            LANGUAGE plpgsql AS $$
            BEGIN
                PERFORM 1
                FROM ai_advisory_records_demo AS advisory
                WHERE advisory.id = NEW.ai_advisory_record_demo_id
                    AND advisory.tenant_id = NEW.tenant_id
                    AND advisory.agency_id IS NOT DISTINCT FROM NEW.agency_id
                    AND advisory.fingerprint = NEW.fingerprint
                    AND advisory.payload#>>'{idempotency,key}' = NEW.idempotency_key::text
                FOR KEY SHARE;

                IF NOT FOUND THEN
                    RAISE EXCEPTION 'AI contract demo idempotency scope mismatch' USING ERRCODE = '23514';
                END IF;

                RETURN NEW;
            END;
            $$;

            CREATE TRIGGER ai_idempotency_demo_scope_guard
            BEFORE INSERT ON ai_idempotency_keys_demo
            FOR EACH ROW EXECUTE FUNCTION enforce_ai_idempotency_demo_scope();

            CREATE TRIGGER ai_idempotency_demo_append_only
            BEFORE UPDATE OR DELETE ON ai_idempotency_keys_demo
            FOR EACH ROW EXECUTE FUNCTION prevent_ai_contract_demo_mutation();
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            LOCK TABLE ai_advisory_records_demo, ai_idempotency_keys_demo IN ACCESS EXCLUSIVE MODE;

            DO $$
            BEGIN
                IF EXISTS (
                    SELECT 1
                    FROM ai_advisory_records_demo
                    GROUP BY tenant_id, module_id, external_record_id
                    HAVING count(*) > 1
                ) OR EXISTS (
                    SELECT 1
                    FROM ai_idempotency_keys_demo
                    GROUP BY tenant_id, idempotency_key
                    HAVING count(*) > 1
                ) THEN
                    RAISE EXCEPTION 'AI contract demo contains distinct agency scopes that cannot be restored to tenant-only uniqueness; no data was deleted'
                        USING ERRCODE = '23514';
                END IF;
            END;
            $$;

            DROP TRIGGER IF EXISTS ai_idempotency_demo_append_only ON ai_idempotency_keys_demo;
            DROP TRIGGER IF EXISTS ai_idempotency_demo_scope_guard ON ai_idempotency_keys_demo;
            DROP FUNCTION IF EXISTS enforce_ai_idempotency_demo_scope();

            ALTER TABLE ai_advisory_records_demo
                DROP CONSTRAINT ai_advisory_demo_external_unique,
                ADD CONSTRAINT ai_advisory_demo_external_unique
                    UNIQUE (tenant_id, module_id, external_record_id);

            ALTER TABLE ai_idempotency_keys_demo
                DROP CONSTRAINT ai_idempotency_demo_agency_fk,
                DROP CONSTRAINT ai_idempotency_demo_advisory_unique,
                DROP CONSTRAINT ai_idempotency_demo_tenant_key_unique,
                ADD CONSTRAINT ai_idempotency_demo_tenant_key_unique
                    UNIQUE (tenant_id, idempotency_key),
                DROP COLUMN agency_id;

            CREATE TRIGGER ai_idempotency_demo_append_only
            BEFORE UPDATE OR DELETE ON ai_idempotency_keys_demo
            FOR EACH ROW EXECUTE FUNCTION prevent_ai_contract_demo_mutation();
        SQL);
    }
};
