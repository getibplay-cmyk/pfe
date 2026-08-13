<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use JsonException;
use Tests\TestCase;

class J15ReleaseEvidenceCommandTest extends TestCase
{
    /** @throws JsonException */
    public function test_command_generates_a_non_operational_release_evidence_bundle(): void
    {
        $root = storage_path('framework/testing/j15-'.bin2hex(random_bytes(8)));
        $junit = $root.'/junit.xml';
        $doctor = $root.'/doctor.json';
        $output = $root.'/bundle';
        File::ensureDirectoryExists($root);

        try {
            File::put($junit, '<testsuite name="RentFleet" tests="2" assertions="7" errors="0" failures="0" skipped="0"/>');
            File::put($doctor, json_encode([
                'status' => 'ok',
                'checks' => [
                    ['name' => 'PHP', 'status' => 'pass', 'detail' => PHP_VERSION],
                    ['name' => 'Queue', 'status' => 'warn', 'detail' => 'sync'],
                ],
            ], JSON_THROW_ON_ERROR));

            $this->artisan('intelligence:j15-release-evidence', [
                '--source-commit' => str_repeat('c', 40),
                '--repository' => 'getibplay-cmyk/pfe',
                '--junit' => $junit,
                '--doctor' => $doctor,
                '--output' => $output,
            ])
                ->expectsOutputToContain('"status": "ok"')
                ->assertSuccessful();

            $this->assertFileExists($output.'/j15-release-manifest.json');
            $this->assertFileExists($output.'/j15-ci-run.json');
            $this->assertFileExists($output.'/SHA256SUMS');

            $manifest = json_decode(File::get($output.'/j15-release-manifest.json'), true, 512, JSON_THROW_ON_ERROR);
            $this->assertSame('J15-A', $manifest['scope']['stage']);
            $this->assertSame('passed', $manifest['quality_gate']['status']);
            $this->assertFalse($manifest['safety']['drive_modified']);
            $this->assertFalse($manifest['safety']['colab_modified']);
            $this->assertFalse($manifest['safety']['operational_business_write_allowed']);
            $this->assertTrue($manifest['safety']['human_decision_required']);
        } finally {
            File::deleteDirectory($root);
        }
    }
}
