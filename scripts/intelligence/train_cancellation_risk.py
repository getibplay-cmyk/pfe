#!/usr/bin/env python3
"""Qualify a consultative CatBoost cancellation/no-show risk model.

The script is intentionally independent from Laravel. It consumes the pinned
public Hotel Booking Demand snapshot, applies the frozen preprocessing and
chronological protocol, writes research evidence, and never performs an
operational RentFleet write.
"""

from __future__ import annotations

import argparse
import csv
import hashlib
import json
import os
import platform
from dataclasses import dataclass
from pathlib import Path
from typing import Any, Iterable

import joblib
import matplotlib
import numpy as np
import pandas as pd
import sklearn
from catboost import CatBoostClassifier, Pool, __version__ as catboost_version
from matplotlib import pyplot as plt
from sklearn.calibration import calibration_curve
from sklearn.isotonic import IsotonicRegression
from sklearn.metrics import (
    average_precision_score,
    balanced_accuracy_score,
    brier_score_loss,
    classification_report,
    confusion_matrix,
    f1_score,
    log_loss,
    roc_auc_score,
)


SEED = 20_260_814
PROTOCOL_VERSION = "cancellation-risk-qualification-v1.0.0"
PREPROCESSING_VERSION = "hotel-booking-to-rentfleet-v1.0.0"
MODEL_NAME = "cancellation_risk_catboost"
MODEL_VERSION = "research-1.0.0"
DATASET_SHA256 = "7c2ae42a7353905ea136e5c2287f17c92c5435826598bfbb8491c6f0c7b1fc06"
DATASET_ROWS = 119_390
DATASET_URL = (
    "https://raw.githubusercontent.com/rfordatascience/tidytuesday/"
    "1f5a20eae51d871ec4ac0f95d16e43b9ba3f1dec/"
    "data/2020/2020-02-11/hotels.csv"
)
DATASET_DOI = "https://doi.org/10.1016/j.dib.2018.11.126"
DATASET_LICENSE = "CC BY 4.0"
DATASET_LICENSE_URL = "https://creativecommons.org/licenses/by/4.0/"
GATE_BALANCED_ACCURACY = 0.80
GATE_MACRO_F1 = 0.80
SPLIT_NAMES = ("train", "validation", "calibration", "threshold", "test")
SPLIT_FRACTIONS = (0.55, 0.15, 0.10, 0.10, 0.10)

MONTHS = {
    month: index
    for index, month in enumerate(
        (
            "January",
            "February",
            "March",
            "April",
            "May",
            "June",
            "July",
            "August",
            "September",
            "October",
            "November",
            "December",
        ),
        start=1,
    )
}

# These fields contain the outcome itself or information accumulated after the
# prediction cutoff. They are never accepted as model inputs.
FORBIDDEN_LEAKAGE_COLUMNS = (
    "is_canceled",
    "reservation_status",
    "reservation_status_date",
    "assigned_room_type",
    "booking_changes",
    "days_in_waiting_list",
)

CATEGORICAL_FEATURES = (
    "agency_proxy",
    "vehicle_category_proxy",
    "deposit_required",
    "arrival_month",
    "arrival_weekday",
    "booking_month",
    "booking_weekday",
)

NUMERIC_FEATURES = (
    "lead_time_days",
    "weekend_nights",
    "weekday_nights",
    "rental_nights",
    "returning_customer",
    "prior_cancellations",
    "prior_completed_bookings",
    "has_options",
)

FEATURES = CATEGORICAL_FEATURES + NUMERIC_FEATURES

