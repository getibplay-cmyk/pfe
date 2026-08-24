from __future__ import annotations

import csv
import contextlib
import hashlib
import importlib.util
import io
import json
import math
import sys
import tempfile
import unittest
import uuid
from pathlib import Path

import numpy as np


ROOT = Path(__file__).resolve().parents[2]
RUNTIME_PATH = ROOT / "scripts" / "intelligence" / "rental_usage_anomaly" / "run_rental_usage_anomaly.py"
SPEC = importlib.util.spec_from_file_location("rentfleet_rental_usage_anomaly", RUNTIME_PATH)
assert SPEC and SPEC.loader
runtime = importlib.util.module_from_spec(SPEC)
sys.modules[SPEC.name] = runtime
SPEC.loader.exec_module(runtime)


class RentalUsageAnomalyRuntimeTest(unittest.TestCase):
    def snapshot(self, row_count: int = 200) -> tuple[Path, bytes]:
        handle = tempfile.NamedTemporaryFile(suffix=".csv", delete=False)
        handle.close()
        path = Path(handle.name)
        stream = io.StringIO()
        writer = csv.DictWriter(stream, fieldnames=runtime.HEADERS, delimiter=";", lineterminator="\n")
        writer.writeheader()
        for index in range(row_count):
            spike = index >= row_count - 4
            digest = hashlib.sha256(f"row-{index}".encode()).hexdigest()
            writer.writerow(
                {
                    "schema_version": runtime.SOURCE_SCHEMA_VERSION,
                    "dataset_version": runtime.SOURCE_DATASET_VERSION,
                    "row_id": f"r_{digest}",
                    "tenant_key": f"t_{'1' * 64}",
                    "agency_key": f"a_{'2' * 64}",
                    "contract_key": f"c_{hashlib.sha256(f'contract-{index}'.encode()).hexdigest()}",
                    "event_at": f"2026-08-{1 + index % 24:02d}T12:00:00Z",
                    "late_hours": f"{(60 + index if spike else index % 5):.6f}",
                    "km_per_day": f"{(1500 + index if spike else 80 + index % 30):.6f}",
                    "fuel_drop_pct": f"{(70 + index if spike else 5 + index % 8):.6f}",
                }
            )
        raw = ("\ufeff" + stream.getvalue()).encode("utf-8")
        path.write_bytes(raw)
        self.addCleanup(path.unlink, missing_ok=True)
        return path, raw

    def test_primary_is_explainable_deterministic_and_budget_exact(self) -> None:
        path, raw = self.snapshot()
        snapshot = runtime.read_snapshot(path, hashlib.sha256(raw).hexdigest(), len(raw), 200)
        run_id = str(uuid.uuid4())
        first = runtime.build_result(run_id, snapshot)
        second = runtime.build_result(run_id, snapshot)

        self.assertEqual(first, second)
        self.assertEqual(first["execution"]["status"], "usable")
        self.assertEqual(first["execution"]["primary"]["name"], "robust_mad_top2")
        self.assertEqual(first["execution"]["challenger"]["name"], "isolation_forest")
        self.assertEqual([item["selected_count"] for item in first["budgets"]], [1, 2, 4])
        self.assertEqual([item["basis_points"] for item in first["budgets"]], [50, 100, 200])
        self.assertLessEqual(len(first["rows"]), 8)
        self.assertTrue(all(len(row["primary"]["factors"]) == 2 for row in first["rows"]))
        self.assertTrue(all(math.isfinite(row["primary"]["score"]) for row in first["rows"]))
        self.assertFalse(first["safety"]["automatic_actions_allowed"])
        self.assertEqual(first["safety"]["operational_effect"], "NO_OPERATIONAL_ACTION")

    def test_primary_uses_only_positive_robust_deviations(self) -> None:
        matrix = np.asarray([[10.0, 100.0, 20.0], [10.0, 100.0, 20.0], [0.0, 0.0, 0.0], [30.0, 500.0, 60.0]])
        scores, deviations, _, _ = runtime.robust_mad_top2(matrix)
        self.assertTrue(np.all(deviations[2] == 0.0))
        self.assertEqual(scores[2], 0.0)
        self.assertGreater(scores[3], scores[0])

    def test_small_snapshot_abstains_without_fitting_the_challenger(self) -> None:
        path, raw = self.snapshot(199)
        snapshot = runtime.read_snapshot(path, hashlib.sha256(raw).hexdigest(), len(raw), 199)
        result = runtime.build_result(str(uuid.uuid4()), snapshot)
        self.assertEqual(result["execution"]["status"], "insufficient_data")
        self.assertEqual(result["execution"]["reason"], "MINIMUM_HISTORY_NOT_REACHED")
        self.assertEqual(result["budgets"], [])
        self.assertEqual(result["rows"], [])

    def test_non_divisible_history_reports_the_realized_budget(self) -> None:
        path, raw = self.snapshot(201)
        snapshot = runtime.read_snapshot(path, hashlib.sha256(raw).hexdigest(), len(raw), 201)
        result = runtime.build_result(str(uuid.uuid4()), snapshot)
        self.assertEqual([item["selected_count"] for item in result["budgets"]], [2, 3, 5])
        self.assertAlmostEqual(result["budgets"][0]["realized_rate"], 2 / 201, places=8)
        self.assertAlmostEqual(result["budgets"][2]["realized_rate"], 5 / 201, places=8)

    def test_tampered_snapshot_and_wrong_schema_fail_closed(self) -> None:
        path, raw = self.snapshot()
        with self.assertRaises(runtime.ContractError):
            runtime.read_snapshot(path, "0" * 64, len(raw), 200)

        altered = raw.replace(b"schema_version", b"schema_changed", 1)
        path.write_bytes(altered)
        with self.assertRaises(runtime.ContractError):
            runtime.read_snapshot(path, hashlib.sha256(altered).hexdigest(), len(altered), 200)

    def test_cli_verifies_its_versioned_runtime_digest(self) -> None:
        path, raw = self.snapshot(199)
        arguments = [
            "--run-id", str(uuid.uuid4()),
            "--snapshot", str(path),
            "--snapshot-sha256", hashlib.sha256(raw).hexdigest(),
            "--snapshot-bytes", str(len(raw)),
            "--snapshot-rows", "199",
            "--minimum-rows", "200",
            "--runtime-sha256", runtime.RUNTIME_SHA256,
            "--stdout",
        ]
        output = io.StringIO()
        with contextlib.redirect_stdout(output):
            self.assertEqual(runtime.main(arguments), 0)
        payload = json.loads(output.getvalue())
        self.assertEqual(payload["execution"]["runtime_sha256"], runtime.RUNTIME_SHA256)

        arguments[arguments.index("--runtime-sha256") + 1] = "0" * 64
        with self.assertRaises(runtime.ContractError):
            runtime.main(arguments)


if __name__ == "__main__":
    unittest.main()
