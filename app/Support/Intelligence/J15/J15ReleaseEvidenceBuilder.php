<?php

namespace App\Support\Intelligence\J15;

use DOMDocument;
use DOMElement;
use DOMXPath;
use InvalidArgumentException;
use JsonException;
use RuntimeException;

final class J15ReleaseEvidenceBuilder
{
    private const MANIFEST_FILE = 'j15-release-manifest.json';

    private const RUN_FILE = 'j15-ci-run.json';

    private const CHECKSUMS_FILE = 'SHA256SUMS';

    /** @var array<string, string> */
    private const QUALITY_CHECKS = [
        'composer_validate' => 'composer validate --strict --no-interaction',
        'composer_audit' => 'composer audit --locked --no-interaction',
        'npm_audit' => 'npm audit',
        'frontend_build' => 'npm run build',
        'pint' => 'vendor/bin/pint --test',
        'migrations' => 'php artisan migrate --force --no-interaction',
        'phpunit' => 'php artisan test --log-junit <junit-path>',
        'doctor' => 'php artisan rentfleet:doctor --json --env=testing --expect-database=rentfleet_test',
        'git_diff' => 'git diff --check',
    ];

    /** @var list<string> */
    private const MATERIAL_PATHS = [
        '.github/workflows/ci.yml',
        'composer.json',
        'composer.lock',
        'docs/intelligence/j12-scientific-evidence-manifest.json',
        'package.json',
        'package-lock.json',
        'public/build/manifest.json',
    ];

    /** @var list<string> */
    private const MATERIAL_GLOBS = [
        'docs/intelligence/schemas/*.json',
        'resources/intelligence/j11/fixtures/*.json',
        'resources/intelligence/j11/schemas/*.json',
    ];

    private readonly string $repositoryRoot;

    public function __construct(string $repositoryRoot, private readonly J15CanonicalJson $canonicalJson = new J15CanonicalJson)
    {
        $normalized = rtrim(str_replace('\\', '/', $repositoryRoot), '/');

        if ($normalized === '' || ! is_dir($normalized)) {
            throw new InvalidArgumentException('La racine du dépôt J15 est invalide.');
        }

        $this->repositoryRoot = $normalized;
    }

    /**
     * @param  array<string, bool|int|string|null>  $runContext
     * @return array{manifest_path: string, manifest_sha256: string, run_path: string, checksums_path: string}
     *
     * @throws JsonException
     */
    public function generate(
        string $sourceCommit,
        string $repository,
        string $junitPath,
        string $doctorPath,
        string $outputDirectory,
        array $runContext = [],
    ): array {
        $this->assertSource($sourceCommit, $repository);
        $tests = $this->readJUnit($junitPath);
        $doctor = $this->readDoctor($doctorPath);
        $materials = $this->materials();

        $manifest = $this->canonicalJson->encode($this->manifest(
            sourceCommit: strtolower($sourceCommit),
            repository: $repository,
            materials: $materials,
            tests: $tests,
            doctor: $doctor,
        ));
        $manifestSha256 = hash('sha256', $manifest);
        $run = $this->canonicalJson->encode($this->runEnvelope(
            sourceCommit: strtolower($sourceCommit),
            repository: $repository,
            manifestSha256: $manifestSha256,
            context: $runContext,
        ));

        $outputDirectory = rtrim($outputDirectory, '/\\');
        if ($outputDirectory === '') {
            throw new InvalidArgumentException('Le dossier de sortie J15 est obligatoire.');
        }
        if (! is_dir($outputDirectory) && ! mkdir($outputDirectory, 0755, true) && ! is_dir($outputDirectory)) {
            throw new RuntimeException('Le dossier de sortie J15 ne peut pas être créé.');
        }
        if (! is_writable($outputDirectory)) {
            throw new RuntimeException('Le dossier de sortie J15 n’est pas accessible en écriture.');
        }

        $manifestPath = $outputDirectory.DIRECTORY_SEPARATOR.self::MANIFEST_FILE;
        $runPath = $outputDirectory.DIRECTORY_SEPARATOR.self::RUN_FILE;
        $checksumsPath = $outputDirectory.DIRECTORY_SEPARATOR.self::CHECKSUMS_FILE;
        $checksums = implode("\n", [
            hash('sha256', $run).'  '.self::RUN_FILE,
            $manifestSha256.'  '.self::MANIFEST_FILE,
        ])."\n";

        $this->write($manifestPath, $manifest);
        $this->write($runPath, $run);
        $this->write($checksumsPath, $checksums);

        return [
            'manifest_path' => $manifestPath,
            'manifest_sha256' => $manifestSha256,
            'run_path' => $runPath,
            'checksums_path' => $checksumsPath,
        ];
    }

    private function assertSource(string $sourceCommit, string $repository): void
    {
        if (preg_match('/\A[0-9a-f]{40}\z/i', $sourceCommit) !== 1) {
            throw new InvalidArgumentException('Le commit source J15 doit être un SHA Git complet de 40 caractères.');
        }

        if (preg_match('/\A[A-Za-z0-9_.-]+\/[A-Za-z0-9_.-]+\z/', $repository) !== 1) {
            throw new InvalidArgumentException('Le dépôt J15 doit utiliser la forme propriétaire/nom.');
        }
    }

