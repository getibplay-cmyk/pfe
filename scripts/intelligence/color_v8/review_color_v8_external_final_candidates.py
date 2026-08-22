#!/usr/bin/env python3
"""Materialize a prediction-blind reviewed pool for the S7 colour v8 final.

The approved queues come exclusively from manual contact-sheet review.  This
script never imports a model.  It only removes exact/perceptual overlaps with
development and exact/perceptual duplicates inside the prospective final,
then keeps the first 20 independent rows for every ontology target.
"""

from __future__ import annotations

import argparse
import csv
import hashlib
import io
import json
from collections import Counter, defaultdict
from datetime import datetime, timezone
from pathlib import Path

from PIL import Image, ImageOps

from prepare_color_v8_dataset import ONTOLOGY, band_keys, phash64, sha256_bytes, sha256_file


MIN_SUPPORT = 20


def utc_now() -> str:
    return datetime.now(timezone.utc).replace(microsecond=0).isoformat().replace("+00:00", "Z")


def image_fingerprint(path: Path) -> tuple[str, int]:
    payload = path.read_bytes()
    with Image.open(io.BytesIO(payload)) as opened:
        opened.load()
        image = ImageOps.exif_transpose(opened).convert("RGB")
    return sha256_bytes(payload), phash64(image)


def build_band_index(phashes: list[int]) -> dict[tuple[int, int], list[int]]:
    index: dict[tuple[int, int], list[int]] = defaultdict(list)
    for row_index, value in enumerate(phashes):
        for key in band_keys(value):
            index[key].append(row_index)
    return index


def closest_distance(
    value: int,
    reference: list[int],
    index: dict[tuple[int, int], list[int]],
) -> int:
    if not reference:
        return 64
    possible = set()
    for key in band_keys(value):
        possible.update(index.get(key, ()))
    if possible:
        screened = min((value ^ reference[row_index]).bit_count() for row_index in possible)
        if screened <= 4:
            return screened
    return min((value ^ reference_value).bit_count() for reference_value in reference)