FEATURE_MAPPING = (
    ("agency_proxy", "hotel", "agency_key", "proxy", "Tenant/agency key must be HMAC-pseudonymized server-side."),
    ("vehicle_category_proxy", "reserved_room_type", "vehicle_category_key", "direct_semantics", "Category key only; never expose a database identifier."),
    ("deposit_required", "deposit_type", "deposit_amount > 0", "partial", "Public refundability is collapsed to the local deposit-presence flag."),
    ("arrival_month", "arrival_date", "starts_at month", "derived", "Derived in Africa/Casablanca at the scoring cutoff."),
    ("arrival_weekday", "arrival_date", "starts_at weekday", "derived", "Derived in Africa/Casablanca at the scoring cutoff."),
    ("booking_month", "booking_date", "created_at month", "derived", "Derived in Africa/Casablanca."),
    ("booking_weekday", "booking_date", "created_at weekday", "derived", "Derived in Africa/Casablanca."),
    ("lead_time_days", "lead_time", "starts_at - created_at", "direct_semantics", "Non-negative whole-day floor at the scoring cutoff."),
    ("weekend_nights", "stays_in_weekend_nights", "weekend nights in [starts_at, ends_at)", "derived", "Calculated from the semi-open local rental period."),
    ("weekday_nights", "stays_in_week_nights", "weekday nights in [starts_at, ends_at)", "derived", "Calculated from the semi-open local rental period."),
    ("rental_nights", "sum of stay nights", "billed_days", "direct_semantics", "Positive duration only."),
    ("returning_customer", "is_repeated_guest", "prior completed contracts > 0", "derived", "Use only events strictly before the scoring cutoff."),
    ("prior_cancellations", "previous_cancellations", "prior cancelled reservations", "derived", "Use only events strictly before the scoring cutoff."),
    ("prior_completed_bookings", "previous_bookings_not_canceled", "prior completed contracts", "derived", "Use only events strictly before the scoring cutoff."),
    ("has_options", "total_of_special_requests > 0", "options_total > 0", "partial", "Presence only; public request count is not transferred."),
)


@dataclass(frozen=True)
class PreparedDataset:
    frame: pd.DataFrame
    audit: dict[str, Any]


def sha256_file(path: Path) -> str:
    digest = hashlib.sha256()
    with path.open("rb") as handle:
        for chunk in iter(lambda: handle.read(1024 * 1024), b""):
            digest.update(chunk)
    return digest.hexdigest()


def canonical_json(payload: Any) -> str:
    return json.dumps(
        payload,
        ensure_ascii=False,
        sort_keys=True,
        separators=(",", ":"),
        allow_nan=False,
    ) + "\n"


def write_json(path: Path, payload: Any) -> None:
    path.write_text(canonical_json(payload), encoding="utf-8")


def expected_columns() -> set[str]:
    return {
        "hotel",
        "is_canceled",
        "lead_time",
        "arrival_date_year",
        "arrival_date_month",
        "arrival_date_week_number",
        "arrival_date_day_of_month",
        "stays_in_weekend_nights",
        "stays_in_week_nights",
        "adults",
        "children",
        "babies",
        "meal",
        "country",
        "market_segment",
        "distribution_channel",
        "is_repeated_guest",
        "previous_cancellations",
        "previous_bookings_not_canceled",
        "reserved_room_type",
        "assigned_room_type",
        "booking_changes",
        "deposit_type",
        "agent",
        "company",
        "days_in_waiting_list",
        "customer_type",
        "adr",
        "required_car_parking_spaces",
        "total_of_special_requests",
        "reservation_status",
        "reservation_status_date",
    }


