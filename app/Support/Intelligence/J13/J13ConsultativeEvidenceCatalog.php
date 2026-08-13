<?php

namespace App\Support\Intelligence\J13;

use App\Enums\J11AdvisoryModule;
use JsonException;
use LogicException;

final class J13ConsultativeEvidenceCatalog
{
    private const MANIFEST_PATH = 'docs/intelligence/j12-scientific-evidence-manifest.json';

    private const MANIFEST_VERSION = '1.0.0';

    private const DECISION = 'J13_CONSULTATIVE_DISABLED_ONLY';

    private const MODE = 'consultative_disabled_only';

    /** @var array<string, mixed>|null */
    private ?array $manifest = null;

    /**
     * @return list<array<string, bool|string|list<string>>>
     */
    public function cards(): array
    {
        $manifest = $this->manifest();
        $gate = $this->gate();
        $taxonomy = $this->requiredArray($manifest, 'evidence_taxonomy', 'taxonomie de preuve');
        $entries = $this->requiredArray($manifest, 'modules', 'modules');
        $modulesById = [];

        foreach ($entries as $entry) {
            if (! is_array($entry)) {
                $this->invalid('Une entrée de module n’est pas un objet.');
            }

            $id = $this->requiredString($entry, 'id', 'identifiant de module');
            if (array_key_exists($id, $modulesById)) {
                $this->invalid('Le module '.$id.' est dupliqué.');
            }

            $modulesById[$id] = $entry;
        }

        $expectedIds = array_map(
            static fn (J11AdvisoryModule $module): string => $module->value,
            J11AdvisoryModule::cases(),
        );
        $actualIds = array_keys($modulesById);
        sort($expectedIds);
        sort($actualIds);

        if ($actualIds !== $expectedIds) {
            $this->invalid('Le manifeste J13 doit contenir exactement les quatre modules J11 autorisés.');
        }

        $cards = [];
        foreach (J11AdvisoryModule::cases() as $module) {
            $entry = $modulesById[$module->value];
            $evidenceClass = $this->requiredString($entry, 'evidence_class', 'classe de preuve');
            $evidenceDescription = $taxonomy[$evidenceClass] ?? null;
            $forbiddenClaims = $entry['claims_forbidden'] ?? null;
            $fixture = $this->requiredArray($entry, 'fixture', 'fixture');
            $schema = $this->requiredArray($entry, 'schema', 'schéma');

            if (! is_string($evidenceDescription) || $evidenceDescription === '') {
                $this->invalid('La classe de preuve '.$evidenceClass.' n’est pas documentée.');
            }

            if (! is_array($forbiddenClaims) || $forbiddenClaims === []) {
                $this->invalid('Les limites scientifiques de '.$module->value.' sont absentes.');
            }

            foreach ($forbiddenClaims as $claim) {
                if (! is_string($claim) || $claim === '') {
                    $this->invalid('Une limite scientifique de '.$module->value.' est invalide.');
                }
            }

            if (($entry['gate_decision'] ?? null) !== $module->gateDecision()
                || ($entry['audit_score'] ?? null) !== $module->auditScore()
                || ($entry['ready_for_saas'] ?? null) !== false
                || ($entry['production_allowed'] ?? null) !== false
                || ! is_bool($entry['benchmark_gate_passed'] ?? null)
                || ($fixture['path'] ?? null) !== 'resources/intelligence/j11/fixtures/'.$module->fixtureFile()
                || ($fixture['sha256'] ?? null) !== $module->fixtureSha256()
                || ($schema['path'] ?? null) !== 'resources/intelligence/j11/schemas/'.$module->schemaFile()
                || ($schema['sha256'] ?? null) !== $module->schemaSha256()) {
                $this->invalid('Les preuves gelées de '.$module->value.' ne correspondent plus au contrat J11/J12.');
            }

            $cards[] = [
                'id' => $module->value,
                'label' => $module->label(),
                'authoritative_stage' => $this->requiredString($entry, 'authoritative_stage', 'étape scientifique'),
                'gate_decision' => $module->gateDecision(),
                'audit_score' => $module->auditScore(),
                'benchmark_gate_passed' => $entry['benchmark_gate_passed'],
                'evidence_class' => $evidenceClass,
                'evidence_label' => $this->evidenceLabel($evidenceClass),
                'evidence_description' => $evidenceDescription,
                'benchmark_role' => $this->requiredString($entry, 'benchmark_role', 'rôle du benchmark'),
                'claim_allowed' => $this->requiredString($entry, 'claim_allowed', 'affirmation autorisée'),
                'claims_forbidden' => array_values($forbiddenClaims),
                'feature_enabled' => $gate['feature_flags_enabled'],
                'ready_for_saas' => false,
                'production_allowed' => false,
            ];
        }

        return $cards;
    }

