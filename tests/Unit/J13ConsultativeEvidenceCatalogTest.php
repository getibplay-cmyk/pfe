<?php

namespace Tests\Unit;

use App\Enums\J11AdvisoryModule;
use App\Support\Intelligence\J13\J13ConsultativeEvidenceCatalog;
use Tests\TestCase;

class J13ConsultativeEvidenceCatalogTest extends TestCase
{
    public function test_catalog_exposes_exactly_four_frozen_modules_and_a_closed_gate(): void
    {
        $catalog = app(J13ConsultativeEvidenceCatalog::class);
        $cards = $catalog->cards();
        $gate = $catalog->gate();

        $this->assertCount(4, $cards);
        $this->assertSame(
            array_map(static fn (J11AdvisoryModule $module): string => $module->value, J11AdvisoryModule::cases()),
            array_column($cards, 'id'),
        );
        $this->assertSame('consultative_disabled_only', $gate['mode']);
        $this->assertFalse($gate['feature_flags_enabled']);
        $this->assertFalse($gate['ready_for_saas']);
        $this->assertFalse($gate['production_allowed']);
        $this->assertFalse($gate['inference_allowed']);
        $this->assertFalse($gate['training_allowed']);
        $this->assertFalse($gate['solver_allowed']);
        $this->assertFalse($gate['automatic_action_allowed']);
        $this->assertFalse($gate['operational_business_write_allowed']);
        $this->assertTrue($gate['human_decision_required']);
        $this->assertSame('NO_OPERATIONAL_ACTION', $gate['decision_effect']);

        foreach ($cards as $card) {
            $this->assertFalse($card['feature_enabled']);
            $this->assertFalse($card['ready_for_saas']);
            $this->assertFalse($card['production_allowed']);
            $this->assertSame('Benchmark public proxy', $card['evidence_label']);
            $this->assertNotSame('', $card['claim_allowed']);
            $this->assertNotEmpty($card['claims_forbidden']);
        }
    }

    public function test_catalog_preserves_the_exact_frozen_decisions_and_claim_boundaries(): void
    {
        $cards = collect(app(J13ConsultativeEvidenceCatalog::class)->cards())->keyBy('id');

        foreach (J11AdvisoryModule::cases() as $module) {
            $card = $cards->get($module->value);

            $this->assertIsArray($card);
            $this->assertSame($module->label(), $card['label']);
            $this->assertSame($module->gateDecision(), $card['gate_decision']);
            $this->assertSame($module->auditScore(), $card['audit_score']);
            $this->assertSame('public_proxy_benchmark', $card['evidence_class']);
        }
    }

    public function test_anomaly_lineage_keeps_j9_legacy_and_fixture_evidence_distinct(): void
    {
        $lineage = app(J13ConsultativeEvidenceCatalog::class)->anomalyLineage();
        $j9 = $lineage['j9_public_proxy_benchmark'];
        $legacy = $lineage['legacy_lot07b1_synthetic_artifact'];
        $fixture = $lineage['j11_j12_fixture'];

        $this->assertSame('robust_mad_top2', $j9['selected_candidate']);
        $this->assertFalse($j9['allowed_in_j13']);
        $this->assertSame('rental_anomaly_iforest', $legacy['name']);
        $this->assertSame('0.1.0', $legacy['version']);
        $this->assertNotSame($j9['selected_candidate'], $legacy['name']);
        $this->assertSame('synthetic', $legacy['training_data']);
        $this->assertFalse($legacy['allowed_in_j13']);
        $this->assertSame('not_run_synthetic_contract_fixture', $fixture['computation_status']);
        $this->assertSame('no_model_or_solver_was_executed', $fixture['relationship_to_models']);
    }
}
