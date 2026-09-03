<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE saas_payments DROP CONSTRAINT saas_payments_method_check');
        DB::statement(<<<'SQL'
            ALTER TABLE saas_payments
                ADD CONSTRAINT saas_payments_method_check
                    CHECK (payment_method IN ('bank_transfer', 'cash', 'cheque', 'cmi', 'other'))
        SQL);

        Schema::create('saas_payment_attempts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('saas_subscription_id');
            $table->string('provider', 20);
            $table->string('merchant_order_id', 64)->unique();
            $table->string('status', 20);
            $table->decimal('amount', 14, 2);
            $table->char('currency', 3);
            $table->string('idempotency_key', 100);
            $table->string('gateway_transaction_id', 100)->nullable();
            $table->string('gateway_response_code', 50)->nullable();
            $table->foreignId('initiated_by')->constrained('users')->restrictOnDelete();
            $table->timestampTz('expires_at');
            $table->timestampTz('resolved_at')->nullable();
            $table->timestampTz('paid_at')->nullable();
            $table->timestampsTz();

            $table->unique(['tenant_id', 'idempotency_key'], 'saas_payment_attempts_tenant_idempotency_unique');
            $table->index(['tenant_id', 'status', 'created_at'], 'saas_payment_attempts_tenant_status_idx');
            $table->foreign(
                ['tenant_id', 'saas_subscription_id'],
                'saas_payment_attempts_subscription_scope_fk',
            )->references(['tenant_id', 'id'])->on('saas_subscriptions')->restrictOnDelete();
        });

        Schema::create('saas_payment_gateway_events', function (Blueprint $table) {
            $table->id();
            $table->uuid('saas_payment_attempt_id');
            $table->string('provider', 20);
            $table->char('provider_event_key', 64)->unique();
            $table->char('payload_sha256', 64);
            $table->boolean('signature_valid');
            $table->string('processing_result', 20);
            $table->string('response_code', 50)->nullable();
            $table->timestampTz('received_at');
            $table->timestampTz('created_at')->useCurrent();

            $table->foreign('saas_payment_attempt_id', 'saas_gateway_events_attempt_fk')
                ->references('id')->on('saas_payment_attempts')->restrictOnDelete();
            $table->index(['saas_payment_attempt_id', 'received_at'], 'saas_gateway_events_attempt_date_idx');
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE saas_payment_attempts
                ADD CONSTRAINT saas_payment_attempts_provider_check
                    CHECK (provider = 'cmi'),
                ADD CONSTRAINT saas_payment_attempts_status_check
                    CHECK (status IN ('pending', 'paid', 'failed', 'cancelled', 'expired')),
                ADD CONSTRAINT saas_payment_attempts_amount_check
                    CHECK (amount > 0),
                ADD CONSTRAINT saas_payment_attempts_currency_check
                    CHECK (currency ~ '^[A-Z]{3}$'),
                ADD CONSTRAINT saas_payment_attempts_order_check
                    CHECK (merchant_order_id = btrim(merchant_order_id) AND btrim(merchant_order_id) <> ''),
                ADD CONSTRAINT saas_payment_attempts_idempotency_check
                    CHECK (idempotency_key = btrim(idempotency_key) AND btrim(idempotency_key) <> ''),
                ADD CONSTRAINT saas_payment_attempts_expiry_check
                    CHECK (expires_at > created_at),
                ADD CONSTRAINT saas_payment_attempts_resolution_check
                    CHECK ((status = 'pending') = (resolved_at IS NULL)),
                ADD CONSTRAINT saas_payment_attempts_paid_check
                    CHECK ((status = 'paid') = (paid_at IS NOT NULL)),
                ADD CONSTRAINT saas_payment_attempts_transaction_check
                    CHECK (status <> 'paid' OR btrim(COALESCE(gateway_transaction_id, '')) <> '');

            ALTER TABLE saas_payment_gateway_events
                ADD CONSTRAINT saas_gateway_events_provider_check
                    CHECK (provider = 'cmi'),
                ADD CONSTRAINT saas_gateway_events_key_check
                    CHECK (provider_event_key ~ '^[a-f0-9]{64}$'),
                ADD CONSTRAINT saas_gateway_events_payload_check
                    CHECK (payload_sha256 ~ '^[a-f0-9]{64}$'),
                ADD CONSTRAINT saas_gateway_events_result_check
                    CHECK (processing_result IN ('accepted', 'declined', 'rejected', 'duplicate'));

            CREATE OR REPLACE FUNCTION belkhir_guard_saas_payment_attempt() RETURNS trigger AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    RAISE EXCEPTION 'SaaS payment attempts cannot be deleted' USING ERRCODE = '23514';
                END IF;

                IF NEW.id IS DISTINCT FROM OLD.id
                   OR NEW.tenant_id IS DISTINCT FROM OLD.tenant_id
                   OR NEW.saas_subscription_id IS DISTINCT FROM OLD.saas_subscription_id
                   OR NEW.provider IS DISTINCT FROM OLD.provider
                   OR NEW.merchant_order_id IS DISTINCT FROM OLD.merchant_order_id
                   OR NEW.amount IS DISTINCT FROM OLD.amount
                   OR NEW.currency IS DISTINCT FROM OLD.currency
                   OR NEW.idempotency_key IS DISTINCT FROM OLD.idempotency_key
                   OR NEW.initiated_by IS DISTINCT FROM OLD.initiated_by
                   OR NEW.expires_at IS DISTINCT FROM OLD.expires_at
                   OR NEW.created_at IS DISTINCT FROM OLD.created_at THEN
                    RAISE EXCEPTION 'SaaS payment attempt identity is immutable' USING ERRCODE = '23514';
                END IF;

                IF OLD.status <> 'pending' AND NEW IS DISTINCT FROM OLD THEN
                    RAISE EXCEPTION 'Terminal SaaS payment attempts are immutable' USING ERRCODE = '23514';
                END IF;

                IF NEW.status IS DISTINCT FROM OLD.status
                   AND NOT (OLD.status = 'pending' AND NEW.status IN ('paid', 'failed', 'cancelled', 'expired')) THEN
                    RAISE EXCEPTION 'Invalid SaaS payment attempt transition' USING ERRCODE = '23514';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER saas_payment_attempts_guard
                BEFORE UPDATE OR DELETE ON saas_payment_attempts
                FOR EACH ROW EXECUTE FUNCTION belkhir_guard_saas_payment_attempt();

            CREATE OR REPLACE FUNCTION belkhir_guard_saas_gateway_event() RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION 'SaaS gateway events are append-only' USING ERRCODE = '23514';
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER saas_payment_gateway_events_guard
                BEFORE UPDATE OR DELETE ON saas_payment_gateway_events
                FOR EACH ROW EXECUTE FUNCTION belkhir_guard_saas_gateway_event();
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS saas_payment_gateway_events_guard ON saas_payment_gateway_events;
            DROP TRIGGER IF EXISTS saas_payment_attempts_guard ON saas_payment_attempts;
            DROP FUNCTION IF EXISTS belkhir_guard_saas_gateway_event();
            DROP FUNCTION IF EXISTS belkhir_guard_saas_payment_attempt();
        SQL);

        Schema::dropIfExists('saas_payment_gateway_events');
        Schema::dropIfExists('saas_payment_attempts');

        // CMI ledger entries are append-only and cannot be rewritten merely to
        // restore the older constraint during a rollback.
        $hasImmutableCmiEntries = DB::table('saas_payments')
            ->where('payment_method', 'cmi')
            ->exists();

        DB::statement('ALTER TABLE saas_payments DROP CONSTRAINT saas_payments_method_check');
        if ($hasImmutableCmiEntries) {
            DB::statement(<<<'SQL'
                ALTER TABLE saas_payments
                    ADD CONSTRAINT saas_payments_method_check
                        CHECK (payment_method IN ('bank_transfer', 'cash', 'cheque', 'cmi', 'other'))
            SQL);
        } else {
            DB::statement(<<<'SQL'
                ALTER TABLE saas_payments
                    ADD CONSTRAINT saas_payments_method_check
                        CHECK (payment_method IN ('bank_transfer', 'cash', 'cheque', 'other'))
            SQL);
        }
    }
};