def load_and_prepare(dataset_path: Path, expected_sha256: str = DATASET_SHA256) -> PreparedDataset:
    actual_sha256 = sha256_file(dataset_path)
    if actual_sha256 != expected_sha256:
        raise ValueError(f"Dataset SHA-256 mismatch: expected {expected_sha256}, got {actual_sha256}")

    raw = pd.read_csv(dataset_path, low_memory=False)
    if len(raw) != DATASET_ROWS:
        raise ValueError(f"Unexpected dataset row count: {len(raw)}")
    if set(raw.columns) != expected_columns():
        missing = sorted(expected_columns() - set(raw.columns))
        unknown = sorted(set(raw.columns) - expected_columns())
        raise ValueError(f"Unexpected dataset schema; missing={missing}, unknown={unknown}")

    arrival_month = raw["arrival_date_month"].map(MONTHS)
    if arrival_month.isna().any():
        raise ValueError("Unknown arrival month label")

    arrival_date = pd.to_datetime(
        {
            "year": pd.to_numeric(raw["arrival_date_year"], errors="raise"),
            "month": arrival_month,
            "day": pd.to_numeric(raw["arrival_date_day_of_month"], errors="raise"),
        },
        errors="raise",
    )
    lead_time = pd.to_numeric(raw["lead_time"], errors="raise").astype("int64")
    if (lead_time < 0).any():
        raise ValueError("Negative lead_time is forbidden")
    booking_date = arrival_date - pd.to_timedelta(lead_time, unit="D")

    weekend_nights = pd.to_numeric(raw["stays_in_weekend_nights"], errors="raise").astype("int64")
    weekday_nights = pd.to_numeric(raw["stays_in_week_nights"], errors="raise").astype("int64")
    rental_nights = weekend_nights + weekday_nights
    compatible = rental_nights > 0

    target = (
        pd.to_numeric(raw["is_canceled"], errors="raise").astype("int64").eq(1)
        | raw["reservation_status"].astype(str).eq("No-Show")
    ).astype("int64")

    frame = pd.DataFrame(
        {
            "source_row": np.arange(len(raw), dtype="int64"),
            "arrival_date": arrival_date,
            "booking_date": booking_date,
            "target": target,
            "agency_proxy": raw["hotel"].fillna("__missing__").astype(str),
            "vehicle_category_proxy": raw["reserved_room_type"].fillna("__missing__").astype(str),
            "deposit_required": raw["deposit_type"].fillna("__missing__").astype(str).ne("No Deposit").map({True: "yes", False: "no"}),
            "arrival_month": arrival_date.dt.month.astype(str),
            "arrival_weekday": arrival_date.dt.dayofweek.astype(str),
            "booking_month": booking_date.dt.month.astype(str),
            "booking_weekday": booking_date.dt.dayofweek.astype(str),
            "lead_time_days": lead_time,
            "weekend_nights": weekend_nights,
            "weekday_nights": weekday_nights,
            "rental_nights": rental_nights,
            "returning_customer": pd.to_numeric(raw["is_repeated_guest"], errors="raise").astype("int64"),
            "prior_cancellations": pd.to_numeric(raw["previous_cancellations"], errors="raise").astype("int64"),
            "prior_completed_bookings": pd.to_numeric(raw["previous_bookings_not_canceled"], errors="raise").astype("int64"),
            "has_options": pd.to_numeric(raw["total_of_special_requests"], errors="raise").gt(0).astype("int64"),
        }
    )
    frame = frame.loc[compatible].copy()
    frame = frame.sort_values(["arrival_date", "booking_date", "source_row"], kind="mergesort").reset_index(drop=True)

    if any(column in FEATURES for column in FORBIDDEN_LEAKAGE_COLUMNS):
        raise AssertionError("A forbidden leakage column entered the feature contract")
    if frame[list(FEATURES)].isna().any().any():
        raise ValueError("Prepared features contain missing values")

    audit = {
        "source_rows": int(len(raw)),
        "retained_rows": int(len(frame)),
        "excluded_zero_night_rows": int((~compatible).sum()),
        "positive_rows": int(frame["target"].sum()),
        "positive_rate": round(float(frame["target"].mean()), 6),
        "minimum_arrival_date": frame["arrival_date"].min().date().isoformat(),
        "maximum_arrival_date": frame["arrival_date"].max().date().isoformat(),
        "minimum_booking_date": frame["booking_date"].min().date().isoformat(),
        "maximum_booking_date": frame["booking_date"].max().date().isoformat(),
        "raw_exact_duplicate_rows": int(raw.duplicated(keep=False).sum()),
        "duplicate_policy": "retained_because_public_source_has_no_booking_identifier",
    }
    return PreparedDataset(frame=frame, audit=audit)


