import csv
import hashlib
import json
import unittest
from pathlib import Path


ROOT = Path(__file__).resolve().parents[2]
EVIDENCE = ROOT / "docs/evidence/intelligence/demand-forecast"
NOTEBOOK = ROOT / "notebooks/J15B_demand_forecast_hgb.ipynb"


class DemandForecastJ15BEvidenceTest(unittest.TestCase):
    def test_required_j15b_artifacts_are_present(self) -> None:
        required = {
            "qualification-manifest.json",
            "final-test-comparison.csv",
            "environment.json",
            "requirements-frozen.txt",
            "demand-forecast-hgb-model-card.md",
            "munich-shared-mobility-datasheet.md",
            "benchmark-comparison.svg",
            "notebook-manifest.json",
            "SHA256SUMS",
        }
        self.assertEqual(required, {path.name for path in EVIDENCE.iterdir()})
        self.assertTrue(NOTEBOOK.is_file())

    def test_manifest_freezes_scientific_and_operational_boundaries(self) -> None:
        manifest = json.loads((EVIDENCE / "qualification-manifest.json").read_text())
        self.assertEqual("HistGradientBoostingRegressor", manifest["model"]["family"])
        self.assertEqual(list(range(1, 8)), manifest["model"]["horizons_days"])
        self.assertTrue(manifest["preprocessing"]["anti_leakage_passed"])
        self.assertTrue(manifest["split"]["final_test"]["locked"])
        self.assertFalse(manifest["selection"]["final_test_used_for_retuning"])
        self.assertTrue(manifest["final_test"]["confirmation_gate_passed"])
        self.assertEqual(
            "not_validated_without_sufficient_real_rentfleet_history",
            manifest["local_status"],
        )
        self.assertTrue(manifest["integration"]["human_validation_required"])
        self.assertFalse(manifest["integration"]["automatic_operational_action"])
        self.assertFalse(manifest["public_benchmark"]["raw_redistribution"])

    def test_hgb_beats_both_frozen_baselines(self) -> None:
        with (EVIDENCE / "final-test-comparison.csv").open(newline="") as stream:
            rows = list(csv.DictReader(stream))
        hgb = rows[0]
        for baseline in rows[1:]:
            self.assertLess(float(hgb["wape"]), float(baseline["wape"]))
            self.assertLess(float(hgb["mase"]), float(baseline["mase"]))

    def test_notebook_is_an_audit_not_a_second_final_test(self) -> None:
        notebook = json.loads(NOTEBOOK.read_text())
        source = "\n".join(
            line
            for cell in notebook["cells"]
            for line in cell.get("source", [])
        )
        self.assertIn("DO_NOT_REOPEN_FINAL_TEST = True", source)
        self.assertIn("test_used_for_retuning", source)
        self.assertNotIn(".fit(", source)
        self.assertIn("validation humaine", source)
        self.assertEqual(4, notebook["nbformat"])

        notebook_manifest = json.loads((EVIDENCE / "notebook-manifest.json").read_text())
        self.assertEqual(
            hashlib.sha256(NOTEBOOK.read_bytes()).hexdigest(),
            notebook_manifest["sha256"],
        )

    def test_repository_checksums_are_complete_and_valid(self) -> None:
        checksum_file = EVIDENCE / "SHA256SUMS"
        expected_names = {path.name for path in EVIDENCE.iterdir()} - {"SHA256SUMS"}
        observed_names = set()
        for line in checksum_file.read_text().splitlines():
            expected, name = line.split("  ", 1)
            observed_names.add(name)
            actual = hashlib.sha256((EVIDENCE / name).read_bytes()).hexdigest()
            self.assertEqual(expected, actual, name)
        self.assertEqual(expected_names, observed_names)


if __name__ == "__main__":
    unittest.main()
