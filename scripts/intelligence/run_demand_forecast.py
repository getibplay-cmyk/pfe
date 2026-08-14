#!/usr/bin/env python3
"""Run the frozen demand model against one RentFleet history snapshot.

This adapter is intentionally limited to consultative shadow inference. It
does not retrain the model, evaluate local accuracy, or write to operational
tables. The generated JSON is accepted by the closed Laravel import contract.
"""

from __future__ import annotations

import argparse
import hashlib
import json
import math
import sys
import uuid
from datetime import datetime, timezone
from pathlib import Path
from typing import Any

import joblib
import numpy as np
import pandas as pd
import sklearn


DATASET_COLUMNS = [
    "schema_version",
    "dataset_version",
    "preprocessing_version",
    "series_id",
    "tenant_key",
    "agency_key",
    "vehicle_category",
    "date_local",
    "observed_departures",
    "observation_available",
    "timezone",
    "distance_unit",
]
DATASET_SCHEMA_VERSION = "1.0"
DATASET_VERSION = "rentfleet-demand-history-v1.0.0"
PREPROCESSING_VERSION = "rentfleet-demand-preprocessing-v1.0.0"
MODEL_NAME = "hgb_poisson::regularized"
MODEL_VERSION = "j5-v1"
MODEL_SHA256 = "992217b4887623ca924a3dc36686c69ab616634aace64cf993ad50b61ace6802"
FRAMEWORK_VERSION = "1.6.1"
JOBLIB_VERSION = "1.5.3"
NUMPY_VERSION = "2.0.2"
PANDAS_VERSION = "2.2.2"
TARGET = "observed_departures"
TIMEZONE = "Africa/Casablanca"
DISTANCE_UNIT = "km"
HORIZONS = tuple(range(1, 8))
QUANTILES = (0.05, 0.50, 0.90, 0.95)
LAG_DAYS = (1, 2, 3, 7, 14, 28)
ROLLING_WINDOWS = (7, 28)
PUBLIC_WAPE = "0.152342"
PUBLIC_MASE = "0.829556"
PUBLIC_INTERVAL_COVERAGE = "0.860700"
EXPLANATION_METHOD = "one_at_a_time_sensitivity_v1"
OPERATIONAL_EFFECT = "NO_OPERATIONAL_ACTION"
EXPLAINABLE_FEATURES = (
    "lag_1_at_cutoff",
    "lag_2_at_cutoff",
    "lag_3_at_cutoff",
    "lag_7_at_cutoff",
    "lag_14_at_cutoff",
    "lag_28_at_cutoff",
    "seasonal_lag_target_minus_7",
    "rolling_mean_7_at_cutoff",
    "rolling_mean_28_at_cutoff",
    "rolling_median_7_at_cutoff",
    "rolling_median_28_at_cutoff",
    "rolling_std_7_at_cutoff",
    "rolling_std_28_at_cutoff",
    "target_is_weekend",
)


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--snapshot", type=Path, required=True)
    parser.add_argument("--manifest", type=Path, required=True)
    parser.add_argument("--model-bundle", type=Path, required=True)
    parser.add_argument("--output", type=Path, required=True)
    return parser.parse_args()


def sha256(path: Path) -> str:
    digest = hashlib.sha256()
    with path.open("rb") as stream:
        for block in iter(lambda: stream.read(1024 * 1024), b""):
            digest.update(block)
    return digest.hexdigest()


def require(condition: bool, message: str) -> None:
    if not condition:
        raise ValueError(message)


def load_manifest(path: Path) -> dict[str, Any]:
    payload = json.loads(path.read_text(encoding="utf-8"))
    require(isinstance(payload, dict), "The manifest root must be an object.")
    require(payload.get("manifest_version") == "1.0.0", "Unexpected manifest version.")
    return payload


