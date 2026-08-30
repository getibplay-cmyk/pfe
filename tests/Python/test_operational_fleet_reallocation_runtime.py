import json
import subprocess
import sys
import unittest
import uuid
from datetime import date, datetime, timedelta, timezone
from pathlib import Path


ROOT = Path(__file__).resolve().parents[2]
SCRIPT = ROOT / "scripts" / "intelligence" / "run_fleet_reallocation.py"


class OperationalFleetReallocationRuntimeTest(unittest.TestCase):
    def request(self, *, transfer: bool = True) -> dict:
        reference = date(2026, 8, 30)
        days = []
        for horizon in range(1, 8):
            if transfer:
                nodes = [
                    {
                        "node_ref": "NODE-001",
                        "available_vehicle_units": 8,
                        "planning_vehicle_units": 3,
                        "transferable_surplus": 5,
                        "uncovered_need": 0,
                    },
                    {
                        "node_ref": "NODE-002",
                        "available_vehicle_units": 1,
                        "planning_vehicle_units": 4,
                        "transferable_surplus": 0,
                        "uncovered_need": 3,
                    },
                ]
            else:
                nodes = [
                    {
                        "node_ref": "NODE-001",
                        "available_vehicle_units": 5,
                        "planning_vehicle_units": 3,
                        "transferable_surplus": 2,
                        "uncovered_need": 0,
                    },
                    {
                        "node_ref": "NODE-002",
                        "available_vehicle_units": 5,
                        "planning_vehicle_units": 4,
                        "transferable_surplus": 1,
                        "uncovered_need": 0,
                    },
                ]
            surplus = {node["node_ref"]: node["transferable_surplus"] for node in nodes}
            days.append(
                {
                    "horizon": horizon,
                    "date": (reference + timedelta(days=horizon)).isoformat(),
                    "nodes": nodes,
                    "lanes": [
                        {
                            "from_node_ref": "NODE-001",
                            "to_node_ref": "NODE-002",
                            "capacity": surplus["NODE-001"],
                            "distance_km": "87.400",
                            "unit_cost_centimes": 43700,
                        },
                        {
                            "from_node_ref": "NODE-002",
                            "to_node_ref": "NODE-001",
                            "capacity": surplus["NODE-002"],
                            "distance_km": "87.400",
                            "unit_cost_centimes": 43700,
                        },
                    ],
                }
            )
        return {
            "schema_version": "1.0.0",
            "source_kind": "rentfleet_operational",
            "run_id": str(uuid.uuid4()),
            "generated_at": datetime.now(timezone.utc).strftime("%Y-%m-%dT%H:%M:%SZ"),
            "reference_date": reference.isoformat(),
            "days": days,
        }

    def execute(self, request: dict) -> subprocess.CompletedProcess[str]:
        return subprocess.run(
            [sys.executable, str(SCRIPT)],
            cwd=ROOT,
            input=json.dumps(request, separators=(",", ":")),
            capture_output=True,
            text=True,
            check=False,
            timeout=30,
        )

    def test_executes_seven_independent_horizons_with_positive_transfers(self):
        request = self.request()
        completed = self.execute(request)
        self.assertEqual(0, completed.returncode, completed.stderr)
        payload = json.loads(completed.stdout)
        self.assertEqual("rentfleet_operational", payload["source_kind"])
        self.assertEqual("OPTIMAL", payload["solver_status"])
        self.assertEqual(list(range(1, 8)), [day["horizon"] for day in payload["days"]])
        for day in payload["days"]:
            self.assertEqual("OPTIMAL", day["solver_status"])
            self.assertEqual(0, day["unserved_need"])
            self.assertEqual(
                [
                    {
                        "from_node_ref": "NODE-001",
                        "to_node_ref": "NODE-002",
                        "vehicle_units": 3,
                        "distance_km": "87.400",
                        "unit_cost_centimes": 43700,
                    }
                ],
                day["recommendations"],
            )

    def test_optimal_zero_transfer_is_a_valid_result(self):
        completed = self.execute(self.request(transfer=False))
        self.assertEqual(0, completed.returncode, completed.stderr)
        payload = json.loads(completed.stdout)
        self.assertTrue(all(day["solver_status"] == "OPTIMAL" for day in payload["days"]))
        self.assertTrue(all(day["recommendations"] == [] for day in payload["days"]))
        self.assertTrue(all(day["unserved_need"] == 0 for day in payload["days"]))

    def test_rejects_malformed_capacity_and_unknown_business_identifier(self):
        request = self.request()
        request["days"][0]["lanes"][0]["capacity"] = 999
        request["tenant_id"] = 12
        completed = self.execute(request)
        self.assertEqual(2, completed.returncode)
        self.assertEqual({"error_code": "OPERATIONAL_REQUEST_INVALID"}, json.loads(completed.stderr))
        self.assertEqual("", completed.stdout)


if __name__ == "__main__":
    unittest.main()