def chronological_split(frame: pd.DataFrame) -> tuple[pd.Series, dict[str, Any]]:
    unique_dates = pd.Series(frame["arrival_date"].dt.normalize().unique()).sort_values().reset_index(drop=True)
    if len(unique_dates) < 100:
        raise ValueError("At least 100 unique arrival dates are required")

    cumulative = np.cumsum(SPLIT_FRACTIONS)[:-1]
    boundaries = [
        pd.Timestamp(unique_dates.iloc[min(len(unique_dates) - 1, int(len(unique_dates) * fraction))])
        for fraction in cumulative
    ]
    split = pd.Series(
        np.select(
            [
                frame["arrival_date"].lt(boundaries[0]),
                frame["arrival_date"].lt(boundaries[1]),
                frame["arrival_date"].lt(boundaries[2]),
                frame["arrival_date"].lt(boundaries[3]),
            ],
            SPLIT_NAMES[:-1],
            default=SPLIT_NAMES[-1],
        ),
        index=frame.index,
        name="split",
    )

    details: dict[str, Any] = {
        "strategy": "five_contiguous_arrival_date_blocks",
        "fractions": {name: fraction for name, fraction in zip(SPLIT_NAMES, SPLIT_FRACTIONS, strict=True)},
        "boundaries_starting_new_block": [date.date().isoformat() for date in boundaries],
        "blocks": {},
    }
    previous_end: pd.Timestamp | None = None
    for name in SPLIT_NAMES:
        mask = split.eq(name)
        if not mask.any():
            raise ValueError(f"Empty chronological split: {name}")
        start = frame.loc[mask, "arrival_date"].min()
        end = frame.loc[mask, "arrival_date"].max()
        if previous_end is not None and start <= previous_end:
            raise AssertionError("Chronological blocks overlap")
        previous_end = end
        details["blocks"][name] = {
            "rows": int(mask.sum()),
            "date_from": start.date().isoformat(),
            "date_to": end.date().isoformat(),
            "positive_rows": int(frame.loc[mask, "target"].sum()),
            "positive_rate": round(float(frame.loc[mask, "target"].mean()), 6),
        }
    return split, details


def expected_calibration_error(y_true: np.ndarray, probabilities: np.ndarray, bins: int = 10) -> float:
    edges = np.linspace(0.0, 1.0, bins + 1)
    indices = np.clip(np.digitize(probabilities, edges, right=True) - 1, 0, bins - 1)
    total = len(y_true)
    error = 0.0
    for index in range(bins):
        mask = indices == index
        if not mask.any():
            continue
        error += (mask.sum() / total) * abs(float(probabilities[mask].mean()) - float(y_true[mask].mean()))
    return float(error)


def choose_threshold(y_true: np.ndarray, probabilities: np.ndarray) -> dict[str, float]:
    best: tuple[tuple[float, float, float], float, float, float] | None = None
    for threshold in np.linspace(0.05, 0.95, 901):
        predicted = probabilities >= threshold
        balanced = balanced_accuracy_score(y_true, predicted)
        macro_f1 = f1_score(y_true, predicted, average="macro", zero_division=0)
        key = (min(balanced, macro_f1), (balanced + macro_f1) / 2.0, -abs(float(threshold) - 0.5))
        if best is None or key > best[0]:
            best = (key, float(threshold), float(balanced), float(macro_f1))
    if best is None:
        raise AssertionError("Threshold search produced no candidate")
    return {
        "threshold": round(best[1], 6),
        "balanced_accuracy": round(best[2], 6),
        "macro_f1": round(best[3], 6),
    }


