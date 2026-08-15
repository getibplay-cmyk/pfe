from __future__ import annotations

import hashlib
import importlib.util
import json
import tempfile
import unittest
from pathlib import Path


ROOT = Path(__file__).resolve().parents[2]
SCRIPT = ROOT / "scripts" / "intelligence" / "build_consultative_demo.py"
EVIDENCE = ROOT / "docs" / "evidence" / "intelligence" / "consultative-demo"
SPEC = importlib.util.spec_from_file_location("rentfleet_consultative_demo", SCRIPT)
if SPEC is None or SPEC.loader is None:
    raise RuntimeError("Unable to load the consultative demo builder")
MODULE = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(MODULE)


@unittest.skipUnless(importlib.util.find_spec("ortools"), "OR-Tools frozen environment not installed")
class ConsultativeDemoTest(unittest.TestCase):
    def test_bundle_is_reproducible_chained_and_checksum_complete(self) -> None:
        with tempfile.TemporaryDirectory() as first_directory, tempfile.TemporaryDirectory() as second_directory:
            first = Path(first_directory)
            second = Path(second_directory)
            first_trace = MODULE.build_bundle(first)
            second_trace = MODULE.build_bundle(second)

            self.assertEqual(first_trace, second_trace)
            self.assertEqual(
                {path.name for path in first.iterdir()},
                {
                    "README.md",
                    "SHA256SUMS",
                    "fleet-reallocation-proposal.json",
                    "pipeline-trace.json",
                    "synthetic-hgb-forecast.json",
                },
            )
            for path in first.iterdir():
                self.assertEqual(path.read_bytes(), (second / path.name).read_bytes(), path.name)
                self.assertEqual(path.read_bytes(), (EVIDENCE / path.name).read_bytes(), path.name)

            expected = {}
            for line in (first / "SHA256SUMS").read_text(encoding="utf-8").splitlines():
                digest, name = line.split("  ", maxsplit=1)
                expected[name] = digest
            self.assertEqual(
                {path.name for path in first.iterdir() if path.name != "SHA256SUMS"},
                set(expected),
            )
            for name, digest in expected.items():
                self.assertEqual(digest, hashlib.sha256((first / name).read_bytes()).hexdigest(), name)

    def test_forecast_abstention_optimization_and_human_gate_are_explicit(self) -> None:
        forecast = json.loads((EVIDENCE / "synthetic-hgb-forecast.json").read_text(encoding="utf-8"))
        proposal = json.loads((EVIDENCE / "fleet-reallocation-proposal.json").read_text(encoding="utf-8"))
        trace = json.loads((EVIDENCE / "pipeline-trace.json").read_text(encoding="utf-8"))

        self.assertEqual("SYNTHETIC_INPUT_CONFORMING_TO_HGB_CONTRACT_NOT_MODEL_INFERENCE", forecast["forecast_status"])
        self.assertEqual(MODULE.FORECAST_HEAD, forecast["model_reference"]["head_commit"])
        self.assertFalse(forecast["safety"]["local_accuracy_claimed"])
        forecast_sha = hashlib.sha256((EVIDENCE / "synthetic-hgb-forecast.json").read_bytes()).hexdigest()
        self.assertEqual(forecast_sha, proposal["planning"]["demand_source"]["forecast_reference_sha256"])
        self.assertEqual("RESEARCH_GATE_NOT_PASSED_NO_SAAS_INTEGRATION", proposal["planning"]["cancellation_risk"]["gate_decision"])
        self.assertEqual("1.000000", proposal["planning"]["cancellation_risk"]["presence_probability"])
        for node in proposal["planning"]["nodes"]:
            self.assertEqual(node["forecast_demand"], node["effective_demand"])
        self.assertEqual("km", proposal["planning"]["distance_unit"])
        self.assertEqual("OPTIMAL", proposal["source"]["solver_status"])
        self.assertEqual("1.000000", proposal["summary"]["service_rate"])
        self.assertLess(
            trace["comparison"]["ortools_min_cost_flow"]["unserved_demand"],
            trace["comparison"]["greedy"]["unserved_demand"],
        )
        self.assertTrue(proposal["safety"]["human_decision_required"])
        self.assertFalse(proposal["safety"]["automatic_action_allowed"])
        self.assertEqual("EXPLICIT_ACCEPT_OR_REJECT_REQUIRED", trace["ordered_stages"][-1]["status"])
        self.assertEqual(
            "FROZEN_MAXIMUM_FROM_QUALIFIED_48_SCENARIO_BENCHMARK",
            trace["runtime_measurement"]["source"],
        )
        self.assertEqual(
            MODULE.canonical_payload_digest(proposal),
            proposal["idempotency"]["canonical_payload_sha256"],
        )

        serialized = MODULE.canonical_json({"forecast": forecast, "proposal": proposal, "trace": trace})
        for forbidden in ("tenant_id", "agency_id", "latitude", "longitude", "customer_id"):
            self.assertNotIn(forbidden, serialized)


if __name__ == "__main__":
    unittest.main()