def load_snapshot(path: Path, manifest: dict[str, Any]) -> pd.DataFrame:
    expected_digest = manifest.get("snapshot", {}).get("content_sha256")
    require(isinstance(expected_digest, str), "Manifest snapshot digest is missing.")
    require(sha256(path) == expected_digest, "Snapshot SHA-256 does not match the manifest.")

    frame = pd.read_csv(path, sep=";", encoding="utf-8-sig", dtype=str, keep_default_na=False)
    require(list(frame.columns) == DATASET_COLUMNS, "Snapshot columns or order are invalid.")
    require(35 <= len(frame) <= 731, "Snapshot must contain between 35 and 731 days.")
    require(len(frame) == manifest.get("snapshot", {}).get("row_count"), "Snapshot row count mismatch.")

    constants = {
        "schema_version": DATASET_SCHEMA_VERSION,
        "dataset_version": DATASET_VERSION,
        "preprocessing_version": PREPROCESSING_VERSION,
        "vehicle_category": "all",
        "observation_available": "1",
        "timezone": TIMEZONE,
        "distance_unit": DISTANCE_UNIT,
    }
    for column, expected in constants.items():
        require(frame[column].eq(expected).all(), f"Unexpected {column} value.")
    for column in ("series_id", "tenant_key", "agency_key"):
        require(frame[column].nunique() == 1, f"Snapshot must contain one {column}.")

    frame["date_local"] = pd.to_datetime(frame["date_local"], format="%Y-%m-%d", errors="raise")
    require(frame["date_local"].is_monotonic_increasing, "Dates must be ordered.")
    require(not frame["date_local"].duplicated().any(), "Dates must be unique.")
    expected_dates = pd.date_range(frame["date_local"].min(), frame["date_local"].max(), freq="D")
    require(frame["date_local"].tolist() == expected_dates.tolist(), "Missing dates were not zero-filled.")
    require(
        frame["date_local"].min().strftime("%Y-%m-%d") == manifest.get("period", {}).get("date_from")
        and frame["date_local"].max().strftime("%Y-%m-%d") == manifest.get("period", {}).get("date_to"),
        "Snapshot dates do not match the manifest.",
    )

    departures = pd.to_numeric(frame["observed_departures"], errors="raise", downcast="integer")
    require(np.isfinite(departures).all(), "Departures must be finite integers.")
    require((departures >= 0).all(), "Departures cannot be negative.")
    require(
        departures.astype(str).eq(frame["observed_departures"]).all(),
        "Departures must use canonical non-negative integer notation.",
    )
    departure_total = int(departures.sum())
    require(departure_total > 0, "Snapshot contains no observed departure; shadow inference is not informative.")
    require(
        departure_total == manifest.get("snapshot", {}).get("observed_departures_count"),
        "Observed departure total does not match the manifest.",
    )
    frame["observed_departures"] = departures.astype(float)
    return frame


def load_bundle(path: Path) -> dict[str, Any]:
    require(sys.version_info[:2] == (3, 12), "Python 3.12 is required.")
    require(np.__version__ == NUMPY_VERSION, f"numpy {NUMPY_VERSION} is required.")
    require(pd.__version__ == PANDAS_VERSION, f"pandas {PANDAS_VERSION} is required.")
    require(sklearn.__version__ == FRAMEWORK_VERSION, f"scikit-learn {FRAMEWORK_VERSION} is required.")
    require(joblib.__version__ == JOBLIB_VERSION, f"joblib {JOBLIB_VERSION} is required.")
    require(sha256(path) == MODEL_SHA256, "Model artifact SHA-256 is not the frozen J5 digest.")
    bundle = joblib.load(path)
    require(isinstance(bundle, dict), "Model bundle root must be a mapping.")
    require(bundle.get("module") == "demand_forecast_munich", "Unexpected model module.")
    require(bundle.get("version") == MODEL_VERSION, "Unexpected model version.")
    require(bundle.get("model_name") == MODEL_NAME, "Unexpected model name.")
    require(bundle.get("horizons") == list(HORIZONS), "Unexpected model horizons.")
    require(bundle.get("quantiles") == list(QUANTILES), "Unexpected model quantiles.")
    require(bundle.get("semantics") == "observed_departures_not_total_demand", "Unexpected target semantics.")
    require(bundle.get("ready_for_saas") is False, "Only the frozen shadow artifact is accepted.")
    require(isinstance(bundle.get("point_models"), dict), "Point models are missing.")
    require(isinstance(bundle.get("quantile_models"), dict), "Quantile models are missing.")
    return bundle


