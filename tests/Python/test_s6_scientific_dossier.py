import hashlib
import json
import unittest
from pathlib import Path


ROOT = Path(__file__).resolve().parents[2]
DOSSIER = ROOT / "docs/evidence/intelligence"
MANIFEST = DOSSIER / "s6-evidence-manifest.json"


class S6ScientificDossierTest(unittest.TestCase):
    def setUp(self) -> None:
        self.manifest = json.loads(MANIFEST.read_text())

    def test_closure_files_are_present_and_complete(self) -> None:
        for name in (
            "J15B_INDEX.md",
            "STOP_S6_CHECKLIST.md",
            "s6-evidence-manifest.json",
            "S6_SHA256SUMS",
        ):
            self.assertTrue((DOSSIER / name).is_file(), name)

        checklist = (DOSSIER / "STOP_S6_CHECKLIST.md").read_text()
        self.assertNotIn("- [ ]", checklist)
        self.assertIn("NO_OPERATIONAL_ACTION", checklist)
        self.assertIn("Aucun rapport ni slide", checklist)

    def test_only_authorized_final_components_are_declared(self) -> None:
        components = self.manifest["components"]
        self.assertEqual(
            {
                "demand_forecast_hgb",
                "cancellation_risk_catboost",
                "fleet_reallocation_ortools",
            },
            set(components),
        )
        serialized = json.dumps(self.manifest)
        for forbidden in ("isolation_forest", "scania_model", "mad_model", "milp_model"):
            self.assertNotIn(forbidden, serialized.lower())

    def test_catboost_negative_result_is_frozen(self) -> None:
        catboost = self.manifest["components"]["cancellation_risk_catboost"]
        final_test = catboost["final_test"]
        self.assertFalse(final_test["reopened"])
        self.assertTrue(final_test["locked"])
        self.assertLess(
            final_test["balanced_accuracy"],
            final_test["balanced_accuracy_gate"],
        )
        self.assertLess(final_test["macro_f1"], final_test["macro_f1_gate"])
        self.assertEqual(
            "RESEARCH_GATE_NOT_PASSED_NO_SAAS_INTEGRATION",
            catboost["decision"],
        )
        self.assertFalse(catboost["saas_integration_allowed"])

    def test_hgb_and_ortools_gates_match_frozen_evidence(self) -> None:
        hgb = self.manifest["components"]["demand_forecast_hgb"]
        hgb_evidence = json.loads(
            (DOSSIER / "demand-forecast/qualification-manifest.json").read_text()
        )
        self.assertEqual(hgb["horizons_days"], hgb_evidence["model"]["horizons_days"])
        self.assertAlmostEqual(hgb["final_test"]["wape"], hgb_evidence["final_test"]["wape"])
        self.assertTrue(hgb_evidence["final_test"]["confirmation_gate_passed"])

        ortools = self.manifest["components"]["fleet_reallocation_ortools"]
        ortools_evidence = json.loads(
            (DOSSIER / "fleet-reallocation/qualification-manifest.json").read_text()
        )
        self.assertTrue(ortools_evidence["gate_passed"])
        self.assertEqual("km", ortools["distance_unit"])
        self.assertEqual(
            ortools["scenario_count"],
            ortools_evidence["benchmark"]["scenario_count"],
        )
        self.assertGreaterEqual(ortools["service_rate"], ortools["service_rate_gate"])
        for baseline in ("greedy", "no_relocation"):
            self.assertLess(
                ortools["unserved_demand"]["ortools"],
                ortools["unserved_demand"][baseline],
            )
            self.assertLess(
                ortools["decision_cost_centimes"]["ortools"],
                ortools["decision_cost_centimes"][baseline],
            )

    def test_demo_and_privacy_boundaries_are_closed(self) -> None:
        demo = self.manifest["consultative_demo"]
        self.assertTrue(demo["human_validation_required"])
        self.assertEqual("NO_OPERATIONAL_ACTION", demo["decision_effect"])
        self.assertIn("CATBOOST_ABSTENTION_NO_DEMAND_REDUCTION", demo["ordered_stages"])

        privacy = self.manifest["privacy"]
        self.assertTrue(privacy["tenant_and_agency_derived_server_side"])
        self.assertFalse(privacy["raw_personal_data_allowed"])
        self.assertFalse(privacy["secrets_allowed"])
        self.assertFalse(privacy["direct_identity_allowed"])

        closure = self.manifest["closure"]
        self.assertTrue(closure["head_ci_must_be_green"])
        self.assertFalse(closure["main_mutation_authorized"])
        self.assertFalse(closure["report_started"])
        self.assertFalse(closure["slides_started"])

    def test_closure_checksums_are_complete_and_valid(self) -> None:
        checksum_file = DOSSIER / "S6_SHA256SUMS"
        observed = set()
        for line in checksum_file.read_text().splitlines():
            expected, relative = line.split("  ", 1)
            observed.add(relative)
            actual = hashlib.sha256((DOSSIER / relative).read_bytes()).hexdigest()
            self.assertEqual(expected, actual, relative)
        self.assertEqual(
            {"J15B_INDEX.md", "STOP_S6_CHECKLIST.md", "s6-evidence-manifest.json"},
            observed,
        )


if __name__ == "__main__":
    unittest.main()