    /** @return array{tests: int, assertions: int, errors: int, failures: int, skipped: int} */
    private function readJUnit(string $path): array
    {
        $xml = $this->read($path, 'Le rapport JUnit J15 est introuvable.');
        if (stripos($xml, '<!DOCTYPE') !== false) {
            throw new InvalidArgumentException('Le rapport JUnit J15 ne doit pas contenir de DOCTYPE.');
        }

        $previous = libxml_use_internal_errors(true);
        $document = new DOMDocument;
        $loaded = $document->loadXML($xml, LIBXML_NONET | LIBXML_COMPACT);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (! $loaded || ! $document->documentElement instanceof DOMElement) {
            throw new InvalidArgumentException('Le rapport JUnit J15 est invalide.');
        }

        $xpath = new DOMXPath($document);
        $nodes = $document->documentElement->tagName === 'testsuite'
            ? [$document->documentElement]
            : iterator_to_array($xpath->query('/testsuites/testsuite') ?: []);

        if ($nodes === []) {
            throw new InvalidArgumentException('Le rapport JUnit J15 ne contient aucune suite agrégée.');
        }

        $totals = ['tests' => 0, 'assertions' => 0, 'errors' => 0, 'failures' => 0, 'skipped' => 0];
        foreach ($nodes as $node) {
            if (! $node instanceof DOMElement) {
                continue;
            }
            foreach (array_keys($totals) as $attribute) {
                $value = $node->getAttribute($attribute);
                if ($value === '' || preg_match('/\A\d+\z/', $value) !== 1) {
                    throw new InvalidArgumentException("Le rapport JUnit J15 ne fournit pas l’attribut {$attribute}.");
                }
                $totals[$attribute] += (int) $value;
            }
        }

        if ($totals['tests'] < 1 || $totals['assertions'] < 1) {
            throw new InvalidArgumentException('Le rapport JUnit J15 ne prouve aucun test avec assertion.');
        }
        if ($totals['errors'] !== 0 || $totals['failures'] !== 0) {
            throw new InvalidArgumentException('Le rapport JUnit J15 contient des tests en échec.');
        }

        return $totals;
    }

    /** @return array{status: string, checks: int, pass: int, warn: int, fail: int} */
    private function readDoctor(string $path): array
    {
        $payload = json_decode($this->read($path, 'Le rapport Doctor J15 est introuvable.'), true, 512, JSON_THROW_ON_ERROR);
        if (! is_array($payload) || ($payload['status'] ?? null) !== 'ok' || ! is_array($payload['checks'] ?? null)) {
            throw new InvalidArgumentException('Le rapport Doctor J15 ne confirme pas un état ok.');
        }

        $statuses = ['pass' => 0, 'warn' => 0, 'fail' => 0];
        foreach ($payload['checks'] as $check) {
            $status = is_array($check) ? ($check['status'] ?? null) : null;
            if (! is_string($status) || ! array_key_exists($status, $statuses)) {
                throw new InvalidArgumentException('Le rapport Doctor J15 contient un statut inconnu.');
            }
            $statuses[$status]++;
        }

        $count = array_sum($statuses);
        if ($count < 1 || $statuses['fail'] !== 0) {
            throw new InvalidArgumentException('Le rapport Doctor J15 ne prouve pas un diagnostic réussi.');
        }

        return [
            'status' => 'ok',
            'checks' => $count,
            'pass' => $statuses['pass'],
            'warn' => $statuses['warn'],
            'fail' => $statuses['fail'],
        ];
    }

    /** @return list<array{path: string, sha256: string}> */
    private function materials(): array
    {
        $paths = array_fill_keys(self::MATERIAL_PATHS, true);

        foreach (self::MATERIAL_GLOBS as $pattern) {
            $matches = glob($this->absolutePath($pattern), GLOB_NOSORT);
            if ($matches === false || $matches === []) {
                throw new RuntimeException("Aucun matériau J15 ne correspond à {$pattern}.");
            }
            foreach ($matches as $match) {
                $normalized = str_replace('\\', '/', $match);
                $prefix = $this->repositoryRoot.'/';
                if (! str_starts_with($normalized, $prefix)) {
                    throw new RuntimeException('Un matériau J15 sort de la racine du dépôt.');
                }
                $paths[substr($normalized, strlen($prefix))] = true;
            }
        }

        ksort($paths, SORT_STRING);
        $materials = [];
        foreach (array_keys($paths) as $path) {
            $absolute = $this->absolutePath($path);
            $digest = is_file($absolute) ? hash_file('sha256', $absolute) : false;
            if (! is_string($digest)) {
                throw new RuntimeException("Le matériau J15 est absent ou illisible : {$path}.");
            }
            $materials[] = ['path' => $path, 'sha256' => $digest];
        }

        return $materials;
    }

