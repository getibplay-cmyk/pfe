<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class AuditSaasBillingCommand extends Command
{
    protected $signature = 'saas:audit-billing {--json}';

    protected $description = 'Contrôle en lecture seule les gardes PostgreSQL du lot de facturation SaaS';

    public function handle(): int
    {
        $checks = [];
        $source = file_get_contents(database_path('migrations/2026_09_07_000001_create_saas_invoices_and_plan_changes.php'))
            .file_get_contents(database_path('migrations/2026_09_02_000003_create_cmi_saas_payment_attempts.php'));
        foreach ([
            ['saas_subscriptions', 'saas_subscriptions_guard', 'rentfleet_guard_saas_subscription', 27, false],
            ['saas_invoices', 'saas_invoices_guard', 'belkhir_guard_saas_invoice', 31, false],
            ['saas_invoice_events', 'saas_invoice_events_guard', 'belkhir_guard_saas_gateway_event', 27, false],
            ['saas_payments', 'saas_payment_invoice_link', 'belkhir_guard_saas_invoice_link', 7, false],
            ['saas_payment_attempts', 'saas_attempt_invoice_link', 'belkhir_guard_saas_invoice_link', 23, false],
            ['saas_invoices', 'saas_invoice_settlement_check', 'belkhir_check_saas_invoice_settlement', 21, true],
            ['saas_payments', 'saas_payment_settlement_check', 'belkhir_check_saas_invoice_settlement', 5, true],
        ] as [$table, $name, $function, $type, $deferred]) {
            $row = DB::selectOne(<<<'SQL'
                SELECT t.tgenabled, t.tgtype, t.tgdeferrable, t.tginitdeferred, p.proname, p.prosrc,
                    pn.nspname = current_schema() AS function_schema_matches
                FROM pg_trigger t JOIN pg_class c ON c.oid = t.tgrelid
                JOIN pg_namespace n ON n.oid = c.relnamespace
                JOIN pg_proc p ON p.oid = t.tgfoid JOIN pg_namespace pn ON pn.oid = p.pronamespace
                WHERE n.nspname = current_schema() AND c.relname = ? AND t.tgname = ? AND NOT t.tgisinternal
            SQL, [$table, $name]);
            preg_match('/FUNCTION\s+'.preg_quote($function, '/').'\(\)\s+RETURNS trigger AS \$\$(.*?)\$\$/s', $source, $body);
            $checks[$name] = $row !== null && $row->tgenabled === 'O' && (int) $row->tgtype === $type
                && (bool) $row->tgdeferrable === $deferred && (bool) $row->tginitdeferred === $deferred
                && $row->proname === $function && $row->function_schema_matches
                && isset($body[1]) && $this->normalize($row->prosrc) === $this->normalize($body[1]);
        }
        foreach ([
            ['saas_invoice_period_unique', 'saas_invoices', ['saas_subscription_id', 'period_starts_at'], null],
            ['saas_one_pending_change_idx', 'saas_subscriptions', ['tenant_id'], "status='pending_payment'"],
            ['saas_one_pending_checkout_idx', 'saas_payment_attempts', ['saas_subscription_id'], "status='pending'"],
            ['saas_invoice_events_event_key_unique', 'saas_invoice_events', ['event_key'], null],
        ] as [$name, $table, $columns, $predicate]) {
            $row = DB::selectOne(<<<'SQL'
                SELECT i.indisunique, i.indisvalid, i.indisready, pg_get_expr(i.indpred, i.indrelid) AS predicate,
                    (SELECT json_agg(a.attname ORDER BY k.ordinality) FROM unnest(i.indkey) WITH ORDINALITY k(attnum, ordinality)
                        JOIN pg_attribute a ON a.attrelid = i.indrelid AND a.attnum = k.attnum) AS columns
                FROM pg_index i JOIN pg_class idx ON idx.oid = i.indexrelid JOIN pg_class c ON c.oid = i.indrelid
                JOIN pg_namespace n ON n.oid = c.relnamespace
                WHERE n.nspname = current_schema() AND c.relname = ? AND idx.relname = ?
            SQL, [$table, $name]);
            $checks[$name] = $row !== null && $row->indisunique && $row->indisvalid && $row->indisready
                && json_decode($row->columns, true) === $columns
                && ($predicate === null ? $row->predicate === null : $this->expression($row->predicate) === $predicate);
        }
        foreach ([
            ['saas_invoices', 'saas_invoice_amount_check', 'checkamount>=0'],
            ['saas_invoices', 'saas_invoice_period_check', 'checkperiod_ends_at>period_starts_at'],
            ['saas_subscriptions', 'saas_billing_suspension_check', "checknotbilling_suspendedorstatus='suspended'"],
        ] as [$table, $name, $definition]) {
            $row = DB::selectOne(<<<'SQL'
                SELECT con.contype, con.convalidated, pg_get_constraintdef(con.oid) AS definition
                FROM pg_constraint con JOIN pg_class c ON c.oid = con.conrelid
                JOIN pg_namespace n ON n.oid = c.relnamespace
                WHERE n.nspname = current_schema() AND c.relname = ? AND con.conname = ?
            SQL, [$table, $name]);
            $checks[$name] = $row !== null && $row->contype === 'c' && $row->convalidated
                && $this->expression($row->definition) === $definition;
        }
        $ok = ! in_array(false, $checks, true);
        $result = ['ok' => $ok, 'checks' => $checks, 'scope' => 'Structural billing guards; not a penetration test or a compliance certification.'];
        if ($this->option('json')) {
            $this->line(json_encode($result, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
        } else {
            $this->table(['Contrôle', 'Résultat'], collect($checks)->map(fn ($valid, $name) => [$name, $valid ? 'OK' : 'ÉCHEC'])->all());
        }

        return $ok ? self::SUCCESS : self::FAILURE;
    }

    private function normalize(string $body): string
    {
        return preg_replace('/\s+/', ' ', trim($body));
    }

    private function expression(string $expression): string
    {
        return strtolower(preg_replace('/\s|[()]/', '', str_replace(['::text', '::numeric'], '', $expression)));
    }
}