def make_feature_row(frame: pd.DataFrame, horizon: int) -> pd.DataFrame:
    cutoff = frame["date_local"].max()
    target = cutoff + pd.Timedelta(days=horizon)
    history = frame.set_index("date_local")["observed_departures"].sort_index()
    row: dict[str, Any] = {
        "series_id": frame["series_id"].iloc[0],
        "provider": frame["tenant_key"].iloc[0],
        "target_weekday": str(target.weekday()),
    }
    for lag in LAG_DAYS:
        row[f"lag_{lag}_at_cutoff"] = float(history.loc[cutoff - pd.Timedelta(days=lag - 1)])
    row["seasonal_lag_target_minus_7"] = float(history.loc[target - pd.Timedelta(days=7)])
    for window in ROLLING_WINDOWS:
        values = history.loc[cutoff - pd.Timedelta(days=window - 1) : cutoff].to_numpy(dtype=float)
        require(len(values) == window, f"The {window}-day rolling window is incomplete.")
        row[f"rolling_mean_{window}_at_cutoff"] = float(np.mean(values))
        row[f"rolling_median_{window}_at_cutoff"] = float(np.median(values))
        row[f"rolling_std_{window}_at_cutoff"] = float(np.std(values, ddof=0))
        row[f"rolling_count_{window}_at_cutoff"] = float(len(values))
    row["target_is_weekend"] = int(target.weekday() >= 5)
    day_of_year = float(target.dayofyear)
    row["target_day_of_year_sin"] = math.sin(2.0 * math.pi * day_of_year / 365.25)
    row["target_day_of_year_cos"] = math.cos(2.0 * math.pi * day_of_year / 365.25)
    return pd.DataFrame([row])


def predict_non_negative(pipeline: Any, row: pd.DataFrame) -> float:
    value = float(np.asarray(pipeline.predict(row), dtype=float)[0])
    require(math.isfinite(value), "Model returned a non-finite value.")
    return max(0.0, value)


def neutral_value(row: pd.DataFrame, feature: str) -> float:
    if feature.startswith("rolling_std_"):
        return 0.0
    if feature == "target_is_weekend":
        return 0.0
    return float(row["rolling_median_28_at_cutoff"].iloc[0])


def local_explanations(pipeline: Any, row: pd.DataFrame, prediction: float) -> list[dict[str, str]]:
    candidates: list[tuple[str, float]] = []
    for feature in EXPLAINABLE_FEATURES:
        perturbed = row.copy()
        perturbed.loc[0, feature] = neutral_value(row, feature)
        delta = prediction - predict_non_negative(pipeline, perturbed)
        candidates.append((feature, delta))
    candidates.sort(key=lambda item: (-abs(item[1]), item[0]))

    explanations = []
    for feature, delta in candidates[:3]:
        rounded = round(delta, 6)
        if abs(rounded) < 0.0000005:
            rounded = 0.0
        direction = "increase" if rounded > 0 else ("decrease" if rounded < 0 else "neutral")
        explanations.append(
            {
                "feature": feature,
                "direction": direction,
                "prediction_delta": f"{rounded:.6f}",
            }
        )
    return explanations


def decimal(value: float) -> str:
    rounded = max(0.0, round(float(value), 6))
    require(rounded < 100_000_000, "Forecast exceeds the contract numeric range.")
    return f"{rounded:.6f}"


def canonical_json(payload: dict[str, Any]) -> str:
    return json.dumps(payload, sort_keys=True, ensure_ascii=False, separators=(",", ":"))


def payload_digest(payload: dict[str, Any]) -> str:
    digestable = json.loads(canonical_json(payload))
    digestable["idempotency"].pop("canonical_payload_sha256", None)
    return hashlib.sha256(canonical_json(digestable).encode("utf-8")).hexdigest()