    /** @return array<string, bool|int|string> */
    public function gate(): array
    {
        $gate = $this->requiredArray($this->manifest(), 'j13_entry_gate', 'porte d’entrée J13');

        if (($gate['mode'] ?? null) !== self::MODE
            || ($gate['module_count'] ?? null) !== count(J11AdvisoryModule::cases())) {
            $this->invalid('La porte d’entrée J13 ne correspond pas au mode consultatif gelé.');
        }

        foreach ([
            'new_model_allowed',
            'dynamic_pricing_allowed',
            'feature_flags_enabled',
            'ready_for_saas',
            'production_allowed',
            'inference_allowed',
            'training_allowed',
            'solver_allowed',
            'historical_public_output_import_allowed',
            'automatic_action_allowed',
            'operational_business_write_allowed',
        ] as $closedBoundary) {
            if (($gate[$closedBoundary] ?? null) !== false) {
                $this->invalid('La frontière J13 '.$closedBoundary.' doit rester fermée.');
            }
        }

        if (($gate['human_decision_required'] ?? null) !== true
            || ($gate['tenant_and_agency_server_derived'] ?? null) !== true
            || ($gate['decision_effect'] ?? null) !== 'NO_OPERATIONAL_ACTION') {
            $this->invalid('Les garanties humaines et de périmètre J13 ne sont plus intactes.');
        }

        return $gate;
    }

    /** @return array<string, array<string, bool|string>> */
    public function anomalyLineage(): array
    {
        $lineage = $this->requiredArray($this->manifest(), 'anomaly_lineage', 'lignée des anomalies');
        $j9 = $this->requiredArray($lineage, 'j9_public_proxy_benchmark', 'benchmark public J9');
        $legacy = $this->requiredArray($lineage, 'legacy_lot07b1_synthetic_artifact', 'artefact historique Lot 07B1');
        $fixture = $this->requiredArray($lineage, 'j11_j12_fixture', 'fixture J11/J12');

        if (($j9['selected_candidate'] ?? null) !== 'robust_mad_top2'
            || ($j9['allowed_in_j13'] ?? null) !== false
            || ($legacy['name'] ?? null) !== config('intelligence.frozen_model.name')
            || ($legacy['version'] ?? null) !== config('intelligence.frozen_model.version')
            || ($legacy['algorithm'] ?? null) !== config('intelligence.frozen_model.algorithm')
            || ($legacy['threshold'] ?? null) !== config('intelligence.frozen_model.threshold')
            || ($legacy['training_data'] ?? null) !== config('intelligence.frozen_model.training_data')
            || ($legacy['relationship_to_j9'] ?? null) !== 'separate_legacy_artifact_not_the_j9_selected_candidate'
            || ($legacy['allowed_in_j13'] ?? null) !== false
            || ($fixture['computation_status'] ?? null) !== 'not_run_synthetic_contract_fixture'
            || ($fixture['relationship_to_models'] ?? null) !== 'no_model_or_solver_was_executed') {
            $this->invalid('La lignée J9, Lot 07B1 et J11/J12 est ambiguë ou incohérente.');
        }

        return [
            'j9_public_proxy_benchmark' => $j9,
            'legacy_lot07b1_synthetic_artifact' => $legacy,
            'j11_j12_fixture' => $fixture,
        ];
    }

    /** @return array<string, mixed> */
    private function manifest(): array
    {
        if ($this->manifest !== null) {
            return $this->manifest;
        }

        $path = base_path(self::MANIFEST_PATH);
        if (! is_file($path) || ! is_readable($path)) {
            $this->invalid('Le manifeste scientifique J12 est introuvable.');
        }

        $contents = file_get_contents($path);
        if ($contents === false) {
            $this->invalid('Le manifeste scientifique J12 ne peut pas être lu.');
        }

        try {
            $manifest = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new LogicException('Le manifeste scientifique J12 est invalide.', 0, $exception);
        }

        if (! is_array($manifest)
            || ($manifest['manifest_version'] ?? null) !== self::MANIFEST_VERSION
            || ($manifest['decision'] ?? null) !== self::DECISION) {
            $this->invalid('La version ou la décision du manifeste scientifique J12 est inattendue.');
        }

        return $this->manifest = $manifest;
    }

    /**
     * @param  array<string, mixed>  $source
     * @return array<string, mixed>
     */
    private function requiredArray(array $source, string $key, string $label): array
    {
        $value = $source[$key] ?? null;
        if (! is_array($value)) {
            $this->invalid('Le champ '.$label.' est absent ou invalide.');
        }

        return $value;
    }

    /** @param array<string, mixed> $source */
    private function requiredString(array $source, string $key, string $label): string
    {
        $value = $source[$key] ?? null;
        if (! is_string($value) || $value === '') {
            $this->invalid('Le champ '.$label.' est absent ou invalide.');
        }

        return $value;
    }

    private function evidenceLabel(string $evidenceClass): string
    {
        return match ($evidenceClass) {
            'public_proxy_benchmark' => 'Benchmark public proxy',
            'synthetic_contract_proof' => 'Preuve contractuelle synthétique',
            'software_integration_proof' => 'Preuve d’intégration logicielle',
            'rentfleet_local_validation' => 'Validation locale RentFleet',
            'production_evidence' => 'Preuve de production',
            default => $evidenceClass,
        };
    }

    private function invalid(string $message): never
    {
        throw new LogicException($message);
    }
}
