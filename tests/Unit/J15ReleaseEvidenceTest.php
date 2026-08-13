<?php

namespace Tests\Unit;

use App\Support\Intelligence\J15\J15ReleaseEvidenceBuilder;
use InvalidArgumentException;
use JsonException;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

class J15ReleaseEvidenceTest extends TestCase
{
    /** @var list<string> */
    private array $temporaryDirectories = [];

    protected function tearDown(): void
    {
        foreach ($this->temporaryDirectories as $directory) {
            if (! is_dir($directory)) {
                continue;
            }

            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS),
                RecursiveIteratorIterator::CHILD_FIRST,
            );
            foreach ($iterator as $item) {
                if ($item->isDir()) {
                    rmdir($item->getPathname());
                } else {
                    unlink($item->getPathname());
                }
            }
            rmdir($directory);
        }

        parent::tearDown();
    }

    /** @throws JsonException */
    public function test_manifest_is_byte_deterministic_and_bundle_checksums_are_verifiable(): void
    {
        [$junit, $doctor] = $this->successfulReports();
        $first = $this->temporaryDirectory();
        $second = $this->temporaryDirectory();
        $builder = new J15ReleaseEvidenceBuilder($this->repositoryRoot());
        $sha = str_repeat('a', 40);

        $builder->generate($sha, 'getibplay-cmyk/pfe', $junit, $doctor, $first, [
            'provider' => 'github_actions',
            'workflow' => 'CI',
            'run_id' => '100',
            'run_attempt' => 1,
            'event_name' => 'pull_request',
            'runner_os' => 'Linux',
            'runner_arch' => 'X64',
        ]);
        $builder->generate($sha, 'getibplay-cmyk/pfe', $junit, $doctor, $second, [
            'provider' => 'github_actions',
            'workflow' => 'CI',
            'run_id' => '200',
            'run_attempt' => 2,
            'event_name' => 'push',
            'runner_os' => 'Linux',
            'runner_arch' => 'X64',
        ]);

        $firstManifest = (string) file_get_contents($first.'/j15-release-manifest.json');
        $secondManifest = (string) file_get_contents($second.'/j15-release-manifest.json');
        $this->assertSame($firstManifest, $secondManifest);
        $this->assertNotSame(
            file_get_contents($first.'/j15-ci-run.json'),
            file_get_contents($second.'/j15-ci-run.json'),
        );

        $manifest = json_decode($firstManifest, true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('1.0.0', $manifest['schema_version']);
        $this->assertSame('software_integration_proof', $manifest['evidence_class']);
        $this->assertSame($sha, $manifest['subject']['source_commit']);
        $this->assertSame('passed', $manifest['quality_gate']['test_suite']['status']);
        $this->assertSame(3, $manifest['quality_gate']['test_suite']['tests']);
        $this->assertSame(8, $manifest['quality_gate']['test_suite']['assertions']);
        $this->assertSame(0, $manifest['quality_gate']['test_suite']['errors']);
        $this->assertSame(0, $manifest['quality_gate']['test_suite']['failures']);
        $this->assertSame(1, $manifest['quality_gate']['test_suite']['skipped']);
        $this->assertSame('ok', $manifest['quality_gate']['doctor']['status']);
        $this->assertSame(3, $manifest['quality_gate']['doctor']['checks']);
        $this->assertSame(2, $manifest['quality_gate']['doctor']['pass']);
        $this->assertSame(1, $manifest['quality_gate']['doctor']['warn']);
        $this->assertSame(0, $manifest['quality_gate']['doctor']['fail']);
        $this->assertFalse($manifest['safety']['scientific_experiments_rerun']);
        $this->assertFalse($manifest['safety']['drive_modified']);
        $this->assertFalse($manifest['safety']['colab_modified']);
        $this->assertFalse($manifest['safety']['ready_for_saas']);
        $this->assertFalse($manifest['safety']['production_allowed']);
        $this->assertFalse($manifest['safety']['automatic_action_allowed']);
        $this->assertSame('NO_OPERATIONAL_ACTION', $manifest['safety']['decision_effect']);

        $paths = array_column($manifest['materials'], 'path');
        $sortedPaths = $paths;
        sort($sortedPaths, SORT_STRING);
        $this->assertSame($sortedPaths, $paths);
        $this->assertContains('.github/workflows/ci.yml', $paths);
        $this->assertContains('composer.lock', $paths);
        $this->assertContains('package-lock.json', $paths);
        $this->assertContains('docs/intelligence/j12-scientific-evidence-manifest.json', $paths);
        $this->assertContains('docs/intelligence/schemas/j15-release-evidence-v1.0.0.json', $paths);
        $this->assertContains('public/build/manifest.json', $paths);

        foreach (file($first.'/SHA256SUMS', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $this->assertMatchesRegularExpression('/\A[0-9a-f]{64}  [A-Za-z0-9.-]+\z/', $line);
            [$digest, $filename] = explode('  ', $line, 2);
            $this->assertSame($digest, hash_file('sha256', $first.'/'.$filename));
        }
        $this->assertCount(2, file($first.'/SHA256SUMS', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES));
    }

    public function test_builder_refuses_an_abbreviated_commit_before_writing_a_bundle(): void
    {
        [$junit, $doctor] = $this->successfulReports();
        $output = $this->temporaryDirectory();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('SHA Git complet');

        (new J15ReleaseEvidenceBuilder($this->repositoryRoot()))
            ->generate('59d654d', 'getibplay-cmyk/pfe', $junit, $doctor, $output);
    }

    public function test_builder_refuses_failed_tests_and_failed_doctor_reports(): void
    {
        [$junit, $doctor] = $this->successfulReports();
        $failedJUnit = $this->temporaryDirectory().'/failed.xml';
        file_put_contents($failedJUnit, '<testsuite tests="1" assertions="1" errors="0" failures="1" skipped="0"/>');
        $builder = new J15ReleaseEvidenceBuilder($this->repositoryRoot());

        try {
            $builder->generate(str_repeat('b', 40), 'getibplay-cmyk/pfe', $failedJUnit, $doctor, $this->temporaryDirectory());
            $this->fail('Le rapport JUnit en échec aurait dû être refusé.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('tests en échec', $exception->getMessage());
        }

        $failedDoctor = $this->temporaryDirectory().'/doctor.json';
        file_put_contents($failedDoctor, json_encode([
            'status' => 'error',
            'checks' => [['name' => 'Migrations', 'status' => 'fail', 'detail' => '1 en attente']],
        ], JSON_THROW_ON_ERROR));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('état ok');
        $builder->generate(str_repeat('b', 40), 'getibplay-cmyk/pfe', $junit, $failedDoctor, $this->temporaryDirectory());
    }

    /** @throws JsonException */
    public function test_schema_and_workflow_freeze_the_safety_and_attestation_boundaries(): void
    {
        $schema = json_decode((string) file_get_contents($this->repositoryRoot().'/docs/intelligence/schemas/j15-release-evidence-v1.0.0.json'), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('https://json-schema.org/draft/2020-12/schema', $schema['$schema']);
        $this->assertFalse($schema['additionalProperties']);
        $this->assertSame('software_integration_proof', $schema['properties']['evidence_class']['const']);
        $this->assertSame('J15-A', $schema['properties']['scope']['properties']['stage']['const']);
        $this->assertTrue($schema['properties']['reproducibility']['properties']['volatile_run_metadata_excluded']['const']);
        $this->assertSame('SHA256SUMS', $schema['properties']['attestation']['properties']['subject_checksums_file']['const']);

        foreach ([
            'scientific_experiments_rerun',
            'drive_modified',
            'colab_modified',
            'contains_new_model',
            'inference_allowed',
            'training_allowed',
            'solver_allowed',
            'rentfleet_local_validation',
            'production_evidence',
            'ready_for_saas',
            'production_allowed',
            'automatic_action_allowed',
            'operational_business_write_allowed',
        ] as $closedBoundary) {
            $this->assertFalse($schema['properties']['safety']['properties'][$closedBoundary]['const'], $closedBoundary);
        }

        $workflow = (string) file_get_contents($this->repositoryRoot().'/.github/workflows/ci.yml');
        $this->assertStringContainsString('attest-release-evidence:', $workflow);
        $this->assertStringContainsString("github.event_name == 'push' && github.ref == 'refs/heads/main'", $workflow);
        $this->assertStringContainsString('id-token: write', $workflow);
        $this->assertStringContainsString('attestations: write', $workflow);
        $this->assertStringNotContainsString('artifact-metadata: write', $workflow);
        $this->assertStringContainsString('subject-checksums:', $workflow);
        $this->assertStringNotContainsString('pull_request_target:', $workflow);

        preg_match_all('/uses:\s+[^@\s]+@([^\s]+)/', $workflow, $matches);
        $this->assertNotEmpty($matches[1]);
        foreach ($matches[1] as $reference) {
            $this->assertMatchesRegularExpression('/\A[0-9a-f]{40}\z/', $reference, $reference);
        }
    }

    /** @return array{string, string} */
    private function successfulReports(): array
    {
        $directory = $this->temporaryDirectory();
        $junit = $directory.'/junit.xml';
        $doctor = $directory.'/doctor.json';
        file_put_contents($junit, <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<testsuites>
  <testsuite name="Unit" tests="2" assertions="5" errors="0" failures="0" skipped="0"/>
  <testsuite name="Feature" tests="1" assertions="3" errors="0" failures="0" skipped="1"/>
</testsuites>
XML);
        file_put_contents($doctor, json_encode([
            'status' => 'ok',
            'checks' => [
                ['name' => 'PHP', 'status' => 'pass', 'detail' => '8.5.8'],
                ['name' => 'PostgreSQL', 'status' => 'pass', 'detail' => '18.4'],
                ['name' => 'Queue', 'status' => 'warn', 'detail' => 'sync'],
            ],
        ], JSON_THROW_ON_ERROR));

        return [$junit, $doctor];
    }

    private function repositoryRoot(): string
    {
        return dirname(__DIR__, 2);
    }

    private function temporaryDirectory(): string
    {
        $directory = sys_get_temp_dir().'/rentfleet-j15-'.bin2hex(random_bytes(8));
        $this->assertTrue(mkdir($directory, 0700));
        $this->temporaryDirectories[] = $directory;

        return $directory;
    }
}
