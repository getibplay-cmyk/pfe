#!/usr/bin/env python3
"""Create aggregate-only evidence from a private hybrid OCR review CSV.

The report never contains crop identifiers, paths, registrations, OCR strings or
human labels.  Pending rows contribute to coverage counts but never to accuracy.
"""

from __future__ import annotations

import argparse
import csv
import json
import math
import re
from collections import Counter
from pathlib import Path
from typing import Mapping, Sequence


REQUIRED_COLUMNS = (
    "image",
    "correction",
    "tool_suggestion",
    "tool_status",
    "tool_confidence",
    "review_status",
    "model_version",
    "fallback_executed",
)
TOOL_STATUSES = frozenset(
    {
        "complete_primary_suggestion",
        "complete_segmented_suggestion",
        "ambiguous_segmented_suggestion",
        "partial_segmented_suggestion",
        "empty_suggestion",
    }
)
COMPLETE_STATUSES = frozenset(
    {
        "complete_primary_suggestion",
        "complete_segmented_suggestion",
        "ambiguous_segmented_suggestion",
    }
)
REVIEW_STATUSES = frozenset(
    {"pending", "reviewed_existing", "confirmed", "corrected", "ignored"}
)
VERIFIED_REVIEW_STATUSES = frozenset({"reviewed_existing", "confirmed", "corrected"})
CANONICAL_RE = re.compile(r"^[1-9][0-9]{0,4}\|[أبدهوطيكلمنصفرس]\|[1-9][0-9]?$", re.UNICODE)


class ReviewEvidenceError(RuntimeError):
    """Raised when the private review file violates the closed aggregate contract."""


def _ratio(numerator: int, denominator: int) -> float | None:
    return round(numerator / denominator, 8) if denominator else None


def build_summary(rows: Sequence[Mapping[str, str]]) -> dict[str, object]:
    if not rows:
        raise ReviewEvidenceError("Le CSV de revue est vide.")

    images: set[str] = set()
    tool_counts: Counter[str] = Counter()
    review_counts: Counter[str] = Counter()
    model_versions: set[str] = set()
    fallback_count = 0
    verified_count = 0
    invalid_verified_count = 0
    exact_match_count = 0

    for index, row in enumerate(rows, start=2):
        missing = [column for column in REQUIRED_COLUMNS if column not in row]
        if missing:
            raise ReviewEvidenceError(
                f"Ligne {index}: colonnes absentes: {', '.join(missing)}."
            )
        image = str(row["image"]).strip()
        if not image or image in images:
            raise ReviewEvidenceError(f"Ligne {index}: image absente ou dupliquée.")
        images.add(image)

        tool_status = str(row["tool_status"]).strip()
        review_status = str(row["review_status"]).strip()
        model_version = str(row["model_version"]).strip()
        if tool_status not in TOOL_STATUSES:
            raise ReviewEvidenceError(f"Ligne {index}: statut outil inconnu.")
        if review_status not in REVIEW_STATUSES:
            raise ReviewEvidenceError(f"Ligne {index}: statut de revue inconnu.")
        if not model_version or len(model_version) > 128:
            raise ReviewEvidenceError(f"Ligne {index}: version modèle invalide.")
        try:
            confidence = float(str(row["tool_confidence"]).strip())
        except ValueError as error:
            raise ReviewEvidenceError(
                f"Ligne {index}: confiance outil invalide."
            ) from error
        if not math.isfinite(confidence) or not 0.0 <= confidence <= 1.0:
            raise ReviewEvidenceError(f"Ligne {index}: confiance hors limites.")
        fallback = str(row["fallback_executed"]).strip().lower()
        if fallback not in {"true", "false"}:
            raise ReviewEvidenceError(f"Ligne {index}: indicateur fallback invalide.")

        tool_counts[tool_status] += 1
        review_counts[review_status] += 1
        model_versions.add(model_version)
        fallback_count += int(fallback == "true")

        if review_status in VERIFIED_REVIEW_STATUSES:
            correction = str(row["correction"]).strip()
            if CANONICAL_RE.fullmatch(correction) is None:
                invalid_verified_count += 1
                continue
            verified_count += 1
            suggestion = str(row["tool_suggestion"]).strip()
            exact_match_count += int(suggestion == correction)

    complete_count = sum(tool_counts[status] for status in COMPLETE_STATUSES)
    total = len(rows)
    return {
        "schema_version": "1.0.0",
        "evidence_kind": "aggregate_private_review_snapshot",
        "row_count": total,
        "model_versions": sorted(model_versions),
        "tool": {
            "status_counts": dict(sorted(tool_counts.items())),
            "complete_suggestion_count": complete_count,
            "complete_suggestion_rate": _ratio(complete_count, total),
            "fallback_count": fallback_count,
            "fallback_rate": _ratio(fallback_count, total),
        },
        "human_review": {
            "status_counts": dict(sorted(review_counts.items())),
            "verified_canonical_count": verified_count,
            "invalid_verified_label_count": invalid_verified_count,
            "tool_exact_match_count": exact_match_count,
            "tool_exact_match_rate_on_verified": _ratio(
                exact_match_count, verified_count
            ),
        },
        "scientific_interpretation": {
            "accuracy_qualified": False,
            "accuracy_claim_allowed": False,
            "reason": (
                "Descriptive pilot only; pending rows and historical corrections do "
                "not constitute an independent preregistered test set."
            ),
        },
        "safeguards": {
            "contains_crop_identifiers": False,
            "contains_plate_text": False,
            "human_review_required": True,
            "automatic_vehicle_update_allowed": False,
            "operational_effect": "NO_OPERATIONAL_ACTION",
        },
    }


def summarize_csv(source: str | Path, destination: str | Path) -> dict[str, object]:
    destination_path = Path(destination)
    if destination_path.exists():
        raise FileExistsError(destination_path)
    with Path(source).open("r", encoding="utf-8-sig", newline="") as handle:
        reader = csv.DictReader(handle)
        fieldnames = list(reader.fieldnames or [])
        missing = [column for column in REQUIRED_COLUMNS if column not in fieldnames]
        if missing:
            raise ReviewEvidenceError(
                f"Colonnes de revue absentes: {', '.join(missing)}."
            )
        rows = [dict(row) for row in reader]

    summary = build_summary(rows)
    destination_path.parent.mkdir(parents=True, exist_ok=True)
    destination_path.write_text(
        json.dumps(summary, ensure_ascii=False, indent=2, sort_keys=True) + "\n",
        encoding="utf-8",
    )
    return summary


def build_parser() -> argparse.ArgumentParser:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--review-csv", required=True)
    parser.add_argument("--output", required=True)
    return parser


def main() -> int:
    args = build_parser().parse_args()
    summary = summarize_csv(args.review_csv, args.output)
    # The CLI emits aggregate counters only.
    print(
        json.dumps(
            {
                "status": "aggregate_evidence_created",
                "row_count": summary["row_count"],
                "accuracy_qualified": False,
            },
            sort_keys=True,
        )
    )
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
