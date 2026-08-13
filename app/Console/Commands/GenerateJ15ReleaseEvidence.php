<?php

namespace App\Console\Commands;

use App\Support\Intelligence\J15\J15ReleaseEvidenceBuilder;
use Illuminate\Console\Command;
use Throwable;

class GenerateJ15ReleaseEvidence extends Command
{
    protected $signature = 'intelligence:j15-release-evidence
                            {--source-commit= : SHA Git complet attesté}
                            {--repository= : Dépôt GitHub au format propriétaire/nom}
                            {--junit= : Rapport JUnit réussi}
                            {--doctor= : Rapport JSON rentfleet:doctor réussi}
                            {--output= : Dossier de sortie du bundle}';

    protected $description = 'Génère le manifeste déterministe et le bundle CI non opérationnel J15-A.';

    public function handle(): int
    {
        try {
            $builder = new J15ReleaseEvidenceBuilder(base_path());
            $result = $builder->generate(
                sourceCommit: (string) $this->option('source-commit'),
                repository: (string) $this->option('repository'),
                junitPath: $this->absolutePath((string) $this->option('junit')),
                doctorPath: $this->absolutePath((string) $this->option('doctor')),
                outputDirectory: $this->absolutePath((string) $this->option('output')),
                runContext: $this->runContext(),
            );

            $this->line(json_encode([
                'status' => 'ok',
                'evidence_class' => 'software_integration_proof',
                'manifest_path' => $result['manifest_path'],
                'manifest_sha256' => $result['manifest_sha256'],
                'checksums_path' => $result['checksums_path'],
                'drive_modified' => false,
                'colab_modified' => false,
            ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->error('J15-A refusé : '.$exception->getMessage());

            return self::FAILURE;
        }
    }

    private function absolutePath(string $path): string
    {
        if ($path === '') {
            return '';
        }

        if (str_starts_with($path, '/') || preg_match('/\A(?:[A-Za-z]:[\\\\\/]|\\\\\\\\)/', $path) === 1) {
            return $path;
        }

        return base_path($path);
    }

    /** @return array<string, int|string|null> */
    private function runContext(): array
    {
        return [
            'provider' => $this->environment('GITHUB_ACTIONS') === 'true' ? 'github_actions' : 'local',
            'workflow' => $this->environment('GITHUB_WORKFLOW'),
            'run_id' => $this->environment('GITHUB_RUN_ID'),
            'run_attempt' => $this->positiveIntegerEnvironment('GITHUB_RUN_ATTEMPT'),
            'event_name' => $this->environment('GITHUB_EVENT_NAME'),
            'runner_os' => $this->environment('RUNNER_OS'),
            'runner_arch' => $this->environment('RUNNER_ARCH'),
        ];
    }

    private function environment(string $name): ?string
    {
        $value = getenv($name);

        return is_string($value) && $value !== '' ? $value : null;
    }

    private function positiveIntegerEnvironment(string $name): ?int
    {
        $value = $this->environment($name);

        return $value !== null && preg_match('/\A[1-9]\d*\z/', $value) === 1 ? (int) $value : null;
    }
}
