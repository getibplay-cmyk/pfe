<?php

namespace Tests\Unit;

use App\Support\Security\RestrictedProcessEnvironment;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

class RestrictedProcessEnvironmentTest extends TestCase
{
    public function test_new_unlisted_credentials_are_not_inherited_by_a_real_child_process(): void
    {
        $name = 'RENTFLEET_TEST_FUTURE_PROVIDER_CREDENTIAL';
        $previous = getenv($name);
        putenv($name.'=synthetic-secret-never-forward');
        $_ENV[$name] = 'synthetic-secret-never-forward';
        try {
            $process = new Process(['/usr/bin/env'], null, RestrictedProcessEnvironment::make(), null, 5);
            if (PHP_OS_FAMILY === 'Windows') {
                $this->assertFalse(RestrictedProcessEnvironment::make()[$name]);

                return;
            }
            $process->mustRun();
            $this->assertStringNotContainsString($name, $process->getOutput());
            $this->assertStringNotContainsString('synthetic-secret', $process->getOutput());
            $this->assertStringContainsString('PYTHONNOUSERSITE=1', $process->getOutput());
        } finally {
            putenv($previous === false ? $name : $name.'='.$previous);
            unset($_ENV[$name]);
        }
    }

    public function test_an_unapproved_override_cannot_reintroduce_a_secret(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        RestrictedProcessEnvironment::make(['DB_PASSWORD' => 'synthetic']);
    }
}
