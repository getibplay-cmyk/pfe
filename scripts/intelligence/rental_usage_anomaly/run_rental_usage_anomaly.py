#!/usr/bin/env python3
"""Rank atypical rental returns from the private RentFleet export v1.1.

The primary score is deliberately transparent: for each feature, compute the
positive robust deviation from the batch median, then average the two largest
deviations. Isolation Forest is fitted only as a challenger and never decides
which rows require operational action.
"""

from __future__ import annotations

import argparse
import csv
import hashlib
import json
import math
import re
import sys
from dataclasses import dataclass
from pathlib import Path
from typing import Any, Sequence

import numpy as np
from sklearn.ensemble import IsolationForest


SCHEMA_VERSION = "1.0.0"
SOURCE_SCHEMA_VERSION = "1.1"
SOURCE_DATASET_VERSION = "rentfleet-real-returns-v1.1.0"
PRIMARY_NAME = "robust_mad_top2"
PRIMARY_VERSION = "1.0.0"
CHALLENGER_NAME = "isolation_forest"
CHALLENGER_VERSION = "1.0.0"
OPERATIONAL_EFFECT = "NO_OPERATIONAL_ACTION"
RANDOM_STATE = 20260824
MINIMUM_ROWS = 200
RUNTIME_SHA256 = hashlib.sha256(Path(__file__).read_bytes()).hexdigest()
BUDGETS_BASIS_POINTS = (50, 100, 200)
FEATURES = ("late_hours", "km_per_day", "fuel_drop_pct")
HEADERS = (
    "schema_version",
    "dataset_version",
    "row_id",
    "tenant_key",
    "agency_key",
    "contract_key",
    "event_at",
    *FEATURES,
)
KEY_PATTERNS = {
    "row_id": re.compile(r"^r_[0-9a-f]{64}$"),
    "tenant_key": re.compile(r"^t_[0-9a-f]{64}$"),
    "agency_key": re.compile(r"^a_[0-9a-f]{64}$"),
    "contract_key": re.compile(r"^c_[0-9a-f]{64}$"),
}
EVENT_PATTERN = re.compile(r"^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$")
DECIMAL_PATTERN = re.compile(r"^(?:0|[1-9]\d{0,8})\.\d{6}$")


class ContractError(ValueError):
    """Raised when the closed input or output contract is violated."""


@dataclass(frozen=True)
class Snapshot:
    records: list[dict[str, str]]
    matrix: np.ndarray
    sha256: str
    byte_size: int


def require(condition: bool, message: str) -> None:
    if not condition:
        raise ContractError(message)


def read_snapshot(
    path: Path,
    expected_sha256: str,
    expected_bytes: int,
    expected_rows: int,
) -> Snapshot:
    raw = path.read_bytes()
    require(0 < len(raw) <= 16_777_216, "snapshot byte size is outside the allowed range")
    require(len(raw) == expected_bytes, "snapshot byte size does not match its manifest")
    digest = hashlib.sha256(raw).hexdigest()
    require(re.fullmatch(r"[0-9a-f]{64}", expected_sha256) is not None, "invalid expected sha256")
    require(digest == expected_sha256, "snapshot sha256 does not match its manifest")

    text = raw.decode("utf-8-sig")
    reader = csv.DictReader(text.splitlines(), delimiter=";", quotechar='"')
    require(tuple(reader.fieldnames or ()) == HEADERS, "unexpected CSV header order")

    records: list[dict[str, str]] = []
    values: list[list[float]] = []
    seen_rows: set[str] = set()
    tenant_key: str | None = None
    for position, row in enumerate(reader, start=1):
        require(None not in row and set(row) == set(HEADERS), f"invalid CSV row {position}")
        require(row["schema_version"] == SOURCE_SCHEMA_VERSION, f"invalid source schema at row {position}")
        require(row["dataset_version"] == SOURCE_DATASET_VERSION, f"invalid dataset version at row {position}")
        for field, pattern in KEY_PATTERNS.items():
            require(pattern.fullmatch(row[field]) is not None, f"invalid {field} at row {position}")
        require(row["row_id"] not in seen_rows, f"duplicate row_id at row {position}")
        require(EVENT_PATTERN.fullmatch(row["event_at"]) is not None, f"invalid event_at at row {position}")
        tenant_key = tenant_key or row["tenant_key"]
        require(row["tenant_key"] == tenant_key, "snapshot contains more than one tenant key")

        numeric: list[float] = []
        for feature in FEATURES:
            encoded = row[feature]
            require(DECIMAL_PATTERN.fullmatch(encoded) is not None, f"invalid {feature} at row {position}")
            value = float(encoded)
            require(math.isfinite(value) and 0.0 <= value <= 1_000_000_000.0, f"unsafe {feature} at row {position}")
            numeric.append(value)

        seen_rows.add(row["row_id"])
        records.append({field: row[field] for field in HEADERS})
        values.append(numeric)

    require(len(records) == expected_rows, "snapshot row count does not match its manifest")
    require(len(records) <= 10_000, "snapshot exceeds the RentFleet export limit")
    matrix = np.asarray(values, dtype=np.float64).reshape((-1, len(FEATURES)))
    return Snapshot(records=records, matrix=matrix, sha256=digest, byte_size=len(raw))


