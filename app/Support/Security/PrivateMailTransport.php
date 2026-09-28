<?php

namespace App\Support\Security;

use Illuminate\Support\ConfigurationUrlParser;
use RuntimeException;
use Throwable;

final class PrivateMailTransport
{
    public static function allows(array $mail, bool $testing = false, bool $requireTls = false): bool
    {
        $configuration = self::configuration($mail);
        $transport = $configuration['transport'] ?? null;
        if ($testing && $transport === 'array') {
            return true;
        }
        // A misleading mailer name or a failover to logs must not disclose bearer links.
        if ($transport !== 'smtp' || filter_var($mail['from']['address'] ?? '', FILTER_VALIDATE_EMAIL) === false) {
            return false;
        }
        if (! is_string($configuration['host'] ?? null) || trim($configuration['host']) === '') {
            return false;
        }
        $scheme = ($configuration['scheme'] ?? null) ?: ((int) ($configuration['port'] ?? 0) === 465 ? 'smtps' : 'smtp');
        if (! in_array($scheme, ['smtp', 'smtps'], true)) {
            return false;
        }
        foreach (['verify_peer', 'verify_peer_name'] as $key) {
            if ((isset($configuration[$key]) && filter_var($configuration[$key], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) !== true)
                || (isset($configuration['stream']['ssl'][$key]) && $configuration['stream']['ssl'][$key] !== true)) {
                return false;
            }
        }
        if ($requireTls && $scheme !== 'smtps'
            && filter_var($configuration['require_tls'] ?? false, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) !== true) {
            return false;
        }

        return true;
    }

    public static function hasBoundedTimeout(array $mail): bool
    {
        $timeout = self::configuration($mail)['timeout'] ?? null;

        return is_numeric($timeout) && (float) $timeout > 0 && (float) $timeout <= 15;
    }

    private static function configuration(array $mail): array
    {
        // Legacy global drivers override named mailers in Laravel; do not silently trust one.
        if (isset($mail['driver'])) {
            return [];
        }
        $name = $mail['default'] ?? null;
        $configuration = is_string($name) ? ($mail['mailers'][$name] ?? []) : [];
        if (! is_array($configuration)) {
            return [];
        }
        if (isset($configuration['url'])) {
            try {
                // Use the same parser and precedence as Laravel's MailManager, including driver overrides.
                $configuration = (new ConfigurationUrlParser)->parseConfiguration($configuration);
                $configuration['transport'] = $configuration['driver'] ?? null;
            } catch (Throwable) {
                return [];
            }
        }

        return $configuration;
    }

    public static function assertReady(): void
    {
        if (! self::allows(config('mail', []), testing: app()->environment('testing'), requireTls: app()->environment('production'))) {
            throw new RuntimeException('Private notification transport is unavailable.');
        }
    }
}
