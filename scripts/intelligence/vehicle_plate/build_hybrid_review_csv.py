#!/usr/bin/env python3
"""Join private hybrid OCR suggestions to a manual-review labels CSV.

The source CSV is never modified.  Existing human corrections are preserved.
Complete suggestions may prefill empty ``correction`` cells for the requested
"leave if correct, edit if wrong" workflow, while ``review_status`` remains
``pending`` until a person explicitly marks the row ``confirmed`` or
``corrected``.  Partial suggestions stay out of ``correction``.
"""

from __future__ import annotations

import argparse
import csv
import json
import sys
from collections import Counter
from pathlib import Path, PurePosixPath
from typing import Any, Mapping, Sequence


REPOSITORY_ROOT = Path(__file__).resolve().parents[3]
if str(REPOSITORY_ROOT) not in sys.path:
    sys.path.insert(0, str(REPOSITORY_ROOT))

from scripts.intelligence.vehicle_plate.hybrid_fallback import (
    HYBRID_FALLBACK_VERSION,
    OCR_MODEL_NAME,
)
from scripts.intelligence.vehicle_plate.hybrid_ocr_worker import (
    validate_hybrid_worker_payload,
)
from scripts.intelligence.vehicle_plate.protocol import ProtocolError


REQUIRED_COLUMNS = ("image", "prediction", "correction")
ADDED_COLUMNS = (
    "tool_suggestion",
    "tool_status",
    "tool_confidence",
    "review_status",
    "model_version",
    "fallback_executed",
)
def _image_keys(value: str) -> tuple[str, ...]:
    text = str(value).strip()
    if not text:
        return ()
    path = PurePosixPath(text.replace("\\", "/"))
    return tuple(dict.fromkeys((text, path.name, path.stem)))


def _load_labels(path: str | Path) -> tuple[list[str], list[dict[str, str]]]:
    with Path(path).open("r", encoding="utf-8-sig", newline="") as handle:
        reader = csv.DictReader(handle)
        fieldnames = list(reader.fieldnames or [])
        missing = [column for column in REQUIRED_COLUMNS if column not in fieldnames]
        if missing:
            raise ProtocolError(
                f"Colonnes labels absentes: {', '.join(missing)}."
            )
        rows = [
            {column: str(value or "") for column, value in row.items()}
            for row in reader
        ]
    if not rows:
        raise ProtocolError("Le fichier labels est vide.")
    images = [row["image"].strip() for row in rows]
    if any(not image for image in images) or len(images) != len(set(images)):
        raise ProtocolError("La colonne image contient une valeur vide ou dupliquée.")
    return fieldnames, rows


def _index_results(payload: Mapping[str, Any]) -> dict[str, Mapping[str, Any]]:
    rows = payload.get("results")
    if not isinstance(rows, list):
        raise ProtocolError("Résultats hybrides absents.")
    crop_ids = [str(row.get("crop_id", "")) for row in rows if isinstance(row, Mapping)]
    indexed = validate_hybrid_worker_payload(payload, crop_ids)
    lookup: dict[str, Mapping[str, Any]] = {}
    for crop_id, row in indexed.items():
        keys = _image_keys(crop_id)
        for key in keys:
            if key in lookup:
                raise ProtocolError(f"Clé de crop hybride ambiguë: {key!r}.")
            lookup[key] = row
    return lookup


def build_review_rows(
    label_rows: Sequence[Mapping[str, str]],
    hybrid_payload: Mapping[str, Any],
    *,
    prefill_complete_corrections: bool = True,
) -> tuple[list[dict[str, str]], Counter[str]]:
    lookup = _index_results(hybrid_payload)
    output: list[dict[str, str]] = []
    counts: Counter[str] = Counter()
    matched_crop_ids: set[str] = set()

    for source_row in label_rows:
        row = {column: str(value or "") for column, value in source_row.items()}
        result = next(
            (lookup[key] for key in _image_keys(row.get("image", "")) if key in lookup),
            None,
        )
        if result is None:
            raise ProtocolError(
                f"Aucun résultat hybride pour l'image {row.get('image', '')!r}."
            )
        matched_crop_ids.add(str(result["crop_id"]))
        suggestion = result["suggestion"]
        canonical = str(suggestion.get("canonical") or "")
        display_text = str(suggestion.get("display_text") or "")
        status = str(suggestion.get("status") or "")
        existing_correction = row.get("correction", "").strip()

        row["tool_suggestion"] = canonical or display_text
        row["tool_status"] = status
        row["tool_confidence"] = format(float(suggestion.get("confidence", 0.0)), ".6f")
        row["model_version"] = f"{OCR_MODEL_NAME}+hybrid-{HYBRID_FALLBACK_VERSION}"
        row["fallback_executed"] = (
            "true" if bool(result.get("fallback_executed")) else "false"
        )
        if existing_correction:
            row["correction"] = existing_correction
            row["review_status"] = row.get("review_status", "").strip() or "reviewed_existing"
            counts["preserved_human_correction"] += 1
        elif prefill_complete_corrections and canonical:
            row["correction"] = canonical
            row["review_status"] = "pending"
            counts["prefilled_pending"] += 1
        else:
            row["correction"] = ""
            row["review_status"] = "pending"
            counts["pending_without_complete_suggestion"] += 1
        counts[f"tool_status:{status}"] += 1
        output.append(row)

    result_ids = {
        str(row.get("crop_id", ""))
        for row in hybrid_payload.get("results", [])
        if isinstance(row, Mapping)
    }
    if matched_crop_ids != result_ids:
        extras = sorted(result_ids.difference(matched_crop_ids))
        raise ProtocolError(
            f"Résultats hybrides sans ligne labels: {', '.join(extras[:5])}."
        )
    return output, counts


def write_review_csv(
    labels_path: str | Path,
    hybrid_output_path: str | Path,
    destination: str | Path,
    *,
    prefill_complete_corrections: bool = True,
) -> Counter[str]:
    destination_path = Path(destination)
    if destination_path.exists():
        raise FileExistsError(destination_path)
    fieldnames, label_rows = _load_labels(labels_path)
    payload = json.loads(Path(hybrid_output_path).read_text(encoding="utf-8"))
    if not isinstance(payload, Mapping):
        raise ProtocolError("Sortie hybride sans objet JSON racine.")
    review_rows, counts = build_review_rows(
        label_rows,
        payload,
        prefill_complete_corrections=prefill_complete_corrections,
    )
    output_fields = fieldnames + [
        column for column in ADDED_COLUMNS if column not in fieldnames
    ]
    destination_path.parent.mkdir(parents=True, exist_ok=True)
    with destination_path.open("x", encoding="utf-8", newline="") as handle:
        writer = csv.DictWriter(handle, fieldnames=output_fields, extrasaction="ignore")
        writer.writeheader()
        writer.writerows(review_rows)
    return counts


def build_parser() -> argparse.ArgumentParser:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--labels", required=True)
    parser.add_argument("--hybrid-output", required=True)
    parser.add_argument("--output", required=True)
    parser.add_argument(
        "--no-prefill-correction",
        action="store_true",
        help="Keep correction empty and place every result only in tool_suggestion.",
    )
    return parser


def main() -> int:
    args = build_parser().parse_args()
    counts = write_review_csv(
        args.labels,
        args.hybrid_output,
        args.output,
        prefill_complete_corrections=not args.no_prefill_correction,
    )
    # Do not print registrations, paths, crop identifiers or raw OCR evidence.
    print(
        json.dumps(
            {"status": "review_csv_created", "counts": dict(sorted(counts.items()))},
            ensure_ascii=False,
            sort_keys=True,
        )
    )
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
