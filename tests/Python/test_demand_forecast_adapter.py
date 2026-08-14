from __future__ import annotations

import hashlib
import importlib.util
import json
import sys
import tempfile
import unittest
from pathlib import Path

import numpy as np
import pandas as pd


ROOT = Path(__file__).resolve().parents[2]
ADAPTER_PATH = ROOT / "scripts" / "intelligence" / "run_demand_forecast.py"
sys.dont_write_bytecode = True
SPEC = importlib.util.spec_from_file_location("rentfleet_demand_adapter", ADAPTER_PATH)
if SPEC is None or SPEC.loader is None:
    raise RuntimeError("Unable to load the demand forecasting adapter.")
ADAPTER = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(ADAPTER)


class ConstantPipeline:
    def __init__(self, value: float) -> None:
        self.value = value

    def predict(self, frame: pd.DataFrame) -> np.ndarray:
        lag_adjustment = 0.01 * float(frame["lag_1_at_cutoff"].iloc[0])
        return np.asarray([self.value + lag_adjustment])


class DemandForecastAdapterTest(unittest.TestCase):
    def test_snapshot_preprocessing_and_seven_horizon_payload_are_deterministic(self) -> None:
        with tempfile.TemporaryDirectory(prefix="rentfleet-demand-adapter-") as temporary:
            snapshot, manifest = self._snapshot(Path(temporary))
            frame = ADAPTER.load_snapshot(snapshot, manifest)
            payload = ADAPTER.make_payload(frame, manifest, self._bundle())

        self.assertEqual(35, len(frame))
        self.assertEqual(5, int(frame["observed_departures"].sum()))
        self.assertEqual(list(range(1, 8)), [row["horizon"] for row in payload["forecasts"]])
        self.assertTrue(all(len(row["explanations"]) == 3 for row in payload["forecasts"]))
        self.assertTrue(
            all(
                float(row["p05"])
                <= float(row["p50"])
                <= float(row["p90"])
                <= float(row["p95"])
                for row in payload["forecasts"]
            )
        )
        self.assertEqual("km", payload["dataset"]["distance_unit"])
        self.assertEqual("consultative_shadow", payload["safety"]["mode"])
        self.assertFalse(payload["safety"]["automatic_action_allowed"])
        self.assertEqual(
            payload["idempotency"]["canonical_payload_sha256"],
            ADAPTER.payload_digest(payload),
        )
        json.loads(ADAPTER.canonical_json(payload))

    def test_snapshot_rejects_non_kilometre_contract(self) -> None:
        with tempfile.TemporaryDirectory(prefix="rentfleet-demand-adapter-") as temporary:
            snapshot, manifest = self._snapshot(Path(temporary), distance_unit="miles")

            with self.assertRaisesRegex(ValueError, "Unexpected distance_unit value"):
                ADAPTER.load_snapshot(snapshot, manifest)

    def _snapshot(
        self,
        root: Path,
        *,
        distance_unit: str = "km",
    ) -> tuple[Path, dict[str, object]]:
        dates = pd.date_range("2026-07-08", periods=35, freq="D")
        frame = pd.DataFrame(
            {column: "" for column in ADAPTER.DATASET_COLUMNS},
            index=range(35),
        )
        frame["schema_version"] = ADAPTER.DATASET_SCHEMA_VERSION
        frame["dataset_version"] = ADAPTER.DATASET_VERSION
        frame["preprocessing_version"] = ADAPTER.PREPROCESSING_VERSION
        frame["series_id"] = "s_" + "1" * 64
        frame["tenant_key"] = "t_" + "2" * 64
        frame["agency_key"] = "a_" + "3" * 64
        frame["vehicle_category"] = "all"
        frame["date_local"] = dates.strftime("%Y-%m-%d")
        frame["observed_departures"] = ["1" if position % 7 == 0 else "0" for position in range(35)]
        frame["observation_available"] = "1"
        frame["timezone"] = ADAPTER.TIMEZONE
        frame["distance_unit"] = distance_unit

        snapshot = root / "snapshot.csv"
        frame.to_csv(
            snapshot,
            sep=";",
            index=False,
            encoding="utf-8-sig",
            lineterminator="\n",
        )
        digest = hashlib.sha256(snapshot.read_bytes()).hexdigest()
        manifest: dict[str, object] = {
            "manifest_version": "1.0.0",
            "run_id": "00000000-0000-4000-8000-000000000001",
            "snapshot": {
                "content_sha256": digest,
                "row_count": 35,
                "observed_departures_count": 5,
            },
            "period": {
                "date_from": "2026-07-08",
                "date_to": "2026-08-11",
            },
        }

        return snapshot, manifest

    def _bundle(self) -> dict[str, object]:
        features = [
            "series_id",
            "provider",
            "target_weekday",
            *(f"lag_{lag}_at_cutoff" for lag in ADAPTER.LAG_DAYS),
            "seasonal_lag_target_minus_7",
            *(f"rolling_mean_{window}_at_cutoff" for window in ADAPTER.ROLLING_WINDOWS),
            *(f"rolling_median_{window}_at_cutoff" for window in ADAPTER.ROLLING_WINDOWS),
            *(f"rolling_std_{window}_at_cutoff" for window in ADAPTER.ROLLING_WINDOWS),
            *(f"rolling_count_{window}_at_cutoff" for window in ADAPTER.ROLLING_WINDOWS),
            "target_is_weekend",
            "target_day_of_year_sin",
            "target_day_of_year_cos",
        ]

        return {
            "feature_columns": features,
            "point_models": {
                f"point_h{horizon}": ConstantPipeline(10 + horizon)
                for horizon in ADAPTER.HORIZONS
            },
            "quantile_models": {
                f"q{int(round(quantile * 100)):02d}_h{horizon}": ConstantPipeline(
                    5 + 10 * quantile + horizon
                )
                for horizon in ADAPTER.HORIZONS
                for quantile in ADAPTER.QUANTILES
            },
        }


if __name__ == "__main__":
    unittest.main()
