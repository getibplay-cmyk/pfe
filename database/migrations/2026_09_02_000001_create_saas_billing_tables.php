<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('saas_plans', function (Blueprint $table) {
            $table->id();
            $table->string('code', 50)->unique();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('billing_interval', 20);
            $table->decimal('price_amount', 14, 2);
            $table->char('currency', 3)->default('MAD');
            $table->jsonb('features')->default('[]');
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();

            $table->index(['is_active', 'billing_interval']);
        });

        Schema::create('saas_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignId('saas_plan_id')->constrained('saas_plans')->restrictOnDelete();
            $table->string('status', 20);
            $table->string('billing_interval', 20);
            $table->decimal('price_amount', 14, 2);
            $table->char('currency', 3);
            $table->timestampTz('starts_at');
            $table->timestampTz('ends_at')->nullable();
            $table->timestampTz('trial_ends_at')->nullable();
            $table->timestampTz('next_renewal_at')->nullable();
            $table->timestampTz('suspended_at')->nullable();
            $table->timestampTz('cancelled_at')->nullable();
            $table->timestampTz('expired_at')->nullable();
            $table->text('admin_note')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();

            $table->unique(['tenant_id', 'id'], 'saas_subscriptions_tenant_id_unique');
            $table->index(['tenant_id', 'status']);
            $table->index(['saas_plan_id', 'status']);
            $table->index(['status', 'ends_at']);
        });

        Schema::create('saas_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('saas_subscription_id');
            $table->string('entry_type', 20);
            $table->string('payment_method', 30);
            $table->decimal('amount', 14, 2);
            $table->char('currency', 3);
            $table->string('reference', 100)->nullable();
            $table->string('idempotency_key', 100);
            $table->timestampTz('occurred_at');
            $table->unsignedBigInteger('reversal_of_id')->nullable();
            $table->text('reason')->nullable();
            $table->text('note')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestampTz('created_at')->useCurrent();

            $table->unique(['tenant_id', 'idempotency_key'], 'saas_payments_tenant_idempotency_unique');
            $table->unique(
                ['tenant_id', 'saas_subscription_id', 'id'],
                'saas_payments_subscription_scope_unique',
            );
            $table->index(['tenant_id', 'occurred_at']);
            $table->index(['tenant_id', 'saas_subscription_id', 'occurred_at'], 'saas_payments_subscription_date_idx');
            $table->foreign(
                ['tenant_id', 'saas_subscription_id'],
                'saas_payments_subscription_scope_fk',
            )->references(['tenant_id', 'id'])->on('saas_subscriptions')->restrictOnDelete();
            $table->foreign(
                ['tenant_id', 'saas_subscription_id', 'reversal_of_id'],
                'saas_payments_reversal_scope_fk',
            )->references(['tenant_id', 'saas_subscription_id', 'id'])->on('saas_payments')->restrictOnDelete();
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE saas_plans
                ADD CONSTRAINT saas_plans_code_check
                    CHECK (code ~ '^[a-z0-9][a-z0-9_-]{1,49}$'),
                ADD CONSTRAINT saas_plans_billing_interval_check
                    CHECK (billing_interval IN ('monthly', 'annual')),
                ADD CONSTRAINT saas_plans_price_check
                    CHECK (price_amount >= 0),
                ADD CONSTRAINT saas_plans_currency_check
                    CHECK (currency ~ '^[A-Z]{3}$'),
                ADD CONSTRAINT saas_plans_features_check
                    CHECK (jsonb_typeof(features) = 'array');

            ALTER TABLE saas_subscriptions
                ADD CONSTRAINT saas_subscriptions_status_check
                    CHECK (status IN ('trialing', 'active', 'past_due', 'suspended', 'cancelled', 'expired')),
                ADD CONSTRAINT saas_subscriptions_billing_interval_check
                    CHECK (billing_interval IN ('monthly', 'annual')),
                ADD CONSTRAINT saas_subscriptions_price_check
                    CHECK (price_amount >= 0),
                ADD CONSTRAINT saas_subscriptions_currency_check
                    CHECK (currency ~ '^[A-Z]{3}$'),
                ADD CONSTRAINT saas_subscriptions_period_check
                    CHECK (ends_at IS NULL OR ends_at > starts_at),
                ADD CONSTRAINT saas_subscriptions_trial_period_check
                    CHECK (
                        trial_ends_at IS NULL
                        OR (
                            trial_ends_at > starts_at
                            AND (ends_at IS NULL OR trial_ends_at <= ends_at)
                        )
                    ),
                ADD CONSTRAINT saas_subscriptions_trial_status_check
                    CHECK (status <> 'trialing' OR trial_ends_at IS NOT NULL),
                ADD CONSTRAINT saas_subscriptions_renewal_check
                    CHECK (
                        next_renewal_at IS NULL
                        OR (
                            next_renewal_at > starts_at
                            AND (ends_at IS NULL OR next_renewal_at <= ends_at)
                        )
                    ),
                ADD CONSTRAINT saas_subscriptions_admin_note_check
                    CHECK (admin_note IS NULL OR btrim(admin_note) <> ''),
                ADD CONSTRAINT saas_subscriptions_suspension_check
                    CHECK ((status = 'suspended') = (suspended_at IS NOT NULL)),
                ADD CONSTRAINT saas_subscriptions_cancellation_check
                    CHECK ((status = 'cancelled') = (cancelled_at IS NOT NULL)),
                ADD CONSTRAINT saas_subscriptions_expiration_check
                    CHECK ((status = 'expired') = (expired_at IS NOT NULL));

            CREATE UNIQUE INDEX saas_subscriptions_one_current_per_tenant_idx
                ON saas_subscriptions (tenant_id)
                WHERE status IN ('trialing', 'active', 'past_due', 'suspended');

            ALTER TABLE saas_payments
                ADD CONSTRAINT saas_payments_entry_type_check
                    CHECK (entry_type IN ('payment', 'reversal')),
                ADD CONSTRAINT saas_payments_method_check
                    CHECK (payment_method IN ('bank_transfer', 'cash', 'cheque', 'other')),
                ADD CONSTRAINT saas_payments_amount_check
                    CHECK (amount > 0),
                ADD CONSTRAINT saas_payments_currency_check
                    CHECK (currency ~ '^[A-Z]{3}$'),
                ADD CONSTRAINT saas_payments_reference_check
                    CHECK (reference IS NULL OR (btrim(reference) <> '' AND reference = btrim(reference))),
                ADD CONSTRAINT saas_payments_idempotency_check
                    CHECK (btrim(idempotency_key) <> ''),
                ADD CONSTRAINT saas_payments_reversal_shape_check
                    CHECK ((entry_type = 'reversal') = (reversal_of_id IS NOT NULL)),
                ADD CONSTRAINT saas_payments_reversal_reason_check
                    CHECK (entry_type <> 'reversal' OR btrim(COALESCE(reason, '')) <> ''),
                ADD CONSTRAINT saas_payments_note_check
                    CHECK (note IS NULL OR btrim(note) <> '');

            CREATE UNIQUE INDEX saas_payments_reference_unique_idx
                ON saas_payments (lower(btrim(reference)))
                WHERE reference IS NOT NULL;

            CREATE UNIQUE INDEX saas_payments_one_reversal_per_original_idx
                ON saas_payments (reversal_of_id)
                WHERE reversal_of_id IS NOT NULL;

            CREATE OR REPLACE FUNCTION rentfleet_guard_saas_plan() RETURNS trigger AS $$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    RAISE EXCEPTION 'SaaS plans cannot be deleted' USING ERRCODE = '23514';
                END IF;

                IF NEW.code IS DISTINCT FROM OLD.code
                   OR NEW.billing_interval IS DISTINCT FROM OLD.billing_interval
                   OR NEW.created_by IS DISTINCT FROM OLD.created_by
                   OR NEW.created_at IS DISTINCT FROM OLD.created_at THEN
                    RAISE EXCEPTION 'SaaS plan identity is immutable' USING ERRCODE = '23514';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER saas_plans_guard
                BEFORE UPDATE OR DELETE ON saas_plans
                FOR EACH ROW EXECUTE FUNCTION rentfleet_guard_saas_plan();

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
                   OR NEW.starts_at IS DISTINCT FROM OLD.starts_at
                   OR NEW.created_by IS DISTINCT FROM OLD.created_by
                   OR NEW.created_at IS DISTINCT FROM OLD.created_at THEN
                    RAISE EXCEPTION 'SaaS subscription scope and price snapshot are immutable' USING ERRCODE = '23514';
                END IF;

                IF OLD.status IN ('cancelled', 'expired') THEN
                    RAISE EXCEPTION 'Terminal SaaS subscriptions are immutable' USING ERRCODE = '23514';
                END IF;

                IF NEW.status IS DISTINCT FROM OLD.status AND NOT (
                    (OLD.status = 'trialing' AND NEW.status IN ('active', 'suspended', 'cancelled', 'expired'))
                    OR (OLD.status = 'active' AND NEW.status IN ('past_due', 'suspended', 'cancelled', 'expired'))
                    OR (OLD.status = 'past_due' AND NEW.status IN ('active', 'suspended', 'cancelled', 'expired'))
                    OR (OLD.status = 'suspended' AND NEW.status IN ('active', 'past_due', 'cancelled', 'expired'))
                ) THEN
                    RAISE EXCEPTION 'Invalid SaaS subscription status transition' USING ERRCODE = '23514';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER saas_subscriptions_guard
                BEFORE UPDATE OR DELETE ON saas_subscriptions
                FOR EACH ROW EXECUTE FUNCTION rentfleet_guard_saas_subscription();

            CREATE OR REPLACE FUNCTION rentfleet_guard_saas_payment() RETURNS trigger AS $$
            DECLARE
                original saas_payments%ROWTYPE;
                subscription_row saas_subscriptions%ROWTYPE;
            BEGIN
                IF TG_OP IN ('UPDATE', 'DELETE') THEN
                    RAISE EXCEPTION 'SaaS payment ledger entries are immutable' USING ERRCODE = '23514';
                END IF;

                SELECT * INTO subscription_row
                FROM saas_subscriptions
                WHERE id = NEW.saas_subscription_id
                  AND tenant_id = NEW.tenant_id;

                IF NOT FOUND OR NEW.currency IS DISTINCT FROM subscription_row.currency THEN
                    RAISE EXCEPTION 'A SaaS payment must use its subscription currency' USING ERRCODE = '23514';
                END IF;

                IF NEW.entry_type = 'reversal' THEN
                    SELECT * INTO original
                    FROM saas_payments
                    WHERE id = NEW.reversal_of_id
                    FOR UPDATE;

                    IF NOT FOUND OR original.entry_type <> 'payment' THEN
                        RAISE EXCEPTION 'A reversal must reference an original SaaS payment' USING ERRCODE = '23514';
                    END IF;

                    IF NEW.tenant_id IS DISTINCT FROM original.tenant_id
                       OR NEW.saas_subscription_id IS DISTINCT FROM original.saas_subscription_id
                       OR NEW.payment_method IS DISTINCT FROM original.payment_method
                       OR NEW.amount IS DISTINCT FROM original.amount
                       OR NEW.currency IS DISTINCT FROM original.currency THEN
                        RAISE EXCEPTION 'A SaaS payment reversal must exactly mirror its original' USING ERRCODE = '23514';
                    END IF;

                    IF NEW.occurred_at < original.occurred_at THEN
                        RAISE EXCEPTION 'A SaaS payment reversal cannot predate its original' USING ERRCODE = '23514';
                    END IF;
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER saas_payments_guard
                BEFORE INSERT OR UPDATE OR DELETE ON saas_payments
                FOR EACH ROW EXECUTE FUNCTION rentfleet_guard_saas_payment();
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS saas_payments_guard ON saas_payments;
            DROP TRIGGER IF EXISTS saas_subscriptions_guard ON saas_subscriptions;
            DROP TRIGGER IF EXISTS saas_plans_guard ON saas_plans;
            DROP FUNCTION IF EXISTS rentfleet_guard_saas_payment();
            DROP FUNCTION IF EXISTS rentfleet_guard_saas_subscription();
            DROP FUNCTION IF EXISTS rentfleet_guard_saas_plan();
        SQL);

        Schema::dropIfExists('saas_payments');
        Schema::dropIfExists('saas_subscriptions');
        Schema::dropIfExists('saas_plans');
    }
};