def robust_mad_top2(matrix: np.ndarray) -> tuple[np.ndarray, np.ndarray, np.ndarray, np.ndarray]:
    require(matrix.ndim == 2 and matrix.shape[1] == len(FEATURES), "invalid feature matrix")
    medians = np.median(matrix, axis=0)
    absolute = np.abs(matrix - medians)
    mads = np.median(absolute, axis=0)
    q1, q3 = np.percentile(matrix, [25.0, 75.0], axis=0)
    robust_scales = np.maximum(1.4826 * mads, (q3 - q1) / 1.349)
    numerical_floors = np.maximum(np.abs(medians), 1.0) * 1e-6
    scales = np.maximum(robust_scales, numerical_floors)
    deviations = np.maximum((matrix - medians) / scales, 0.0)
    ordered = np.sort(deviations, axis=1)
    scores = np.mean(ordered[:, -2:], axis=1)
    return scores, deviations, medians, mads


def isolation_forest_scores(matrix: np.ndarray) -> np.ndarray:
    transformed = np.log1p(matrix)
    model = IsolationForest(
        n_estimators=300,
        max_samples="auto",
        contamination="auto",
        max_features=1.0,
        bootstrap=False,
        n_jobs=1,
        random_state=RANDOM_STATE,
    )
    model.fit(transformed)
    return -model.score_samples(transformed)


def stable_ranks(scores: np.ndarray, records: Sequence[dict[str, str]]) -> tuple[np.ndarray, list[int]]:
    order = sorted(range(len(records)), key=lambda index: (-float(scores[index]), records[index]["row_id"]))
    ranks = np.empty(len(records), dtype=np.int64)
    for rank, index in enumerate(order, start=1):
        ranks[index] = rank
    return ranks, order


def selected_count(row_count: int, basis_points: int) -> int:
    return int(math.ceil(row_count * basis_points / 10_000.0))


def rounded(value: float) -> float:
    require(math.isfinite(value), "non-finite numeric output")
    return round(float(value), 8)


