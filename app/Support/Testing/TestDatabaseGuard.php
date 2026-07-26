<?php

namespace App\Support\Testing;

use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class TestDatabaseGuard
{
    public const REQUIRED_CONNECTION = 'pgsql';

    public const REQUIRED_DATABASE = 'rentfleet_test';

    public const ACCEPTANCE_DATABASE = 'rentfleet_06g_acceptance';

    public const ACCEPTANCE_MODE_VARIABLE = 'RENTFLEET_ACCEPTANCE_MODE';

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
                self::ACCEPTANCE_MODE_VARIABLE => self::environmentValue(self::ACCEPTANCE_MODE_VARIABLE),
            ],
            self::resolvedDatabaseName(),
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
        ?string $resolvedDatabase = null,
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

        $databaseNames = array_values(array_unique($databaseNames));
        if (count($databaseNames) !== 1) {
            throw self::unsafeConfiguration();
        }

        $database = $databaseNames[0];
        $acceptanceMode = self::nonEmptyString($environment[self::ACCEPTANCE_MODE_VARIABLE] ?? null);
        $normalTestDatabase = $database === self::REQUIRED_DATABASE;
        $explicitAcceptanceDatabase = $database === self::ACCEPTANCE_DATABASE
            && $acceptanceMode === '1'
            && $resolvedDatabase === self::ACCEPTANCE_DATABASE;

        if (! $normalTestDatabase && ! $explicitAcceptanceDatabase) {
            throw self::unsafeConfiguration();
        }

        if ($resolvedDatabase !== null && $resolvedDatabase !== $database) {
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

    private static function resolvedDatabaseName(): string
    {
        $result = DB::selectOne('select current_database() as database_name');
        $database = self::nonEmptyString($result?->database_name ?? null);

        if ($database === null) {
            throw self::unsafeConfiguration();
        }

        return $database;
    }

    private static function unsafeConfiguration(): RuntimeException
    {
        return new RuntimeException(
            'Exécution destructive refusée : les tests exigent APP_ENV=testing, '
            .'DB_CONNECTION=pgsql et la base exacte rentfleet_test. La cible 06G '
            .'exige en plus son nom exact, son mode explicite et sa résolution PostgreSQL.'
        );
    }
}