def make_payload(
    frame: pd.DataFrame,
    manifest: dict[str, Any],
    bundle: dict[str, Any],
) -> dict[str, Any]:
    forecasts = []
    for horizon in HORIZONS:
        feature_row = make_feature_row(frame, horizon)
        required_features = bundle.get("feature_columns")
        require(isinstance(required_features, list), "Model feature registry is missing.")
        require(set(required_features).issubset(feature_row.columns), "A frozen model feature is missing.")
        model_input = feature_row[required_features]
        point_pipeline = bundle["point_models"].get(f"point_h{horizon}")
        require(point_pipeline is not None, f"Point model H{horizon} is missing.")
        mean = predict_non_negative(point_pipeline, model_input)

        raw_quantiles = []
        for quantile in QUANTILES:
            quantile_id = int(round(quantile * 100))
            pipeline = bundle["quantile_models"].get(f"q{quantile_id:02d}_h{horizon}")
            require(pipeline is not None, f"Quantile model Q{quantile_id} H{horizon} is missing.")
            raw_quantiles.append(predict_non_negative(pipeline, model_input))
        sorted_quantiles = sorted(raw_quantiles)
        crossing = any(right < left for left, right in zip(raw_quantiles, raw_quantiles[1:]))
        adjusted = not np.allclose(raw_quantiles, sorted_quantiles, rtol=0.0, atol=1e-12)
        target_date = frame["date_local"].max() + pd.Timedelta(days=horizon)
        forecasts.append(
            {
                "target_date": target_date.strftime("%Y-%m-%d"),
                "horizon": horizon,
                "vehicle_category": "all",
                "conditional_mean": decimal(mean),
                "p05": decimal(sorted_quantiles[0]),
                "p50": decimal(sorted_quantiles[1]),
                "p90": decimal(sorted_quantiles[2]),
                "p95": decimal(sorted_quantiles[3]),
                "raw_any_crossing": bool(crossing),
                "monotone_adjusted": bool(adjusted),
                "explanations": local_explanations(point_pipeline, model_input, mean),
                "demand_semantics": TARGET,
                "operational_effect": OPERATIONAL_EFFECT,
            }
        )

    batch_id = str(uuid.uuid4())
    idempotency_key = str(uuid.uuid4())
    payload: dict[str, Any] = {
        "schema_version": "1.0.0",
        "batch_id": batch_id,
        "generated_at": datetime.now(timezone.utc).replace(microsecond=0).strftime("%Y-%m-%dT%H:%M:%SZ"),
        "model": {
            "name": MODEL_NAME,
            "version": MODEL_VERSION,
            "artifact_sha256": MODEL_SHA256,
            "framework": "scikit-learn",
            "framework_version": FRAMEWORK_VERSION,
            "compute": "cpu",
            "explanation_method": EXPLANATION_METHOD,
        },
        "dataset": {
            "run_id": manifest["run_id"],
            "schema_version": DATASET_SCHEMA_VERSION,
            "dataset_version": DATASET_VERSION,
            "preprocessing_version": PREPROCESSING_VERSION,
            "content_sha256": manifest["snapshot"]["content_sha256"],
            "row_count": int(manifest["snapshot"]["row_count"]),
            "date_from": manifest["period"]["date_from"],
            "date_to": manifest["period"]["date_to"],
            "timezone": TIMEZONE,
            "distance_unit": DISTANCE_UNIT,
            "target": TARGET,
            "vehicle_category": "all",
            "missing_dates": "zero_filled",
        },
        "evaluation": {
            "validation_scope": "public_proxy_only_local_shadow",
            "public_wape": PUBLIC_WAPE,
            "public_mase": PUBLIC_MASE,
            "public_interval_coverage_p05_p95": PUBLIC_INTERVAL_COVERAGE,
            "local_holdout_status": "not_available_pending_real_history",
            "local_wape": None,
            "local_mase": None,
            "local_interval_coverage_p05_p95": None,
            "production_claim_allowed": False,
        },
        "forecasts": forecasts,
        "safety": {
            "mode": "consultative_shadow",
            "human_decision_required": True,
            "automatic_action_allowed": False,
            "operational_table_write_allowed": False,
            "ready_for_production": False,
            "operational_effect": OPERATIONAL_EFFECT,
        },
        "idempotency": {
            "key": idempotency_key,
            "policy": "SAME_KEY_SAME_PAYLOAD_ONLY",
            "canonical_payload_sha256": "",
        },
    }
    payload["idempotency"]["canonical_payload_sha256"] = payload_digest(payload)
    return payload


def main() -> int:
    args = parse_args()
    try:
        manifest = load_manifest(args.manifest)
        frame = load_snapshot(args.snapshot, manifest)
        bundle = load_bundle(args.model_bundle)
        payload = make_payload(frame, manifest, bundle)
        args.output.parent.mkdir(parents=True, exist_ok=True)
        args.output.write_text(canonical_json(payload) + "\n", encoding="utf-8")
    except (OSError, ValueError, KeyError, TypeError, json.JSONDecodeError) as exception:
        print(f"Demand forecast failed: {exception}", file=sys.stderr)
        return 1

    print(f"Consultative forecast written to {args.output}")
    print("Local accuracy remains unavailable; no production claim is allowed.")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
