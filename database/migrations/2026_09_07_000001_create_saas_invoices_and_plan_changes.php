<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('saas_subscriptions', function (Blueprint $table) {
            $table->unsignedBigInteger('previous_subscription_id')->nullable();
            $table->timestampTz('change_expires_at')->nullable();
            $table->boolean('billing_suspended')->default(false);
            $table->boolean('auto_renew')->default(false);
            $table->foreign(['tenant_id', 'previous_subscription_id'], 'saas_previous_scope_fk')
                ->references(['tenant_id', 'id'])->on('saas_subscriptions')->restrictOnDelete();
        });

        Schema::create('saas_invoices', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('saas_subscription_id');
            $table->string('number', 50)->unique();
            $table->string('type', 20);
            $table->string('status', 20)->default('open');
            $table->decimal('amount', 14, 2);
            $table->char('currency', 3);
            $table->jsonb('snapshot');
            $table->timestampTz('period_starts_at');
            $table->timestampTz('period_ends_at');
            $table->timestampTz('due_at');
            $table->timestampTz('paid_at')->nullable();
            $table->unsignedBigInteger('saas_payment_id')->nullable();
            $table->timestampsTz();
            $table->unique(['tenant_id', 'saas_subscription_id', 'id'], 'saas_invoice_scope_unique');
            $table->unique(['saas_subscription_id', 'period_starts_at'], 'saas_invoice_period_unique');
            $table->index(['status', 'due_at']);
            $table->foreign(['tenant_id', 'saas_subscription_id'], 'saas_invoice_subscription_fk')
                ->references(['tenant_id', 'id'])->on('saas_subscriptions')->restrictOnDelete();
            $table->foreign(['tenant_id', 'saas_subscription_id', 'saas_payment_id'], 'saas_invoice_payment_fk')
                ->references(['tenant_id', 'saas_subscription_id', 'id'])->on('saas_payments')->restrictOnDelete();
        });

        foreach (['saas_payments', 'saas_payment_attempts'] as $name) {
            Schema::table($name, function (Blueprint $table) use ($name) {
                $table->uuid('saas_invoice_id')->nullable();
                $table->foreign(['tenant_id', 'saas_subscription_id', 'saas_invoice_id'], $name.'_invoice_fk')
                    ->references(['tenant_id', 'saas_subscription_id', 'id'])->on('saas_invoices')->restrictOnDelete();
            });
        }

        Schema::create('saas_invoice_events', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('saas_invoice_id')->constrained('saas_invoices')->restrictOnDelete();
            $table->string('type', 30);
            $table->string('event_key', 100)->unique();
            $table->timestampTz('created_at')->useCurrent();
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE saas_subscriptions DROP CONSTRAINT saas_subscriptions_status_check;
            ALTER TABLE saas_subscriptions
                ADD CONSTRAINT saas_subscriptions_status_check CHECK (
                    status IN ('pending_payment', 'trialing', 'active', 'past_due', 'suspended', 'cancelled', 'expired')),
                ADD CONSTRAINT saas_billing_suspension_check CHECK (NOT billing_suspended OR status = 'suspended'),
                ADD CONSTRAINT saas_change_shape_check CHECK (
                    (previous_subscription_id IS NULL) = (change_expires_at IS NULL)
                    AND (previous_subscription_id IS NULL OR previous_subscription_id <> id)
                    AND (status <> 'pending_payment' OR previous_subscription_id IS NOT NULL));
            CREATE UNIQUE INDEX saas_one_pending_change_idx ON saas_subscriptions (tenant_id)
                WHERE status = 'pending_payment';
            UPDATE saas_payment_attempts SET status = 'expired', resolved_at = CURRENT_TIMESTAMP,
                updated_at = CURRENT_TIMESTAMP WHERE status = 'pending' AND expires_at <= CURRENT_TIMESTAMP;
            CREATE UNIQUE INDEX saas_one_pending_checkout_idx ON saas_payment_attempts (saas_subscription_id)
                WHERE status = 'pending';

            ALTER TABLE saas_invoices
                ADD CONSTRAINT saas_invoice_type_check CHECK (type IN ('renewal', 'plan_change')),
                ADD CONSTRAINT saas_invoice_status_check CHECK (status IN ('open', 'paid', 'void')),
                ADD CONSTRAINT saas_invoice_amount_check CHECK (amount >= 0),
                ADD CONSTRAINT saas_invoice_currency_check CHECK (currency ~ '^[A-Z]{3}$'),
                ADD CONSTRAINT saas_invoice_snapshot_check CHECK ((jsonb_typeof(snapshot) = 'object') IS TRUE),
                ADD CONSTRAINT saas_invoice_period_check CHECK (period_ends_at > period_starts_at),
                ADD CONSTRAINT saas_invoice_paid_check CHECK (
                    (status = 'paid') = (paid_at IS NOT NULL)
                    AND (status = 'paid' OR saas_payment_id IS NULL)
                    AND (status <> 'paid' OR amount = 0 OR saas_payment_id IS NOT NULL));
            ALTER TABLE saas_invoice_events ADD CONSTRAINT saas_invoice_event_type_check
                CHECK (type IN ('issued', 'paid', 'overdue', 'voided', 'reversed'));

            CREATE OR REPLACE FUNCTION rentfleet_guard_saas_subscription() RETURNS trigger AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    RAISE EXCEPTION 'SaaS subscriptions cannot be deleted' USING ERRCODE = '23514';
                END IF;

                IF NEW.tenant_id IS DISTINCT FROM OLD.tenant_id
                   OR NEW.saas_plan_id IS DISTINCT FROM OLD.saas_plan_id
                   OR NEW.billing_interval IS DISTINCT FROM OLD.billing_interval
                   OR NEW.price_amount IS DISTINCT FROM OLD.price_amount
                   OR NEW.currency IS DISTINCT FROM OLD.currency
                   OR NEW.entitlements IS DISTINCT FROM OLD.entitlements
                   OR NEW.previous_subscription_id IS DISTINCT FROM OLD.previous_subscription_id
                   OR NEW.change_expires_at IS DISTINCT FROM OLD.change_expires_at
                   OR NEW.starts_at IS DISTINCT FROM OLD.starts_at
                   OR NEW.created_by IS DISTINCT FROM OLD.created_by
                   OR NEW.created_at IS DISTINCT FROM OLD.created_at THEN
                    RAISE EXCEPTION 'SaaS subscription scope, price and entitlements snapshot are immutable' USING ERRCODE = '23514';
                END IF;

                IF OLD.status IN ('cancelled', 'expired') THEN
                    RAISE EXCEPTION 'Terminal SaaS subscriptions are immutable' USING ERRCODE = '23514';
                END IF;

                IF NEW.status IS DISTINCT FROM OLD.status AND NOT (
                    (OLD.status = 'pending_payment' AND NEW.status IN ('active', 'cancelled', 'expired'))
                    OR (OLD.status = 'trialing' AND NEW.status IN ('active', 'suspended', 'cancelled', 'expired'))
                    OR (OLD.status = 'active' AND NEW.status IN ('past_due', 'suspended', 'cancelled', 'expired'))
                    OR (OLD.status = 'past_due' AND NEW.status IN ('active', 'suspended', 'cancelled', 'expired'))
                    OR (OLD.status = 'suspended' AND NEW.status IN ('active', 'past_due', 'cancelled', 'expired'))
                ) THEN
                    RAISE EXCEPTION 'Invalid SaaS subscription status transition' USING ERRCODE = '23514';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE FUNCTION belkhir_guard_saas_invoice() RETURNS trigger AS $$
            DECLARE subscription_row saas_subscriptions%ROWTYPE;
            BEGIN
                IF TG_OP = 'INSERT' THEN
                    SELECT * INTO subscription_row FROM saas_subscriptions WHERE id = NEW.saas_subscription_id;
                    IF NOT FOUND OR NEW.tenant_id <> subscription_row.tenant_id
                        OR NEW.amount <> subscription_row.price_amount OR NEW.currency <> subscription_row.currency
                        OR NEW.status <> 'open'
                        OR (NEW.type = 'plan_change') <> (subscription_row.status = 'pending_payment') THEN
                        RAISE EXCEPTION 'Invoice must match its subscription snapshot' USING ERRCODE = '23514';
                    END IF;
                    RETURN NEW;
                END IF;
                IF TG_OP = 'DELETE' THEN
                    RAISE EXCEPTION 'SaaS invoices cannot be deleted' USING ERRCODE = '23514';
                END IF;
                IF (to_jsonb(NEW) - ARRAY['status', 'paid_at', 'saas_payment_id', 'updated_at'])
                    IS DISTINCT FROM
                    (to_jsonb(OLD) - ARRAY['status', 'paid_at', 'saas_payment_id', 'updated_at'])
                    OR OLD.status = 'void'
                    OR (NEW.status <> OLD.status AND NOT (
                        (OLD.status = 'open' AND NEW.status IN ('paid', 'void'))
                        OR (OLD.status = 'paid' AND NEW.status IN ('open', 'void'))
                    )) THEN
                    RAISE EXCEPTION 'SaaS invoice snapshot or transition is immutable' USING ERRCODE = '23514';
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;
            CREATE TRIGGER saas_invoices_guard BEFORE INSERT OR UPDATE OR DELETE ON saas_invoices
                FOR EACH ROW EXECUTE FUNCTION belkhir_guard_saas_invoice();
            CREATE TRIGGER saas_invoice_events_guard BEFORE UPDATE OR DELETE ON saas_invoice_events
                FOR EACH ROW EXECUTE FUNCTION belkhir_guard_saas_gateway_event();

            CREATE FUNCTION belkhir_guard_saas_invoice_link() RETURNS trigger AS $$
            DECLARE invoice_row saas_invoices%ROWTYPE;
            BEGIN
                IF TG_OP = 'UPDATE' AND NEW.saas_invoice_id IS DISTINCT FROM OLD.saas_invoice_id THEN
                    RAISE EXCEPTION 'SaaS invoice link is immutable' USING ERRCODE = '23514';
                END IF;
                IF NEW.saas_invoice_id IS NOT NULL THEN
                    SELECT * INTO invoice_row FROM saas_invoices WHERE id = NEW.saas_invoice_id;
                    IF NOT FOUND OR NEW.tenant_id <> invoice_row.tenant_id
                        OR NEW.saas_subscription_id <> invoice_row.saas_subscription_id
                        OR NEW.amount <> invoice_row.amount OR NEW.currency <> invoice_row.currency THEN
                        RAISE EXCEPTION 'SaaS invoice payment must match exactly' USING ERRCODE = '23514';
                    END IF;
                END IF;
                IF TG_TABLE_NAME = 'saas_payments' THEN
                    IF NEW.entry_type = 'reversal' AND NEW.saas_invoice_id IS DISTINCT FROM
                        (SELECT saas_invoice_id FROM saas_payments WHERE id = NEW.reversal_of_id) THEN
                        RAISE EXCEPTION 'Invoice reversal must mirror original linkage' USING ERRCODE = '23514';
                    END IF;
                    IF NEW.entry_type = 'payment' AND NEW.saas_invoice_id IS NULL AND EXISTS (
                        SELECT 1 FROM saas_invoices WHERE saas_subscription_id = NEW.saas_subscription_id
                    ) THEN
                        RAISE EXCEPTION 'Invoice-ledger subscriptions require an invoice payment' USING ERRCODE = '23514';
                    END IF;
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;
            CREATE TRIGGER saas_payment_invoice_link BEFORE INSERT ON saas_payments
                FOR EACH ROW EXECUTE FUNCTION belkhir_guard_saas_invoice_link();
            CREATE TRIGGER saas_attempt_invoice_link BEFORE INSERT OR UPDATE ON saas_payment_attempts
                FOR EACH ROW EXECUTE FUNCTION belkhir_guard_saas_invoice_link();

            CREATE FUNCTION belkhir_check_saas_invoice_settlement() RETURNS trigger AS $$
            DECLARE invoice_id uuid; invoice_row saas_invoices%ROWTYPE; paid_row saas_payments%ROWTYPE;
            BEGIN
                IF TG_TABLE_NAME = 'saas_invoices' THEN invoice_id := NEW.id;
                ELSE invoice_id := NEW.saas_invoice_id;
                END IF;
                IF invoice_id IS NULL THEN RETURN NULL; END IF;
                SELECT * INTO invoice_row FROM saas_invoices WHERE id = invoice_id;
                IF invoice_row.status = 'paid' AND invoice_row.amount > 0 THEN
                    SELECT * INTO paid_row FROM saas_payments WHERE id = invoice_row.saas_payment_id;
                    IF NOT FOUND OR paid_row.entry_type <> 'payment'
                        OR paid_row.saas_invoice_id IS DISTINCT FROM invoice_id
                        OR EXISTS (SELECT 1 FROM saas_payments WHERE reversal_of_id = paid_row.id) THEN
                        RAISE EXCEPTION 'Invoice requires an unreversed matching payment' USING ERRCODE = '23514';
                    END IF;
                END IF;
                IF EXISTS (
                    SELECT 1 FROM saas_payments p WHERE p.saas_invoice_id = invoice_id
                    AND p.entry_type = 'payment'
                    AND NOT EXISTS (SELECT 1 FROM saas_payments r WHERE r.reversal_of_id = p.id)
                    AND (invoice_row.status <> 'paid' OR invoice_row.saas_payment_id IS DISTINCT FROM p.id)
                ) THEN
                    RAISE EXCEPTION 'Payment and invoice settlement must be atomic' USING ERRCODE = '23514';
                END IF;
                RETURN NULL;
            END;
            $$ LANGUAGE plpgsql;
            CREATE CONSTRAINT TRIGGER saas_invoice_settlement_check AFTER INSERT OR UPDATE ON saas_invoices
                DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION belkhir_check_saas_invoice_settlement();
            CREATE CONSTRAINT TRIGGER saas_payment_settlement_check AFTER INSERT ON saas_payments
                DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION belkhir_check_saas_invoice_settlement();
        SQL);
    }

    public function down(): void
    {
        // Deliberately fail closed: billing history must never disappear on rollback.
        throw new RuntimeException('SaaS billing migration is forward-only. Restore a verified backup or deploy a corrective migration.');
    }
};
