from __future__ import annotations

import hashlib
import importlib.util
import json
import tempfile
import unittest
from decimal import Decimal
from pathlib import Path


ROOT = Path(__file__).resolve().parents[2]
SCRIPT = ROOT / "scripts" / "intelligence" / "qualify_fleet_reallocation.py"
PROTOCOL = ROOT / "docs" / "intelligence" / "protocols" / "fleet-reallocation-v1.0.0.json"
REQUIREMENTS = ROOT / "scripts" / "intelligence" / "requirements-fleet-reallocation.txt"
EVIDENCE = ROOT / "docs" / "evidence" / "intelligence" / "fleet-reallocation"
SCHEMA = ROOT / "docs" / "intelligence" / "schemas" / "fleet-reallocation-qualification-v1.0.0.json"
BENCHMARK_SHA256 = "53d79202807b2952dc95154e0116153664f202007807a0855a16cbea63cc4214"
SPEC = importlib.util.spec_from_file_location("rentfleet_fleet_reallocation_qualification", SCRIPT)
if SPEC is None or SPEC.loader is None:
    raise RuntimeError("Unable to load fleet reallocation qualification module")
MODULE = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(MODULE)


class FleetReallocationQualificationTest(unittest.TestCase):
    def test_protocol_preserves_preregistered_gates_and_frozen_result(self) -> None:
        protocol = json.loads(PROTOCOL.read_text(encoding="utf-8"))

        self.assertEqual("QUALIFIED_AFTER_PREREGISTERED_RUN", protocol["status"])
        self.assertEqual("9.15.6755", protocol["solver"]["version"])
        self.assertEqual("3.12.13", protocol["solver"]["python"])
        self.assertEqual("OPTIMAL", protocol["solver"]["required_status"])
        self.assertEqual("km", protocol["benchmark"]["distance_unit"])
        self.assertEqual(48, protocol["benchmark"]["scenario_count"])
        self.assertEqual(BENCHMARK_SHA256, protocol["benchmark"]["snapshot_sha256"])
        self.assertEqual("1.000000", protocol["benchmark"]["presence_probability"])
        self.assertEqual("0.800000", protocol["gates"]["aggregate_service_rate_min"])
        self.assertEqual("5000.000", protocol["gates"]["maximum_single_solve_runtime_ms"])
        self.assertFalse(protocol["safety"]["saas_integration_allowed_before_gate"])
        self.assertFalse(protocol["safety"]["automatic_business_write_allowed"])
        self.assertEqual(
            [
                "absl-py==2.5.0",
                "immutabledict==4.3.1",
                "numpy==2.5.2",
                "ortools==9.15.6755",
                "pandas==3.0.5",
                "protobuf==6.33.6",
                "python-dateutil==2.9.0.post0",
                "six==1.17.0",
                "typing_extensions==4.16.0",
            ],
            REQUIREMENTS.read_text(encoding="utf-8").splitlines(),
        )

    def test_frozen_qualified_result_and_ci_checksums_are_consistent(self) -> None:
        manifest = json.loads((EVIDENCE / "qualification-manifest.json").read_text(encoding="utf-8"))
        schema = json.loads(SCHEMA.read_text(encoding="utf-8"))

        self.assertTrue(manifest["gate_passed"])
        self.assertEqual(MODULE.PASS_DECISION, manifest["decision"])
        self.assertEqual("9.15.6755", manifest["solver"]["version"])
        self.assertEqual(48, manifest["benchmark"]["scenario_count"])
        self.assertEqual(BENCHMARK_SHA256, manifest["benchmark"]["snapshot_sha256"])
        self.assertEqual("0.983607", manifest["aggregates"]["ortools_min_cost_flow"]["service_rate"])
        self.assertEqual(12, manifest["aggregates"]["ortools_min_cost_flow"]["unserved_demand"])
        self.assertEqual(180, manifest["aggregates"]["greedy"]["unserved_demand"])
        self.assertEqual(348, manifest["aggregates"]["no_relocation"]["unserved_demand"])
        self.assertFalse(manifest["safety"]["automatic_business_write_allowed"])
        self.assertFalse(manifest["safety"]["catboost_output_consumed"])
        self.assertEqual(
            manifest["decision"],
            schema["properties"]["decision"]["const"],
        )

        expected = {}
        for line in (EVIDENCE / "CI_SHA256SUMS").read_text(encoding="utf-8").splitlines():
            digest, name = line.split("  ", maxsplit=1)
            expected[name] = digest
        for name, digest in expected.items():
            self.assertEqual(digest, hashlib.sha256((EVIDENCE / name).read_bytes()).hexdigest(), name)

    def test_synthetic_snapshot_is_deterministic_kilometres_only_and_catboost_abstains(self) -> None:
        first = MODULE.build_scenarios()
        second = MODULE.build_scenarios()
        encoded = MODULE.canonical_json(first).encode("utf-8")

        self.assertEqual(first, second)
        self.assertEqual(48, len(first))
        self.assertEqual(BENCHMARK_SHA256, hashlib.sha256(encoded).hexdigest())
        self.assertNotIn("tenant_id", encoded.decode("utf-8"))
        self.assertNotIn("agency_id", encoded.decode("utf-8"))
        for scenario in first:
            self.assertEqual("km", scenario["distance_unit"])
            self.assertEqual("1.000000", scenario["presence_probability"])
            self.assertEqual(
                "CATBOOST_RESEARCH_GATE_NOT_PASSED_CONSERVATIVE_NO_DISCOUNT",
                scenario["presence_reason"],
            )
            self.assertEqual(scenario["gross_demand_forecast"], scenario["effective_demand"])
            for lane in scenario["lanes"]:
                self.assertGreater(Decimal(lane["distance_km"]), 0)
                self.assertLess(
                    MODULE.distance_cost_centimes(lane["distance_km"]),
                    MODULE.UNSERVED_PENALTY_CENTIMES,
                )

    def test_baselines_are_deterministic_and_share_the_frozen_lane_constraints(self) -> None:
        scenario = MODULE.build_scenarios()[0]
        no_move = MODULE.no_relocation(scenario)
        greedy = MODULE.greedy_relocation(scenario)

        self.assertEqual(no_move, MODULE.no_relocation(scenario))
        self.assertEqual(greedy, MODULE.greedy_relocation(scenario))
        self.assertEqual(4, no_move["unserved_demand"])
        self.assertEqual(2, greedy["unserved_demand"])
        self.assertEqual(1, len(greedy["relocations"]))
        self.assertEqual("agency_alpha", greedy["relocations"][0]["origin"])
        self.assertEqual("agency_gamma", greedy["relocations"][0]["destination"])

    @unittest.skipUnless(importlib.util.find_spec("ortools"), "OR-Tools frozen environment not installed")
    def test_final_ortools_suite_passes_all_preregistered_gates_and_is_reproducible(self) -> None:
        with tempfile.TemporaryDirectory() as first_directory, tempfile.TemporaryDirectory() as second_directory:
            first_output = Path(first_directory)
            second_output = Path(second_directory)
            first_manifest = MODULE.run_qualification(first_output)
            second_manifest = MODULE.run_qualification(second_output)

            self.assertTrue(first_manifest["gate_passed"])
            self.assertEqual(MODULE.PASS_DECISION, first_manifest["decision"])
            self.assertEqual(first_manifest, second_manifest)
            self.assertEqual(
                BENCHMARK_SHA256,
                first_manifest["benchmark"]["snapshot_sha256"],
            )
            self.assertEqual("1.000000", first_manifest["gates"]["observed"]["optimal_solution_rate"])
            self.assertEqual(
                "1.000000",
                first_manifest["gates"]["observed"]["invariant_valid_solution_rate"],
            )
            self.assertGreaterEqual(
                Decimal(first_manifest["gates"]["observed"]["aggregate_service_rate"]),
                Decimal("0.80"),
            )
            self.assertFalse(first_manifest["safety"]["automatic_business_write_allowed"])
            self.assertTrue(first_manifest["safety"]["human_validation_required"])
            self.assertFalse(first_manifest["safety"]["catboost_output_consumed"])

            deterministic_names = {
                "benchmark-scenarios.json",
                "scenario-results.csv",
                "method-summary.csv",
                "sample-human-review-plan.csv",
                "qualification-manifest.json",
                "benchmark-comparison.svg",
            }
            for name in deterministic_names:
                self.assertEqual((first_output / name).read_bytes(), (second_output / name).read_bytes(), name)

            expected = {}
            for line in (first_output / "SHA256SUMS").read_text(encoding="utf-8").splitlines():
                digest, name = line.split("  ", maxsplit=1)
                expected[name] = digest
            actual = {path.name for path in first_output.iterdir() if path.is_file() and path.name != "SHA256SUMS"}
            self.assertEqual(actual, set(expected))
            for name, digest in expected.items():
                self.assertEqual(digest, hashlib.sha256((first_output / name).read_bytes()).hexdigest(), name)


if __name__ == "__main__":
    unittest.main()