def evaluate(y_true: np.ndarray, probabilities: np.ndarray, threshold: float) -> dict[str, Any]:
    predicted = (probabilities >= threshold).astype("int64")
    report = classification_report(
        y_true,
        predicted,
        labels=[0, 1],
        target_names=["arrival", "cancellation_or_no_show"],
        output_dict=True,
        zero_division=0,
    )
    matrix = confusion_matrix(y_true, predicted, labels=[0, 1])
    return {
        "balanced_accuracy": round(float(balanced_accuracy_score(y_true, predicted)), 6),
        "macro_f1": round(float(f1_score(y_true, predicted, average="macro", zero_division=0)), 6),
        "pr_auc": round(float(average_precision_score(y_true, probabilities)), 6),
        "roc_auc": round(float(roc_auc_score(y_true, probabilities)), 6),
        "brier_loss": round(float(brier_score_loss(y_true, probabilities)), 6),
        "log_loss": round(float(log_loss(y_true, probabilities, labels=[0, 1])), 6),
        "ece_10": round(expected_calibration_error(y_true, probabilities, bins=10), 6),
        "threshold": round(float(threshold), 6),
        "confusion_matrix": {
            "true_arrival_pred_arrival": int(matrix[0, 0]),
            "true_arrival_pred_risk": int(matrix[0, 1]),
            "true_risk_pred_arrival": int(matrix[1, 0]),
            "true_risk_pred_risk": int(matrix[1, 1]),
        },
        "per_class": {
            label: {
                key: round(float(values[key]), 6) if key != "support" else int(values[key])
                for key in ("precision", "recall", "f1-score", "support")
            }
            for label, values in (
                ("arrival", report["arrival"]),
                ("cancellation_or_no_show", report["cancellation_or_no_show"]),
            )
        },
    }


def model_parameters(iterations: int) -> dict[str, Any]:
    return {
        "iterations": iterations,
        "depth": 8,
        "learning_rate": 0.04,
        "l2_leaf_reg": 8.0,
        "loss_function": "Logloss",
        "eval_metric": "AUC",
        "auto_class_weights": "Balanced",
        "random_seed": SEED,
        "random_strength": 0.5,
        "bootstrap_type": "Bernoulli",
        "subsample": 0.85,
        "thread_count": 1,
        "allow_writing_files": False,
        "verbose": False,
    }