    /**
     * @param  list<array{path: string, sha256: string}>  $materials
     * @param  array{tests: int, assertions: int, errors: int, failures: int, skipped: int}  $tests
     * @param  array{status: string, checks: int, pass: int, warn: int, fail: int}  $doctor
     * @return array<string, mixed>
     */
    private function manifest(string $sourceCommit, string $repository, array $materials, array $tests, array $doctor): array
    {
        $checks = [];
        foreach (self::QUALITY_CHECKS as $id => $command) {
            $checks[] = ['id' => $id, 'command' => $command, 'status' => 'passed'];
        }

        return [
            'schema_version' => '1.0.0',
            'evidence_id' => 'rentfleet-j15-a-reproducibility-release-evidence',
            'evidence_class' => 'software_integration_proof',
            'subject' => [
                'repository' => $repository,
                'source_commit' => $sourceCommit,
                'workflow_path' => '.github/workflows/ci.yml',
            ],
            'scope' => [
                'stage' => 'J15-A',
                'claim_allowed' => 'The repository CI quality gates passed for this exact source commit and the listed materials were hashed into a reproducible software-evidence manifest.',
                'claims_forbidden' => [
                    'scientific experiments were rerun',
                    'RentFleet local model validity',
                    'production or SaaS readiness',
                    'authorization for automatic or operational action',
                ],
            ],
            'safety' => [
                'scientific_experiments_rerun' => false,
                'drive_modified' => false,
                'colab_modified' => false,
                'contains_new_model' => false,
                'inference_allowed' => false,
                'training_allowed' => false,
                'solver_allowed' => false,
                'rentfleet_local_validation' => false,
                'production_evidence' => false,
                'ready_for_saas' => false,
                'production_allowed' => false,
                'automatic_action_allowed' => false,
                'operational_business_write_allowed' => false,
                'human_decision_required' => true,
                'decision_effect' => 'NO_OPERATIONAL_ACTION',
            ],
            'materials' => $materials,
            'quality_gate' => [
                'status' => 'passed',
                'checks' => $checks,
                'test_suite' => ['status' => 'passed', ...$tests],
                'doctor' => $doctor,
            ],
            'reproducibility' => [
                'canonical_json' => 'UTF-8 JSON; object keys sorted recursively; array order preserved; one trailing LF',
                'digest_algorithm' => 'sha256',
                'volatile_run_metadata_excluded' => true,
                'same_inputs_same_manifest' => true,
            ],
            'attestation' => [
                'provider' => 'github_actions',
                'predicate' => 'slsa_provenance',
                'subject_checksums_file' => self::CHECKSUMS_FILE,
                'pull_request_policy' => 'unsigned_downloadable_bundle',
                'main_policy' => 'sigstore_attestation_after_protected_push',
            ],
        ];
    }

    /**
     * @param  array<string, bool|int|string|null>  $context
     * @return array<string, mixed>
     */
    private function runEnvelope(string $sourceCommit, string $repository, string $manifestSha256, array $context): array
    {
        return [
            'schema_version' => '1.0.0',
            'manifest' => [
                'path' => self::MANIFEST_FILE,
                'sha256' => $manifestSha256,
            ],
            'ci' => [
                'provider' => $this->contextString($context, 'provider', 'local'),
                'repository' => $repository,
                'workflow' => $this->contextNullableString($context, 'workflow'),
                'run_id' => $this->contextNullableString($context, 'run_id'),
                'run_attempt' => $this->contextNullableInteger($context, 'run_attempt'),
                'event_name' => $this->contextNullableString($context, 'event_name'),
                'source_commit' => $sourceCommit,
            ],
            'runner' => [
                'os' => $this->contextNullableString($context, 'runner_os'),
                'arch' => $this->contextNullableString($context, 'runner_arch'),
                'php_version' => PHP_VERSION,
            ],
            'external_systems' => [
                'drive_modified' => false,
                'colab_modified' => false,
            ],
        ];
    }

    /** @param array<string, bool|int|string|null> $context */
    private function contextString(array $context, string $key, string $default): string
    {
        $value = $context[$key] ?? $default;

        return is_string($value) && $value !== '' ? $value : $default;
    }

    /** @param array<string, bool|int|string|null> $context */
    private function contextNullableString(array $context, string $key): ?string
    {
        $value = $context[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    /** @param array<string, bool|int|string|null> $context */
    private function contextNullableInteger(array $context, string $key): ?int
    {
        $value = $context[$key] ?? null;

        return is_int($value) && $value > 0 ? $value : null;
    }

    private function absolutePath(string $relativePath): string
    {
        return $this->repositoryRoot.'/'.ltrim(str_replace('\\', '/', $relativePath), '/');
    }

    private function read(string $path, string $missingMessage): string
    {
        $contents = is_file($path) ? file_get_contents($path) : false;
        if (! is_string($contents)) {
            throw new InvalidArgumentException($missingMessage);
        }

        return $contents;
    }

    private function write(string $path, string $contents): void
    {
        $written = file_put_contents($path, $contents, LOCK_EX);
        if ($written !== strlen($contents)) {
            throw new RuntimeException('Le bundle J15 n’a pas pu être écrit complètement.');
        }
    }
}