def main() -> None:
    parser = argparse.ArgumentParser()
    parser.add_argument("--candidate-ledger", type=Path, required=True)
    parser.add_argument("--development-manifest", type=Path, required=True)
    parser.add_argument("--approved-queues", type=Path, required=True)
    parser.add_argument("--review-ledger", type=Path, required=True)
    parser.add_argument("--audit", type=Path, required=True)
    args = parser.parse_args()

    candidates = [
        json.loads(line)
        for line in args.candidate_ledger.read_text(encoding="utf-8").splitlines()
        if line.strip()
    ]
    by_review_id = {row["review_id"]: row for row in candidates}
    if len(by_review_id) != len(candidates):
        raise ValueError("Candidate review IDs are not unique")

    approved = json.loads(args.approved_queues.read_text(encoding="utf-8"))
    if set(approved) != set(ONTOLOGY):
        raise ValueError(f"Approved queue targets must be exactly {list(ONTOLOGY)}")
    approved_flat = [review_id for target in ONTOLOGY for review_id in approved[target]]
    if len(set(approved_flat)) != len(approved_flat):
        raise ValueError("Approved review IDs are not unique")
    missing = sorted(set(approved_flat) - set(by_review_id))
    if missing:
        raise ValueError(f"Unknown approved review IDs: {missing}")

    development_hashes: set[str] = set()
    development_phashes: list[int] = []
    with args.development_manifest.open("r", encoding="utf-8", newline="") as stream:
        for row in csv.DictReader(stream):
            development_hashes.add(row["sha256"])
            development_phashes.append(int(row["phash64"], 16))
    if not development_hashes:
        raise ValueError("Empty development manifest")
    development_index = build_band_index(development_phashes)

    selected: list[dict] = []
    selected_hashes: set[str] = set()
    selected_urls: set[str] = set()
    selected_phashes: list[int] = []
    selected_index: dict[tuple[int, int], list[int]] = defaultdict(list)
    decisions: dict[str, dict] = {
        row["review_id"]: {
            "review_id": row["review_id"],
            "target_hint": row["target_hint"],
            "source_id": row["source_id"],
            "source_url": row["source_url"],
            "visual_review": (
                "approved_pool" if row["review_id"] in set(approved_flat) else "rejected"
            ),
            "decision": "not_selected_by_prediction_blind_visual_review",
            "candidate_model_used_for_selection": False,
        }
        for row in candidates
    }

    for target in ONTOLOGY:
        accepted_for_target = 0
        for queue_position, review_id in enumerate(approved[target], start=1):
            decision = decisions[review_id]
            row = by_review_id[review_id]
            if row["target_hint"] != target:
                raise ValueError(f"{review_id} belongs to {row['target_hint']}, not {target}")
            decision["approved_queue_position"] = queue_position
            if accepted_for_target >= MIN_SUPPORT:
                decision["decision"] = "approved_backup_not_needed"
                continue

            path = Path(row["absolute_path"]).resolve()
            if not path.is_file():
                raise ValueError(f"Missing candidate image: {path}")
            digest, phash = image_fingerprint(path)
            if row.get("sha256") and row["sha256"] != digest:
                raise ValueError(f"Candidate SHA-256 drift: {path}")

            development_distance = closest_distance(phash, development_phashes, development_index)
            final_distance = closest_distance(phash, selected_phashes, selected_index)
            reason = None
            if digest in development_hashes:
                reason = "exact_development_overlap"
            elif development_distance <= 4:
                reason = f"perceptual_development_overlap_distance_{development_distance}"
            elif digest in selected_hashes:
                reason = "exact_duplicate_inside_final"
            elif final_distance <= 4:
                reason = f"perceptual_duplicate_inside_final_distance_{final_distance}"
            elif row["source_url"] in selected_urls:
                reason = "duplicate_source_url_inside_final"
            if reason:
                decision["decision"] = reason
                decision["sha256"] = digest
                decision["phash64"] = f"{phash:016x}"
                decision["minimum_development_phash_distance"] = development_distance
                continue

            reviewed = dict(row)
            reviewed.update(
                {
                    "target": target,
                    "review_status": "accepted_prediction_blind_manual_review",
                    "review_method": "manual_visual_contact_sheet_review_20260822_candidate_model_never_loaded",
                    "candidate_model_used_for_selection": False,
                    "selection_rank_within_target": accepted_for_target + 1,
                    "approved_queue_position": queue_position,
                    "sha256": digest,
                    "phash64": f"{phash:016x}",
                }
            )
            selected.append(reviewed)
            accepted_for_target += 1
            selected_hashes.add(digest)
            selected_urls.add(row["source_url"])
            selected_row_index = len(selected_phashes)
            selected_phashes.append(phash)
            for key in band_keys(phash):
                selected_index[key].append(selected_row_index)
            decision.update(
                {
                    "decision": "selected_for_frozen_final",
                    "selection_rank_within_target": accepted_for_target,
                    "sha256": digest,
                    "phash64": f"{phash:016x}",
                    "minimum_development_phash_distance": development_distance,
                    "minimum_prior_final_phash_distance": final_distance,
                }
            )
        if accepted_for_target < MIN_SUPPORT:
            raise ValueError(
                f"{target}: only {accepted_for_target} independent visually approved rows; "
                f"need {MIN_SUPPORT}"
            )

    args.review_ledger.parent.mkdir(parents=True, exist_ok=True)
    with args.review_ledger.open("w", encoding="utf-8") as stream:
        for row in selected:
            stream.write(json.dumps(row, ensure_ascii=False, sort_keys=True) + "\n")

    support = Counter(row["target"] for row in selected)
    audit = {
        "schema_version": "8.0.0-prediction-blind-review",
        "created_at_utc": utc_now(),
        "status": "VISUALLY_REVIEWED_AND_INDEPENDENCE_FILTERED_NOT_EXECUTED",
        "candidate_ledger_sha256": sha256_file(args.candidate_ledger),
        "development_manifest_sha256": sha256_file(args.development_manifest),
        "approved_queues_sha256": sha256_file(args.approved_queues),
        "selection": {
            "prediction_blind": True,
            "candidate_model_loaded": False,
            "candidate_model_used_for_selection": False,
            "manual_review_surface": "Wikimedia Commons contact sheets",
            "exact_development_overlap_removed": True,
            "perceptual_development_overlap_hamming_le_4_removed": True,
            "exact_and_perceptual_duplicates_inside_final_removed": True,
        },
        "support": {target: int(support[target]) for target in ONTOLOGY},
        "selected_rows": len(selected),
        "decisions": [decisions[row["review_id"]] for row in candidates],
        "external_final_inference_executed": False,
        "saas_integration_authorized": False,
    }
    args.audit.write_text(json.dumps(audit, indent=2, ensure_ascii=False, sort_keys=True) + "\n", encoding="utf-8")
    print(
        json.dumps(
            {
                "status": audit["status"],
                "support": audit["support"],
                "selected_rows": len(selected),
                "review_ledger_sha256": sha256_file(args.review_ledger),
                "candidate_model_loaded": False,
                "external_final_inference_executed": False,
            },
            indent=2,
        )
    )


if __name__ == "__main__":
    main()