def train_and_evaluate(prepared: PreparedDataset, output: Path) -> dict[str, Any]:
    if output.exists() and any(output.iterdir()):
        raise ValueError(f"Output directory must be absent or empty: {output}")
    frame = prepared.frame.copy()
    split, split_details = chronological_split(frame)
    frame["split"] = split
    indices = {name: np.flatnonzero(split.eq(name).to_numpy()) for name in SPLIT_NAMES}
    features = frame.loc[:, FEATURES]
    target = frame["target"].astype("int64")

    development_model = CatBoostClassifier(**model_parameters(1800))
    development_model.fit(
        Pool(features.iloc[indices["train"]], target.iloc[indices["train"]], cat_features=list(CATEGORICAL_FEATURES)),
        eval_set=Pool(
            features.iloc[indices["validation"]],
            target.iloc[indices["validation"]],
            cat_features=list(CATEGORICAL_FEATURES),
        ),
        early_stopping_rounds=150,
        use_best_model=True,
        verbose=False,
    )
    selected_iterations = max(1, int(development_model.get_best_iteration()) + 1)

    training_indices = np.concatenate([indices["train"], indices["validation"]])
    final_model = CatBoostClassifier(**model_parameters(selected_iterations))
    final_model.fit(
        Pool(
            features.iloc[training_indices],
            target.iloc[training_indices],
            cat_features=list(CATEGORICAL_FEATURES),
        ),
        verbose=False,
    )

    def raw_probabilities(name: str) -> np.ndarray:
        return final_model.predict_proba(
            Pool(features.iloc[indices[name]], cat_features=list(CATEGORICAL_FEATURES))
        )[:, 1]

    calibration_target = target.iloc[indices["calibration"]].to_numpy()
    calibrator = IsotonicRegression(out_of_bounds="clip", y_min=0.0, y_max=1.0)
    calibrator.fit(raw_probabilities("calibration"), calibration_target)

    threshold_probabilities = calibrator.predict(raw_probabilities("threshold"))
    threshold_target = target.iloc[indices["threshold"]].to_numpy()
    threshold_selection = choose_threshold(threshold_target, threshold_probabilities)

    test_target = target.iloc[indices["test"]].to_numpy()
    test_raw = raw_probabilities("test")
    test_calibrated = calibrator.predict(test_raw)
    metrics = evaluate(test_target, test_calibrated, threshold_selection["threshold"])
    metrics["uncalibrated_brier_loss"] = round(float(brier_score_loss(test_target, test_raw)), 6)
    metrics["calibration_brier_delta"] = round(
        metrics["uncalibrated_brier_loss"] - metrics["brier_loss"], 6
    )

    always_arrival = np.zeros_like(test_target)
    metrics["always_arrival_baseline"] = {
        "balanced_accuracy": round(float(balanced_accuracy_score(test_target, always_arrival)), 6),
        "macro_f1": round(float(f1_score(test_target, always_arrival, average="macro", zero_division=0)), 6),
    }

    gate_passed = (
        metrics["balanced_accuracy"] >= GATE_BALANCED_ACCURACY
        and metrics["macro_f1"] >= GATE_MACRO_F1
    )
    gate = {
        "balanced_accuracy_minimum": GATE_BALANCED_ACCURACY,
        "macro_f1_minimum": GATE_MACRO_F1,
        "passed": gate_passed,
        "decision": (
            "PUBLIC_PROXY_GATE_PASSED_PENDING_LOCAL_VALIDATION"
            if gate_passed
            else "RESEARCH_GATE_NOT_PASSED_NO_SAAS_INTEGRATION"
        ),
    }

    output.mkdir(parents=True, exist_ok=True)
    raw_model_path = output / ".cancellation-risk-catboost.raw.json"
    final_model.save_model(raw_model_path, format="json")
    normalized_model = json.loads(raw_model_path.read_text(encoding="utf-8"))
    # CatBoost embeds a random model GUID and the wall-clock finish time. They
    # do not affect inference but would make checksums differ across identical
    # runs, so the research artifact deliberately removes only those fields.
    model_info = normalized_model.get("model_info", {})
    model_info.pop("model_guid", None)
    model_info.pop("train_finish_time", None)
    write_json(output / "cancellation-risk-catboost.json", normalized_model)
    raw_model_path.unlink()
    joblib.dump(calibrator, output / "cancellation-risk-isotonic.joblib", compress=9)

    write_feature_mapping(output / "feature-mapping.csv")
    write_split_rows(output / "split-summary.csv", split_details)
    shap_rows = write_shap_evidence(
        output,
        final_model,
        features.iloc[indices["test"]],
        target.iloc[indices["test"]],
    )
    reliability_rows = write_calibration_evidence(output, test_target, test_calibrated)
    write_confusion_matrix(output, metrics["confusion_matrix"])

    result = {
        "schema_version": "1.0.0",
        "protocol": {
            "version": PROTOCOL_VERSION,
            "preprocessing_version": PREPROCESSING_VERSION,
            "seed": SEED,
            "compute": "cpu",
            "distance_unit": "km",
            "prediction_cutoff": "one_day_before_planned_arrival_public_proxy",
            "test_policy": "final_contiguous_block_evaluated_after_model_calibration_and_threshold_freeze",
        },
        "dataset": {
            "title": "Hotel Booking Demand",
            "doi": DATASET_DOI,
            "snapshot_url": DATASET_URL,
            "snapshot_sha256": DATASET_SHA256,
            "license": DATASET_LICENSE,
            "license_url": DATASET_LICENSE_URL,
            "audit": prepared.audit,
        },
        "mapping": {
            "scope": "public_hotel_proxy_to_rentfleet_reservation_features",
            "feature_count": len(FEATURES),
            "features": list(FEATURES),
            "forbidden_leakage_columns": list(FORBIDDEN_LEAKAGE_COLUMNS),
            "tenant_and_agency_policy": "derive_server_side_and_hmac_pseudonymize",
            "raw_personal_data_allowed": False,
        },
        "splits": split_details,
        "model": {
            "name": MODEL_NAME,
            "version": MODEL_VERSION,
            "framework": "catboost",
            "framework_version": catboost_version,
            "selected_iterations": selected_iterations,
            "parameters": model_parameters(selected_iterations),
            "imbalance_strategy": "CatBoost auto_class_weights=Balanced on training blocks only",
            "calibration": "isotonic on a disjoint chronological block",
            "explanation": "CatBoost native exact Tree SHAP on raw model margin",
        },
        "threshold_selection": threshold_selection,
        "test_metrics": metrics,
        "gate": gate,
        "evidence": {
            "shap_rows": len(shap_rows),
            "reliability_bins": len(reliability_rows),
        },
        "safety": {
            "validation_scope": "PUBLIC_BENCHMARK",
            "local_rentfleet_status": "NOT_VALIDATED_NO_REAL_HISTORY",
            "consultative_only": True,
            "human_validation_required": True,
            "automatic_action_allowed": False,
            "operational_business_write_allowed": False,
            "saas_integration_allowed": gate_passed,
            "production_claim_allowed": False,
        },
        "runtime": {
            "python": platform.python_version(),
            "numpy": np.__version__,
            "pandas": pd.__version__,
            "scikit_learn": sklearn.__version__,
            "catboost": catboost_version,
            "joblib": joblib.__version__,
            "matplotlib": matplotlib.__version__,
            "operating_system": platform.system(),
            "architecture": platform.machine(),
        },
    }
    write_json(output / "qualification-manifest.json", result)
    write_json(output / "metrics.json", {"gate": gate, "threshold_selection": threshold_selection, "test_metrics": metrics})
    write_checksums(output)
    return result


