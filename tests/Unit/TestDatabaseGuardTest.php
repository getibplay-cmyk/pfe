<?php

namespace Tests\Unit;

use App\Support\Testing\TestDatabaseGuard;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class TestDatabaseGuardTest extends TestCase
{
    public function test_exact_testing_postgresql_database_is_authorized(): void
    {
        TestDatabaseGuard::assertSafeConfiguration(
            'testing',
            'pgsql',
            $this->connection(),
            ['DB_CONNECTION' => 'pgsql', 'DB_DATABASE' => 'rentfleet_test'],
        );

        $this->addToAssertionCount(1);
    }

    #[DataProvider('unsafeConfigurations')]
    public function test_every_other_database_or_environment_is_refused(
        string $environment,
        string $connectionName,
        array $connection,
        array $variables,
    ): void {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Exécution destructive refusée');

        TestDatabaseGuard::assertSafeConfiguration(
            $environment,
            $connectionName,
            $connection,
            $variables,
        );
    }

    public function test_database_url_is_checked_without_exposing_its_credentials(): void
    {
        $credential = bin2hex(random_bytes(16));
        $url = "postgresql://tester:{$credential}@127.0.0.1:5432/rentfleet";

        try {
            TestDatabaseGuard::assertSafeConfiguration(
                'testing',
                'pgsql',
                $this->connection(),
                ['DATABASE_URL' => $url],
            );
            $this->fail('La garde aurait dû refuser cette URL.');
        } catch (RuntimeException $exception) {
            $this->assertStringNotContainsString($credential, $exception->getMessage());
            $this->assertStringNotContainsString($url, $exception->getMessage());
        }
    }

    public function test_destructive_command_list_is_explicit(): void
    {
        foreach (['migrate:fresh', 'migrate:refresh', 'migrate:reset', 'db:wipe'] as $command) {
            $this->assertTrue(TestDatabaseGuard::protects($command));
        }

        $this->assertFalse(TestDatabaseGuard::protects('migrate'));
        $this->assertFalse(TestDatabaseGuard::protects('test'));
    }

    public static function unsafeConfigurations(): array
    {
        $connection = [
            'driver' => 'pgsql',
            'database' => 'rentfleet_test',
            'url' => null,
        ];

        return [
            'base rentfleet' => [
                'testing', 'pgsql', [...$connection, 'database' => 'rentfleet'], [],
            ],
            'base de restauration' => [
                'testing', 'pgsql', [...$connection, 'database' => 'rentfleet_restore_test'], [],
            ],
            'base postgres' => [
                'testing', 'pgsql', [...$connection, 'database' => 'postgres'], [],
            ],
            'nom vide' => [
                'testing', 'pgsql', [...$connection, 'database' => ''], [],
            ],
            'URL DB vers rentfleet' => [
                'testing', 'pgsql', $connection,
                ['DB_URL' => 'postgresql://127.0.0.1:5432/rentfleet'],
            ],
            'DATABASE_URL vers rentfleet' => [
                'testing', 'pgsql', $connection,
                ['DATABASE_URL' => 'postgresql://127.0.0.1:5432/rentfleet'],
            ],
            'environnement local' => [
                'local', 'pgsql', $connection, [],
            ],
            'connexion mysql' => [
                'testing', 'mysql', ['driver' => 'mysql', 'database' => 'rentfleet_test'], [],
            ],
        ];
    }

    private function connection(): array
    {
        return [
            'driver' => 'pgsql',
            'database' => 'rentfleet_test',
            'url' => null,
        ];
    }
}