def build_result(run_id: str, snapshot: Snapshot, minimum_rows: int = MINIMUM_ROWS) -> dict[str, Any]:
    require(re.fullmatch(r"[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}", run_id) is not None, "invalid run id")
    require(200 <= minimum_rows <= 10_000, "minimum rows must be between 200 and 10000")
    row_count = len(snapshot.records)
    base: dict[str, Any] = {
        "schema_version": SCHEMA_VERSION,
        "run_id": run_id,
        "source": {
            "schema_version": SOURCE_SCHEMA_VERSION,
            "dataset_version": SOURCE_DATASET_VERSION,
            "sha256": snapshot.sha256,
            "byte_size": snapshot.byte_size,
            "row_count": row_count,
        },
        "execution": {
            "compute": "CPU",
            "primary": {"name": PRIMARY_NAME, "version": PRIMARY_VERSION},
            "challenger": {"name": CHALLENGER_NAME, "version": CHALLENGER_VERSION},
            "random_state": RANDOM_STATE,
            "runtime_sha256": RUNTIME_SHA256,
            "minimum_rows": minimum_rows,
            "default_budget_basis_points": 100,
        },
        "safety": {
            "human_review_required": True,
            "automatic_actions_allowed": False,
            "operational_effect": OPERATIONAL_EFFECT,
            "forbidden_actions": [
                "SANCTION",
                "FEE_OR_CHARGE",
                "FRAUD_ACCUSATION",
                "CONTRACT_MUTATION",
            ],
        },
    }
    if row_count < minimum_rows:
        base["execution"]["status"] = "insufficient_data"
        base["execution"]["reason"] = "MINIMUM_HISTORY_NOT_REACHED"
        base["budgets"] = []
        base["rows"] = []
        return base

    primary_scores, deviations, medians, mads = robust_mad_top2(snapshot.matrix)
    challenger_scores = isolation_forest_scores(snapshot.matrix)
    primary_ranks, primary_order = stable_ranks(primary_scores, snapshot.records)
    challenger_ranks, challenger_order = stable_ranks(challenger_scores, snapshot.records)

    budgets: list[dict[str, Any]] = []
    primary_selected: dict[int, set[int]] = {}
    challenger_selected: dict[int, set[int]] = {}
    for basis_points in BUDGETS_BASIS_POINTS:
        count = selected_count(row_count, basis_points)
        primary_set = set(primary_order[:count])
        challenger_set = set(challenger_order[:count])
        primary_selected[basis_points] = primary_set
        challenger_selected[basis_points] = challenger_set
        intersection = primary_set & challenger_set
        union = primary_set | challenger_set
        budgets.append(
            {
                "basis_points": basis_points,
                "requested_rate": basis_points / 10_000.0,
                "selected_count": count,
                "realized_rate": rounded(count / row_count),
                "primary_cutoff": rounded(primary_scores[primary_order[count - 1]]),
                "challenger_cutoff": rounded(challenger_scores[challenger_order[count - 1]]),
                "agreement_count": len(intersection),
                "union_count": len(union),
                "jaccard": rounded(len(intersection) / len(union)),
            }
        )

    candidate_indices = primary_selected[200] | challenger_selected[200]
    rows: list[dict[str, Any]] = []
    for index in sorted(candidate_indices, key=lambda item: (int(primary_ranks[item]), snapshot.records[item]["row_id"])):
        factor_order = sorted(
            range(len(FEATURES)),
            key=lambda feature_index: (-float(deviations[index, feature_index]), FEATURES[feature_index]),
        )[:2]
        factors = [
            {
                "feature": FEATURES[feature_index],
                "value": snapshot.records[index][FEATURES[feature_index]],
                "median": rounded(medians[feature_index]),
                "mad": rounded(mads[feature_index]),
                "positive_robust_deviation": rounded(deviations[index, feature_index]),
            }
            for feature_index in factor_order
        ]
        rows.append(
            {
                "row_id": snapshot.records[index]["row_id"],
                "agency_key": snapshot.records[index]["agency_key"],
                "contract_key": snapshot.records[index]["contract_key"],
                "event_at": snapshot.records[index]["event_at"],
                "features": {feature: snapshot.records[index][feature] for feature in FEATURES},
                "primary": {
                    "score": rounded(primary_scores[index]),
                    "rank": int(primary_ranks[index]),
                    "selected_budgets": [budget for budget in BUDGETS_BASIS_POINTS if index in primary_selected[budget]],
                    "factors": factors,
                },
                "challenger": {
                    "score": rounded(challenger_scores[index]),
                    "rank": int(challenger_ranks[index]),
                    "selected_budgets": [budget for budget in BUDGETS_BASIS_POINTS if index in challenger_selected[budget]],
                },
            }
        )

    base["execution"]["status"] = "usable"
    base["budgets"] = budgets
    base["rows"] = rows
    return base


def parse_args(argv: Sequence[str] | None = None) -> argparse.Namespace:
    parser = argparse.ArgumentParser()
    parser.add_argument("--run-id", required=True)
    parser.add_argument("--snapshot", type=Path, required=True)
    parser.add_argument("--snapshot-sha256", required=True)
    parser.add_argument("--snapshot-bytes", type=int, required=True)
    parser.add_argument("--snapshot-rows", type=int, required=True)
    parser.add_argument("--minimum-rows", type=int, default=MINIMUM_ROWS)
    parser.add_argument("--runtime-sha256", required=True)
    parser.add_argument("--stdout", action="store_true")
    return parser.parse_args(argv)


def main(argv: Sequence[str] | None = None) -> int:
    args = parse_args(argv)
    require(args.runtime_sha256 == RUNTIME_SHA256, "runtime sha256 does not match its manifest")
    snapshot = read_snapshot(
        args.snapshot,
        args.snapshot_sha256,
        args.snapshot_bytes,
        args.snapshot_rows,
    )
    result = build_result(args.run_id, snapshot, args.minimum_rows)
    encoded = json.dumps(result, ensure_ascii=False, separators=(",", ":"), sort_keys=True)
    if args.stdout:
        sys.stdout.write(encoded)
        return 0
    raise ContractError("--stdout is required")


if __name__ == "__main__":
    try:
        raise SystemExit(main())
    except (ContractError, OSError, UnicodeError) as exception:
        sys.stderr.write(f"rental_usage_anomaly_error: {exception}\n")
        raise SystemExit(2)