def write_feature_mapping(path: Path) -> None:
    with path.open("w", encoding="utf-8", newline="") as handle:
        writer = csv.writer(handle, lineterminator="\n")
        writer.writerow(("model_feature", "public_source", "rentfleet_source", "compatibility", "guard"))
        writer.writerows(FEATURE_MAPPING)


def write_split_rows(path: Path, details: dict[str, Any]) -> None:
    with path.open("w", encoding="utf-8", newline="") as handle:
        writer = csv.writer(handle, lineterminator="\n")
        writer.writerow(("split", "date_from", "date_to", "rows", "positive_rows", "positive_rate"))
        for name in SPLIT_NAMES:
            block = details["blocks"][name]
            writer.writerow(
                (name, block["date_from"], block["date_to"], block["rows"], block["positive_rows"], block["positive_rate"])
            )


def write_shap_evidence(
    output: Path,
    model: CatBoostClassifier,
    test_features: pd.DataFrame,
    test_target: pd.Series,
) -> list[dict[str, Any]]:
    sample_count = min(2_000, len(test_features))
    rng = np.random.default_rng(SEED)
    selected = np.sort(rng.choice(len(test_features), size=sample_count, replace=False))
    sample = test_features.iloc[selected]
    sample_pool = Pool(sample, test_target.iloc[selected], cat_features=list(CATEGORICAL_FEATURES))
    shap_values = model.get_feature_importance(sample_pool, type="ShapValues")
    mean_absolute = np.abs(shap_values[:, :-1]).mean(axis=0)
    signed_mean = shap_values[:, :-1].mean(axis=0)
    rows = [
        {
            "feature": feature,
            "mean_absolute_shap_raw_margin": round(float(mean_absolute[index]), 8),
            "mean_signed_shap_raw_margin": round(float(signed_mean[index]), 8),
            "rank": 0,
        }
        for index, feature in enumerate(FEATURES)
    ]
    rows.sort(key=lambda row: (-row["mean_absolute_shap_raw_margin"], row["feature"]))
    for rank, row in enumerate(rows, start=1):
        row["rank"] = rank

    with (output / "shap-global.csv").open("w", encoding="utf-8", newline="") as handle:
        writer = csv.DictWriter(handle, fieldnames=tuple(rows[0].keys()), lineterminator="\n")
        writer.writeheader()
        writer.writerows(rows)

    figure_rows = list(reversed(rows[:12]))
    plt.figure(figsize=(9, 6))
    plt.barh(
        [row["feature"] for row in figure_rows],
        [row["mean_absolute_shap_raw_margin"] for row in figure_rows],
        color="#2563eb",
    )
    plt.xlabel("Mean absolute SHAP contribution (raw margin)")
    plt.title("CatBoost cancellation risk — public proxy only")
    plt.tight_layout()
    plt.savefig(output / "shap-global.png", dpi=160)
    plt.close()
    return rows


