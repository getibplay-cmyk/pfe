"""End-to-end contract tests for the SaaS-invoked OR-Tools runtime."""

from __future__ import annotations

import hashlib
import json
import subprocess
import sys
import unittest
import uuid
from datetime import datetime, timezone
from pathlib import Path
from typing import Any


ROOT = Path(__file__).resolve().parents[2]
SCRIPT = ROOT / "scripts" / "intelligence" / "run_fleet_reallocation.py"


def canonical_json(value: Any) -> str:
    return json.dumps(value, ensure_ascii=False, sort_keys=True, separators=(",", ":"))


def request_payload(horizon: int = 1) -> dict[str, Any]:
    run_id = str(uuid.uuid4())
    return {
        "schema_version": "1.0.0",
        "proposal_id": run_id,
        "idempotency_key": run_id,
        "generated_at": datetime.now(timezone.utc).strftime("%Y-%m-%dT%H:%M:%SZ"),
        "as_of_date": datetime.now(timezone.utc).date().isoformat(),
        "forecast_horizon": horizon,
    }


class FleetReallocationRuntimeTest(unittest.TestCase):
    maxDiff = None

    def execute(self, payload: dict[str, Any]) -> subprocess.CompletedProcess[str]:
        return subprocess.run(
            [sys.executable, str(SCRIPT)],
            cwd=ROOT,
            input=json.dumps(payload, separators=(",", ":")),
            capture_output=True,
            text=True,
            timeout=15,
            check=False,
        )

    def test_runtime_executes_ortools_and_emits_a_closed_consultative_proposal(self) -> None:
        request = request_payload(horizon=4)
        result = self.execute(request)
        self.assertEqual(0, result.returncode, result.stderr)
        self.assertEqual("", result.stderr)
        proposal = json.loads(result.stdout)

        self.assertEqual(request["proposal_id"], proposal["proposal_id"])
        self.assertEqual(request["idempotency_key"], proposal["idempotency"]["key"])
        self.assertEqual(4, proposal["planning"]["forecast_horizon"])
        self.assertEqual("ortools_simple_min_cost_flow", proposal["source"]["solver_name"])
        self.assertEqual("9.15.6755", proposal["source"]["solver_version"])
        self.assertEqual("OPTIMAL", proposal["source"]["solver_status"])
        self.assertTrue(proposal["planning"]["moves"])
        self.assertGreaterEqual(float(proposal["summary"]["service_rate"]), 0.8)
        self.assertLessEqual(float(proposal["summary"]["solver_runtime_ms"]), 5000.0)
        self.assertEqual("NO_OPERATIONAL_ACTION", proposal["safety"]["operational_effect"])
        self.assertTrue(proposal["safety"]["synthetic_demo"])
        self.assertFalse(proposal["safety"]["contains_coordinates"])

        candidate = json.loads(json.dumps(proposal))
        candidate["idempotency"].pop("canonical_payload_sha256")
        expected_digest = hashlib.sha256(canonical_json(candidate).encode("utf-8")).hexdigest()
        self.assertEqual(
            expected_digest,
            proposal["idempotency"]["canonical_payload_sha256"],
        )
        self.assertNotIn("tenant_id", result.stdout)
        self.assertNotIn("agency_id", result.stdout)

    def test_runtime_rejects_unknown_scope_fields_before_solver_execution(self) -> None:
        request = request_payload()
        request["tenant_id"] = 999
        result = self.execute(request)
        self.assertEqual(2, result.returncode)
        self.assertEqual("", result.stdout)
        self.assertEqual(
            {"error_code": "RUNTIME_REQUEST_INVALID"},
            json.loads(result.stderr),
        )

    def test_runtime_reports_a_sanitized_dependency_failure(self) -> None:
        result = subprocess.run(
            [sys.executable, "-S", str(SCRIPT)],
            cwd=ROOT,
            input=json.dumps(request_payload(), separators=(",", ":")),
            capture_output=True,
            text=True,
            timeout=15,
            check=False,
        )
        self.assertEqual(3, result.returncode)
        self.assertEqual("", result.stdout)
        self.assertEqual(
            {"error_code": "ORTOOLS_DEPENDENCY_MISSING"},
            json.loads(result.stderr),
        )


if __name__ == "__main__":
    unittest.main()
