<?php

namespace App\Support\Testing;

use Illuminate\Foundation\Application;
use RuntimeException;

final class TestDatabaseGuard
{
    public const REQUIRED_CONNECTION = 'pgsql';

    public const REQUIRED_DATABASE = 'rentfleet_test';

    /** @var list<string> */
    public const DESTRUCTIVE_COMMANDS = [
        'db:wipe',
        'migrate:fresh',
        'migrate:refresh',
        'migrate:reset',
    ];

    public static function assertSafe(Application $app): void
    {
        $connectionName = (string) config('database.default');
        $connection = config("database.connections.{$connectionName}", []);

        self::assertSafeConfiguration(
            $app->environment(),
            $connectionName,
            is_array($connection) ? $connection : [],
            [
                'DB_CONNECTION' => self::environmentValue('DB_CONNECTION'),
                'DB_DATABASE' => self::environmentValue('DB_DATABASE'),
                'DB_URL' => self::environmentValue('DB_URL'),
                'DATABASE_URL' => self::environmentValue('DATABASE_URL'),
            ],
        );
    }

    /**
     * @param  array<string, mixed>  $connection
     * @param  array<string, mixed>  $environment
     */
    public static function assertSafeConfiguration(
        string $appEnvironment,
        string $connectionName,
        array $connection,
        array $environment = [],
    ): void {
        $configuredDriver = (string) ($connection['driver'] ?? '');
        $environmentDriver = self::nonEmptyString($environment['DB_CONNECTION'] ?? null);

        if ($appEnvironment !== 'testing'
            || $connectionName !== self::REQUIRED_CONNECTION
            || $configuredDriver !== self::REQUIRED_CONNECTION
            || ($environmentDriver !== null && $environmentDriver !== self::REQUIRED_CONNECTION)) {
            throw self::unsafeConfiguration();
        }

        $databaseNames = array_values(array_filter([
            self::nonEmptyString($connection['database'] ?? null),
            self::nonEmptyString($environment['DB_DATABASE'] ?? null),
            self::databaseFromUrl(self::nonEmptyString($connection['url'] ?? null)),
            self::databaseFromUrl(self::nonEmptyString($environment['DB_URL'] ?? null)),
            self::databaseFromUrl(self::nonEmptyString($environment['DATABASE_URL'] ?? null)),
        ], fn (?string $value): bool => $value !== null));

        if ($databaseNames === []
            || collect($databaseNames)->contains(fn (string $database): bool => $database !== self::REQUIRED_DATABASE)) {
            throw self::unsafeConfiguration();
        }

        foreach ([
            self::nonEmptyString($connection['url'] ?? null),
            self::nonEmptyString($environment['DB_URL'] ?? null),
            self::nonEmptyString($environment['DATABASE_URL'] ?? null),
        ] as $url) {
            if ($url !== null && ! self::isPostgreSqlUrl($url)) {
                throw self::unsafeConfiguration();
            }
        }
    }

    public static function protects(string $command): bool
    {
        return in_array($command, self::DESTRUCTIVE_COMMANDS, true);
    }

    private static function environmentValue(string $key): ?string
    {
        $value = env($key);

        return self::nonEmptyString($value);
    }

    private static function nonEmptyString(mixed $value): ?string
    {
        if (! is_string($value) && ! is_numeric($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private static function databaseFromUrl(?string $url): ?string
    {
        if ($url === null) {
            return null;
        }

        $parts = parse_url($url);
        if (! is_array($parts)) {
            throw self::unsafeConfiguration();
        }

        $database = trim(rawurldecode((string) ($parts['path'] ?? '')), '/');

        return $database === '' ? null : $database;
    }

    private static function isPostgreSqlUrl(string $url): bool
    {
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));

        return in_array($scheme, ['pgsql', 'postgres', 'postgresql'], true);
    }

    private static function unsafeConfiguration(): RuntimeException
    {
        return new RuntimeException(
            'Exécution destructive refusée : les tests exigent APP_ENV=testing, '
            .'DB_CONNECTION=pgsql et la base exacte rentfleet_test.'
        );
    }
}