def write_calibration_evidence(output: Path, y_true: np.ndarray, probabilities: np.ndarray) -> list[dict[str, Any]]:
    observed, predicted = calibration_curve(y_true, probabilities, n_bins=10, strategy="quantile")
    rows = [
        {"bin": index, "mean_predicted_probability": round(float(prediction), 8), "observed_rate": round(float(rate), 8)}
        for index, (prediction, rate) in enumerate(zip(predicted, observed, strict=True), start=1)
    ]
    with (output / "calibration-curve.csv").open("w", encoding="utf-8", newline="") as handle:
        writer = csv.DictWriter(handle, fieldnames=tuple(rows[0].keys()), lineterminator="\n")
        writer.writeheader()
        writer.writerows(rows)

    plt.figure(figsize=(6, 6))
    plt.plot([0, 1], [0, 1], linestyle="--", color="#64748b", label="Ideal")
    plt.plot(predicted, observed, marker="o", color="#2563eb", label="Calibrated CatBoost")
    plt.xlabel("Predicted probability")
    plt.ylabel("Observed cancellation/no-show rate")
    plt.title("Reliability — final chronological public test")
    plt.legend()
    plt.tight_layout()
    plt.savefig(output / "calibration-curve.png", dpi=160)
    plt.close()
    return rows


def write_confusion_matrix(output: Path, matrix: dict[str, int]) -> None:
    values = np.asarray(
        [
            [matrix["true_arrival_pred_arrival"], matrix["true_arrival_pred_risk"]],
            [matrix["true_risk_pred_arrival"], matrix["true_risk_pred_risk"]],
        ]
    )
    plt.figure(figsize=(6, 5))
    plt.imshow(values, cmap="Blues")
    for row in range(2):
        for column in range(2):
            plt.text(column, row, str(values[row, column]), ha="center", va="center", color="#0f172a")
    plt.xticks([0, 1], ["Arrival", "Risk"])
    plt.yticks([0, 1], ["Arrival", "Cancellation/no-show"])
    plt.xlabel("Predicted")
    plt.ylabel("Observed")
    plt.title("Final chronological public test")
    plt.tight_layout()
    plt.savefig(output / "confusion-matrix.png", dpi=160)
    plt.close()


def write_checksums(output: Path) -> None:
    artifacts = sorted(path for path in output.iterdir() if path.is_file() and path.name != "SHA256SUMS")
    lines = [f"{sha256_file(path)}  {path.name}" for path in artifacts]
    (output / "SHA256SUMS").write_text("\n".join(lines) + "\n", encoding="utf-8")


def parse_args(argv: Iterable[str] | None = None) -> argparse.Namespace:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--dataset", type=Path, required=True, help="Pinned hotels.csv snapshot")
    parser.add_argument("--output", type=Path, required=True, help="Research evidence output directory")
    parser.add_argument("--expected-sha256", default=DATASET_SHA256)
    return parser.parse_args(argv)


def main(argv: Iterable[str] | None = None) -> int:
    args = parse_args(argv)
    os.environ.setdefault("PYTHONHASHSEED", str(SEED))
    prepared = load_and_prepare(args.dataset, args.expected_sha256)
    result = train_and_evaluate(prepared, args.output)
    print(canonical_json({"gate": result["gate"], "test_metrics": result["test_metrics"]}), end="")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
