from __future__ import annotations

import os
import tempfile
import unittest
from pathlib import Path

from tests.Python import test_demand_forecast_adapter as adapter_tests


ADAPTER = adapter_tests.ADAPTER
MODEL_PATH = Path(os.environ.get("DEMAND_FORECAST_MODEL_PATH", ""))
MODEL_AVAILABLE = bool(str(MODEL_PATH)) and MODEL_PATH.is_file()


@unittest.skipUnless(
    MODEL_AVAILABLE,
    "The private authentic J5 artifact is not installed in this environment.",
)
class AuthenticDemandForecastArtifactTest(unittest.TestCase):
    def test_exact_j5_bundle_executes_all_seven_horizons(self) -> None:
        self.assertEqual(ADAPTER.MODEL_SHA256, ADAPTER.sha256(MODEL_PATH))
        self.assertEqual(6_401_204, MODEL_PATH.stat().st_size)

        with tempfile.TemporaryDirectory(prefix="rentfleet-authentic-hgb-") as temporary:
            snapshot, manifest = adapter_tests.DemandForecastAdapterTest()._snapshot(
                Path(temporary)
            )
            frame = ADAPTER.load_snapshot(snapshot, manifest)
            bundle = ADAPTER.load_bundle(MODEL_PATH)
            payload = ADAPTER.make_payload(frame, manifest, bundle)

        self.assertEqual(list(range(1, 8)), [row["horizon"] for row in payload["forecasts"]])
        self.assertTrue(
            all(
                float(row["p05"])
                <= float(row["p50"])
                <= float(row["p90"])
                <= float(row["p95"])
                for row in payload["forecasts"]
            )
        )
        self.assertEqual("NO_OPERATIONAL_ACTION", payload["safety"]["operational_effect"])
        self.assertFalse(payload["safety"]["ready_for_production"])


if __name__ == "__main__":
    unittest.main()
