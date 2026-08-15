from __future__ import annotations

import hashlib
import importlib.util
import json
import sys
import unittest
from pathlib import Path

import numpy as np
import pandas as pd
import yaml
from catboost import CatBoostClassifier


ROOT = Path(__file__).resolve().parents[2]
SCRIPT = ROOT / "scripts" / "intelligence" / "train_cancellation_risk.py"
EVIDENCE = ROOT / "docs" / "evidence" / "intelligence" / "cancellation-risk"
NOTEBOOK = ROOT / "notebooks" / "J15B_cancellation_risk_catboost.ipynb"
QUALIFICATION_COMMIT = "f27985d35aa853653e3120a3ee3acb5289948319"
SPEC = importlib.util.spec_from_file_location("rentfleet_cancellation_qualification", SCRIPT)
if SPEC is None or SPEC.loader is None:
    raise RuntimeError("Unable to load the cancellation qualification module")
MODULE = importlib.util.module_from_spec(SPEC)
sys.modules[SPEC.name] = MODULE
SPEC.loader.exec_module(MODULE)


class CancellationRiskQualificationTest(unittest.TestCase):
    def test_frozen_negative_result_and_checksums_are_consistent(self) -> None:
        manifest = json.loads((EVIDENCE / "qualification-manifest.json").read_text(encoding="utf-8"))
        schema = json.loads(
            (ROOT / "docs" / "intelligence" / "schemas" / "cancellation-qualification-v1.0.0.json")
            .read_text(encoding="utf-8")
        )
        protocol = yaml.safe_load(
            (ROOT / "docs" / "intelligence" / "protocols" / "cancellation-risk-v1.0.0.yaml")
            .read_text(encoding="utf-8")
        )

        self.assertEqual("RESEARCH_GATE_NOT_PASSED_NO_SAAS_INTEGRATION", manifest["gate"]["decision"])
        self.assertFalse(manifest["gate"]["passed"])
        self.assertLess(manifest["test_metrics"]["balanced_accuracy"], 0.80)
        self.assertLess(manifest["test_metrics"]["macro_f1"], 0.80)
        self.assertFalse(manifest["safety"]["saas_integration_allowed"])
        self.assertFalse(manifest["safety"]["automatic_action_allowed"])
        self.assertFalse(manifest["safety"]["operational_business_write_allowed"])
        self.assertEqual("NOT_VALIDATED_NO_REAL_HISTORY", manifest["safety"]["local_rentfleet_status"])
        self.assertEqual("km", manifest["protocol"]["distance_unit"])
        self.assertEqual(1, manifest["model"]["parameters"]["thread_count"])
        self.assertEqual(MODULE.DATASET_SHA256, manifest["dataset"]["snapshot_sha256"])
        self.assertEqual(manifest["gate"]["decision"], protocol["decision"])
        self.assertEqual(manifest["test_metrics"]["balanced_accuracy"], protocol["gates"]["observed_balanced_accuracy"])
        self.assertEqual(manifest["test_metrics"]["macro_f1"], protocol["gates"]["observed_macro_f1"])
        self.assertEqual(manifest["gate"]["decision"], schema["properties"]["gate"]["properties"]["decision"]["const"])
        self.assertEqual(118_675, sum(block["rows"] for block in manifest["splits"]["blocks"].values()))
        self.assertTrue(set(MODULE.FORBIDDEN_LEAKAGE_COLUMNS).isdisjoint(manifest["mapping"]["features"]))

        expected = {}
        for line in (EVIDENCE / "SHA256SUMS").read_text(encoding="utf-8").splitlines():
            digest, name = line.split("  ", maxsplit=1)
            expected[name] = digest
        actual_files = {path.name for path in EVIDENCE.iterdir() if path.is_file() and path.name != "SHA256SUMS"}
        self.assertEqual(actual_files, set(expected))
        for name, digest in expected.items():
            self.assertEqual(digest, hashlib.sha256((EVIDENCE / name).read_bytes()).hexdigest(), name)

        model_path = EVIDENCE / "cancellation-risk-catboost.json"
        normalized_model = json.loads(model_path.read_text(encoding="utf-8"))
        self.assertNotIn("model_guid", normalized_model["model_info"])
        self.assertNotIn("train_finish_time", normalized_model["model_info"])

        frozen_model = CatBoostClassifier()
        frozen_model.load_model(model_path, format="json")
        self.assertEqual(63, frozen_model.tree_count_)

    def test_chronological_split_has_disjoint_ordered_blocks(self) -> None:
        dates = pd.date_range("2025-01-01", periods=200, freq="D")
        frame = pd.DataFrame({"arrival_date": dates, "target": np.arange(200) % 2})

        split, details = MODULE.chronological_split(frame)

        self.assertEqual(set(MODULE.SPLIT_NAMES), set(split.unique()))
        self.assertEqual(200, sum(block["rows"] for block in details["blocks"].values()))
        previous_end = None
        for name in MODULE.SPLIT_NAMES:
            block = details["blocks"][name]
            if previous_end is not None:
                self.assertLess(previous_end, block["date_from"])
            previous_end = block["date_to"]

    def test_threshold_selection_is_deterministic_and_class_balanced(self) -> None:
        target = np.asarray([0, 0, 0, 0, 1, 1, 1, 1])
        probabilities = np.asarray([0.05, 0.15, 0.30, 0.40, 0.60, 0.70, 0.80, 0.95])

        first = MODULE.choose_threshold(target, probabilities)
        second = MODULE.choose_threshold(target, probabilities)

        self.assertEqual(first, second)
        self.assertEqual(1.0, first["balanced_accuracy"])
        self.assertEqual(1.0, first["macro_f1"])
        self.assertEqual(0.5, first["threshold"])

    def test_colab_notebook_is_clean_and_pinned_to_the_qualification_commit(self) -> None:
        notebook = json.loads(NOTEBOOK.read_text(encoding="utf-8"))
        sources = "\n".join("".join(cell.get("source", [])) for cell in notebook["cells"])

        self.assertEqual(4, notebook["nbformat"])
        self.assertIn(QUALIFICATION_COMMIT, sources)
        self.assertIn(MODULE.DATASET_SHA256, sources)
        self.assertIn("RESEARCH_GATE_NOT_PASSED_NO_SAAS_INTEGRATION", sources)
        self.assertTrue(all(cell.get("execution_count") is None for cell in notebook["cells"] if cell["cell_type"] == "code"))
        self.assertTrue(all(not cell.get("outputs") for cell in notebook["cells"] if cell["cell_type"] == "code"))
        self.assertNotRegex(sources.lower(), r"codex|chatgpt|claude|openai")


if __name__ == "__main__":
    unittest.main()
