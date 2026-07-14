<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION rentfleet_protect_issued_invoice() RETURNS trigger AS $$
            BEGIN
                IF TG_OP = 'DELETE' AND OLD.status <> 'draft' THEN
                    RAISE EXCEPTION 'issued invoices cannot be deleted' USING ERRCODE = '23514';
                END IF;
                IF TG_OP = 'UPDATE' AND OLD.status IN ('issued', 'partially_paid', 'paid')
                   AND (to_jsonb(NEW) - ARRAY['status','paid_amount','balance_due','updated_at'])
                       IS DISTINCT FROM (to_jsonb(OLD) - ARRAY['status','paid_amount','balance_due','updated_at']) THEN
                    RAISE EXCEPTION 'issued invoice content is immutable' USING ERRCODE = '23514';
                END IF;
                IF TG_OP = 'UPDATE' AND OLD.status <> 'draft' AND NEW.deleted_at IS DISTINCT FROM OLD.deleted_at THEN
                    RAISE EXCEPTION 'only draft invoices may be soft deleted' USING ERRCODE = '23514';
                END IF;
                RETURN CASE WHEN TG_OP = 'DELETE' THEN OLD ELSE NEW END;
            END;
            $$ LANGUAGE plpgsql;
            CREATE TRIGGER invoices_financial_immutability
            BEFORE UPDATE OR DELETE ON invoices FOR EACH ROW EXECUTE FUNCTION rentfleet_protect_issued_invoice();

            CREATE OR REPLACE FUNCTION rentfleet_protect_issued_invoice_line() RETURNS trigger AS $$
            DECLARE invoice_status text;
            BEGIN
                SELECT status INTO invoice_status FROM invoices WHERE id = COALESCE(OLD.invoice_id, NEW.invoice_id);
                IF invoice_status <> 'draft' THEN
                    RAISE EXCEPTION 'issued invoice lines are immutable' USING ERRCODE = '23514';
                END IF;
                RETURN CASE WHEN TG_OP = 'DELETE' THEN OLD ELSE NEW END;
            END;
            $$ LANGUAGE plpgsql;
            CREATE TRIGGER invoice_lines_financial_immutability
            BEFORE UPDATE OR DELETE ON invoice_lines FOR EACH ROW EXECUTE FUNCTION rentfleet_protect_issued_invoice_line();

            CREATE OR REPLACE FUNCTION rentfleet_protect_payment() RETURNS trigger AS $$
            BEGIN
                IF TG_OP = 'DELETE' AND OLD.status IN ('posted', 'reversed') THEN
                    RAISE EXCEPTION 'posted payments are immutable' USING ERRCODE = '23514';
                END IF;
                IF TG_OP = 'UPDATE' AND OLD.status IN ('posted', 'reversed')
                   AND NOT (OLD.status = 'posted' AND NEW.status = 'reversed'
                       AND (to_jsonb(NEW) - ARRAY['status','updated_at']) = (to_jsonb(OLD) - ARRAY['status','updated_at'])) THEN
                    RAISE EXCEPTION 'posted payments are immutable' USING ERRCODE = '23514';
                END IF;
                RETURN CASE WHEN TG_OP = 'DELETE' THEN OLD ELSE NEW END;
            END;
            $$ LANGUAGE plpgsql;
            CREATE TRIGGER payments_financial_immutability
            BEFORE UPDATE OR DELETE ON payments FOR EACH ROW EXECUTE FUNCTION rentfleet_protect_payment();

            CREATE OR REPLACE FUNCTION rentfleet_immutable_ledger_row() RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION 'financial ledger rows are immutable' USING ERRCODE = '23514';
            END;
            $$ LANGUAGE plpgsql;
            CREATE TRIGGER payment_allocations_financial_immutability BEFORE UPDATE OR DELETE ON payment_allocations FOR EACH ROW EXECUTE FUNCTION rentfleet_immutable_ledger_row();
            CREATE TRIGGER deposit_transactions_financial_immutability BEFORE UPDATE OR DELETE ON deposit_transactions FOR EACH ROW EXECUTE FUNCTION rentfleet_immutable_ledger_row();

            DROP TRIGGER IF EXISTS rental_contracts_prevent_closed_before_finance ON rental_contracts;
            CREATE OR REPLACE FUNCTION rentfleet_prevent_contract_closed_before_finance() RETURNS trigger AS $$
            DECLARE invoice_ok boolean; pending_count bigint;
            BEGIN
                IF NEW.status = 'closed' AND OLD.status IS DISTINCT FROM 'closed' THEN
                    SELECT EXISTS(SELECT 1 FROM invoices i WHERE i.id = NEW.invoice_id AND i.tenant_id = NEW.tenant_id AND i.status = 'paid' AND i.balance_due = 0) INTO invoice_ok;
                    SELECT COUNT(*) INTO pending_count FROM payments p WHERE p.tenant_id = NEW.tenant_id AND p.rental_contract_id = NEW.id AND p.status = 'pending';
                    IF OLD.status <> 'returned' OR NOT invoice_ok OR NEW.balance_due <> 0
                       OR NEW.deposit_received <> NEW.deposit_retained + NEW.deposit_refunded
                       OR pending_count <> 0 OR NEW.closed_at IS NULL OR NEW.closed_by IS NULL OR NEW.financially_settled_at IS NULL THEN
                        RAISE EXCEPTION 'contract financial settlement is incomplete' USING ERRCODE = '23514';
                    END IF;
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;
            CREATE TRIGGER rental_contracts_prevent_closed_before_finance
            BEFORE INSERT OR UPDATE ON rental_contracts FOR EACH ROW EXECUTE FUNCTION rentfleet_prevent_contract_closed_before_finance();
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS invoices_financial_immutability ON invoices;
            DROP TRIGGER IF EXISTS invoice_lines_financial_immutability ON invoice_lines;
            DROP TRIGGER IF EXISTS payments_financial_immutability ON payments;
            DROP TRIGGER IF EXISTS payment_allocations_financial_immutability ON payment_allocations;
            DROP TRIGGER IF EXISTS deposit_transactions_financial_immutability ON deposit_transactions;
            DROP FUNCTION IF EXISTS rentfleet_protect_issued_invoice();
            DROP FUNCTION IF EXISTS rentfleet_protect_issued_invoice_line();
            DROP FUNCTION IF EXISTS rentfleet_protect_payment();
            DROP FUNCTION IF EXISTS rentfleet_immutable_ledger_row();
            DROP TRIGGER IF EXISTS rental_contracts_prevent_closed_before_finance ON rental_contracts;
            CREATE OR REPLACE FUNCTION rentfleet_prevent_contract_closed_before_finance() RETURNS trigger AS $$
            BEGIN
                IF NEW.status = 'closed' THEN RAISE EXCEPTION 'closed status is reserved for the financial lot' USING ERRCODE = '23514'; END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;
            CREATE TRIGGER rental_contracts_prevent_closed_before_finance BEFORE INSERT OR UPDATE ON rental_contracts FOR EACH ROW EXECUTE FUNCTION rentfleet_prevent_contract_closed_before_finance();
        SQL);
    }
};
