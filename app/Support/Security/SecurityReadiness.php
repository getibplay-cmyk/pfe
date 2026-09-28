<?php

namespace App\Support\Security;

use Illuminate\Support\Facades\DB;
use Throwable;

final class SecurityReadiness
{
    /** Configuration inspection only: it never changes a setting or contacts an external service. */
    public function inspect(bool $production = false, bool $database = false): array
    {
        $checks = [];
        $check = function (string $id, bool $ok, string $remediation) use (&$checks): void {
            $checks[] = ['id' => $id, 'status' => $ok ? 'PASS' : 'FAIL', 'remediation' => $remediation];
        };
        $check('web.private_storage', config('documents.disk') === 'local' && ! is_link(public_path('storage')), 'Keep documents on the private local disk; remove public storage links after checking their purpose.');
        $check('session.http_only', config('session.http_only') === true, 'Set SESSION_HTTP_ONLY=true.');
        $check('session.same_site', in_array(config('session.same_site'), ['lax', 'strict'], true), 'Use SameSite=lax or strict for the application session.');
        $check('session.host_prefix', ! str_starts_with((string) config('session.cookie'), '__Host-') || (config('session.secure') === true && config('session.path') === '/' && empty(config('session.domain'))), 'A __Host- cookie requires Secure, Path=/ and no Domain.');
        $check('session.absolute', (int) config('security.session_absolute_minutes') >= 15 && (int) config('security.session_absolute_minutes') <= 480, 'Set SECURITY_SESSION_ABSOLUTE_MINUTES between 15 and 480.');
        $check('auth.reauthentication', (int) config('auth.password_timeout') > 0 && (int) config('auth.password_timeout') <= 900, 'Require a fresh password confirmation within 900 seconds.');
        $check('auth.recovery_expiry', (int) config('auth.passwords.users.expire') > 0 && (int) config('auth.passwords.users.expire') <= 15, 'Expire password recovery tokens within 15 minutes.');
        $check('queue.retry_window', (int) config('queue.connections.database.retry_after') > 630, 'Keep database retry_after above the maximum 630 second worker timeout.');
        if ($production) {
            $check('app.production', app()->environment('production'), 'Run the final inspection with the effective production configuration.');
            $check('app.debug', config('app.debug') === false, 'Set APP_DEBUG=false and rebuild the configuration cache.');
            $check('logs.security', config('logging.channels.security.driver') === 'daily'
                && in_array(config('logging.channels.security.level'), ['debug', 'info', 'notice'], true)
                && (int) config('logging.channels.security.days') > 0, 'Keep the dedicated security channel enabled with notice-level events and bounded retention.');
            $check('web.https', parse_url((string) config('app.url'), PHP_URL_SCHEME) === 'https', 'Configure the canonical HTTPS APP_URL and test the entire proxy chain.');
            $check('auth.admin_mfa', config('security.mfa_require_admins') === true, 'Set SECURITY_MFA_REQUIRE_ADMINS=true; enroll all privileged accounts.');
            $check('auth.breach_screening', config('security.password_breach_check') === true, 'Enable password screening and permit only its configured HTTPS destination.');
            $check('auth.hash_cost', match (config('hashing.driver')) {
                'bcrypt' => (int) config('hashing.bcrypt.rounds') >= 12,
                'argon', 'argon2id' => (int) config('hashing.argon.memory') >= 65536 && (int) config('hashing.argon.time') >= 3,
                default => false,
            }, 'Use a supported password hasher with sufficient cost; benchmark before migration.');
            $check('session.secure', config('session.secure') === true && config('session.encrypt') === true && empty(config('session.domain')), 'Enable Secure/encrypted sessions and omit a parent cookie domain.');
            $check('session.idle', (int) config('session.lifetime') > 0 && (int) config('session.lifetime') <= 30, 'Set SESSION_LIFETIME to at most 30 minutes.');
            $check('session.shared', config('session.driver') === 'database', 'Use database sessions: device listing and revocation operate on the PostgreSQL session table.');
            $check('limits.shared', in_array(config('cache.default'), ['database', 'redis'], true), 'Use a shared cache for limits across application nodes.');
            $check('queue.asynchronous', config('queue.default') !== 'sync' && config('queue.default') !== 'null', 'Run asynchronous workers; monitor the dedicated notifications queue.');
            $check('mail.transport', PrivateMailTransport::allows(config('mail', [])), 'Configure direct SMTP without a log fallback or disabled certificate verification.');
            $check('mail.tls', PrivateMailTransport::allows(config('mail', []), requireTls: true), 'Require encrypted SMTP and verify its certificate on the deployed transport, including MAIL_URL options.');
            $check('mail.timeout', PrivateMailTransport::hasBoundedTimeout(config('mail', [])), 'Bound the effective mail transport timeout, including MAIL_URL overrides, to at most 15 seconds.');
            $check('files.scanner_configured', config('security.uploads.scan_required') === true && preg_match('~^(unix:///|tcp://(?:127\.0\.0\.1|\[::1\]):)~', (string) config('security.uploads.clamd_endpoint')) === 1, 'Configure the private clamd socket; verify signatures, clean/infected/error paths separately.');
            $check('web.hsts', config('security.hsts.enabled') === true && (int) config('security.hsts.max_age') >= 15552000, 'Enable HSTS after validating HTTPS; includeSubDomains needs a separate domain inventory.');
            $check('db.tls', config('database.connections.pgsql.sslmode') === 'verify-full' && is_readable((string) config('database.connections.pgsql.sslrootcert')), 'Configure PostgreSQL verify-full and a readable CA certificate, or document a reviewed local Unix-socket alternative.');
        }
        if ($database) {
            try {
                $role = DB::selectOne('SELECT rolsuper, rolcreaterole, rolcreatedb, rolbypassrls FROM pg_roles WHERE rolname = current_user');
                $owned = DB::selectOne("SELECT count(*) AS total FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace WHERE c.relowner = (SELECT oid FROM pg_roles WHERE rolname = current_user) AND n.nspname = 'public' AND c.relkind IN ('r','p')");
                $checks[] = ['id' => 'db.connection', 'status' => 'PASS', 'remediation' => 'Read-only role inspection succeeded.'];
                if ($production) {
                    $check('db.runtime_role', $role !== null && ! $role->rolsuper && ! $role->rolcreaterole && ! $role->rolcreatedb && ! $role->rolbypassrls && (int) $owned->total === 0, 'Use a non-owner runtime role without superuser, role/database creation or RLS bypass; migrations use a separate identity.');
                }
            } catch (Throwable) {
                $checks[] = ['id' => 'db.connection', 'status' => 'FAIL', 'remediation' => 'Read-only inspection failed; inspect connectivity privately.'];
            }
        }

        return ['schema_version' => 1, 'scope' => 'application_configuration', 'production' => $production,
            'checked_at' => now()->utc()->toIso8601String(), 'checks' => $checks,
            'external_evidence' => ['TLS/origin reachability', 'scanner signatures and isolation', 'backup restore and RTO/RPO', 'alert delivery', 'deployment identity and access reviews', 'independent penetration test'],
            'passed' => ! in_array('FAIL', array_column($checks, 'status'), true)];
    }
}
