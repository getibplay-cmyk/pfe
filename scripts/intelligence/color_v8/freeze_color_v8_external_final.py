#!/usr/bin/env python3
"""Freeze a prediction-blind, licence-audited S7 colour v8 final.

Input selection and review must be complete before this command.  The command
never imports a model and verifies exact/perceptual independence from all v8
development rows.  It creates data only; it cannot execute the one-shot final.
"""

from __future__ import annotations

import argparse
import io
import json
import os
import tempfile
import zipfile
from collections import Counter, defaultdict
from datetime import datetime, timezone
from pathlib import Path

from PIL import Image, ImageOps

from prepare_color_v8_dataset import ONTOLOGY, band_keys, phash64, sha256_bytes, sha256_file


MIN_SUPPORT = 20
ACCEPTED_REVIEW_STATUSES = {
    "accepted_prediction_blind_manual_review",
    "accepted_prediction_blind_metadata_review",
}


def utc_now() -> str:
    return datetime.now(timezone.utc).replace(microsecond=0).isoformat().replace("+00:00", "Z")


def atomic_json(path: Path, payload: dict) -> None:
    descriptor, temporary = tempfile.mkstemp(prefix=f".{path.name}.", dir=path.parent)
    try:
        with os.fdopen(descriptor, "w", encoding="utf-8") as stream:
            json.dump(payload, stream, indent=2, sort_keys=True)
            stream.write("\n")
            stream.flush()
            os.fsync(stream.fileno())
        os.replace(temporary, path)
    except Exception:
        try:
            os.unlink(temporary)
        except FileNotFoundError:
            pass
        raise


def image_fingerprint(path: Path) -> tuple[bytes, str, int, int, int]:
    payload = path.read_bytes()
    with Image.open(io.BytesIO(payload)) as opened:
        opened.load()
        image = ImageOps.exif_transpose(opened).convert("RGB")
    width, height = image.size
    return payload, sha256_bytes(payload), phash64(image), width, height


def read_development_fingerprints(path: Path) -> tuple[set[str], list[int]]:
    import csv

    hashes, phashes = set(), []
    with path.open("r", encoding="utf-8", newline="") as stream:
        for row in csv.DictReader(stream):
            hashes.add(row["sha256"])
            phashes.append(int(row["phash64"], 16))
    if not hashes:
        raise ValueError("Empty development manifest")
    return hashes, phashes


def build_band_index(phashes: list[int]) -> dict[tuple[int, int], list[int]]:
    index: dict[tuple[int, int], list[int]] = defaultdict(list)
    for row_index, value in enumerate(phashes):
        for key in band_keys(value):
            index[key].append(row_index)
    return index


def closest_distance(value: int, reference: list[int], index: dict[tuple[int, int], list[int]]) -> int:
    if not reference:
        return 64
    possible = set()
    for key in band_keys(value):
        possible.update(index.get(key, ()))
    if possible:
        screened = min((value ^ reference[row_index]).bit_count() for row_index in possible)
        if screened <= 4:
            return screened
    # The band index is a complete detector for distances <= 4.  Compute the
    # global minimum too because that exact distance is recorded in the ledger.
    return min((value ^ reference_value).bit_count() for reference_value in reference)


def safe_suffix(path: Path) -> str:
    suffix = path.suffix.lower()
    return suffix if suffix in {".jpg", ".jpeg", ".png", ".webp", ".bmp"} else ".jpg"


def main() -> None:
    parser = argparse.ArgumentParser()
    parser.add_argument("--review-ledger", type=Path, required=True)
    parser.add_argument("--development-manifest", type=Path, required=True)
    parser.add_argument("--output-dir", type=Path, required=True)
    args = parser.parse_args()

    review_ledger = args.review_ledger.resolve()
    development_manifest = args.development_manifest.resolve()
    output = args.output_dir.resolve()
    output.mkdir(parents=True, exist_ok=True)
    if any(output.iterdir()):
        raise FileExistsError(f"Output directory must be empty: {output}")

    development_hashes, development_phashes = read_development_fingerprints(development_manifest)
    development_index = build_band_index(development_phashes)
    source_rows = [json.loads(line) for line in review_ledger.read_text(encoding="utf-8").splitlines() if line.strip()]
    if not source_rows:
        raise ValueError("Empty final review ledger")

    rows = []
    payloads = {}
    final_phashes: list[int] = []
    final_index: dict[tuple[int, int], list[int]] = defaultdict(list)
    seen_hashes, seen_urls = set(), set()
    for number, source in enumerate(source_rows, start=1):
        required = {
            "absolute_path",
            "target",
            "source_id",
            "source_url",
            "license_id",
            "license_url",
            "review_status",
            "review_method",
            "candidate_model_used_for_selection",
        }
        missing = sorted(required - set(source))
        if missing:
            raise ValueError(f"Ledger row {number} is missing {missing}")
        target = source["target"]
        if target not in ONTOLOGY:
            raise ValueError(f"Ledger row {number} has unsupported target {target!r}")
        if source["review_status"] not in ACCEPTED_REVIEW_STATUSES:
            raise ValueError(f"Ledger row {number} was not accepted prediction-blind")
        if source["candidate_model_used_for_selection"] is not False:
            raise ValueError(f"Ledger row {number} used the candidate model for selection")
        if not all(str(source[field]).strip() for field in ("source_id", "source_url", "license_id", "license_url", "review_method")):
            raise ValueError(f"Ledger row {number} has incomplete provenance")
        if source["source_url"] in seen_urls:
            raise ValueError(f"Duplicate source URL in final ledger: {source['source_url']}")

        image_path = Path(source["absolute_path"]).resolve()
        if not image_path.is_file():
            raise ValueError(f"Missing final image: {image_path}")
        payload, digest, phash, width, height = image_fingerprint(image_path)
        if source.get("sha256") and source["sha256"] != digest:
            raise ValueError(f"Ledger SHA-256 drift: {image_path}")
        if source.get("phash64") and int(source["phash64"], 16) != phash:
            raise ValueError(f"Ledger pHash drift: {image_path}")
        if digest in development_hashes:
            raise ValueError(f"Exact development/final leakage: {image_path}")
        development_distance = closest_distance(phash, development_phashes, development_index)
        if development_distance <= 4:
            raise ValueError(f"Perceptual development/final leakage (distance {development_distance}): {image_path}")
        if digest in seen_hashes:
            raise ValueError(f"Exact duplicate inside final: {image_path}")
        final_distance = closest_distance(phash, final_phashes, final_index) if final_phashes else 64
        if final_distance <= 4:
            raise ValueError(f"Perceptual duplicate inside final (distance {final_distance}): {image_path}")

        relative_path = f"images/{target}/{digest}{safe_suffix(image_path)}"
        row = {
            "relative_path": relative_path,
            "target": target,
            "source_id": source["source_id"],
            "source_url": source["source_url"],
            "license_id": source["license_id"],
            "license_url": source["license_url"],
            "attribution": source.get("attribution", ""),
            "review_status": source["review_status"],
            "review_method": source["review_method"],
            "candidate_model_used_for_selection": False,
            "sha256": digest,
            "phash64": f"{phash:016x}",
            "minimum_development_phash_distance": development_distance,
            "width": width,
            "height": height,
        }
        rows.append(row)
        payloads[digest] = payload
        row_index = len(final_phashes)
        final_phashes.append(phash)
        for key in band_keys(phash):
            final_index[key].append(row_index)
        seen_hashes.add(digest)
        seen_urls.add(source["source_url"])

    counts = Counter(row["target"] for row in rows)
    insufficient = {target: counts.get(target, 0) for target in ONTOLOGY if counts.get(target, 0) < MIN_SUPPORT}
    if insufficient:
        raise ValueError(f"Final support below {MIN_SUPPORT}: {insufficient}")

    rows.sort(key=lambda row: (ONTOLOGY.index(row["target"]), row["sha256"]))
    manifest_path = output / "S7_COLOR_V8_EXTERNAL_FINAL_MANIFEST.jsonl"
    manifest_path.write_text(
        "".join(json.dumps(row, sort_keys=True, separators=(",", ":")) + "\n" for row in rows),
        encoding="utf-8",
    )
    archive_path = output / "S7_COLOR_V8_EXTERNAL_FINAL_DATA.zip"
    zip_timestamp = (2026, 8, 22, 0, 0, 0)
    with zipfile.ZipFile(archive_path, "w", compression=zipfile.ZIP_STORED, allowZip64=True) as archive:
        for row in rows:
            info = zipfile.ZipInfo(row["relative_path"], date_time=zip_timestamp)
            info.compress_type = zipfile.ZIP_STORED
            info.external_attr = 0o100644 << 16
            archive.writestr(info, payloads[row["sha256"]])
        info = zipfile.ZipInfo(manifest_path.name, date_time=zip_timestamp)
        info.compress_type = zipfile.ZIP_DEFLATED
        info.external_attr = 0o100644 << 16
        archive.writestr(info, manifest_path.read_bytes(), compress_type=zipfile.ZIP_DEFLATED, compresslevel=9)

    registry_path = output / "S7_COLOR_V8_EXTERNAL_FINAL_REGISTRY.json"
    registry = {
        "schema_version": "8.0.0",
        "created_at_utc": utc_now(),
        "status": "FROZEN_PREDICTION_BLIND_NOT_EXECUTED",
        "rows": len(rows),
        "support": {target: int(counts[target]) for target in ONTOLOGY},
        "ontology": list(ONTOLOGY),
        "independence": {
            "development_manifest_sha256": sha256_file(development_manifest),
            "exact_overlap": 0,
            "perceptual_overlap_hamming_le_4": 0,
            "minimum_observed_distance": min(row["minimum_development_phash_distance"] for row in rows),
        },
        "selection": {
            "prediction_blind": True,
            "candidate_model_used": False,
            "review_ledger_sha256": sha256_file(review_ledger),
        },
        "artifacts": {
            manifest_path.name: {"sha256": sha256_file(manifest_path), "bytes": manifest_path.stat().st_size},
            archive_path.name: {"sha256": sha256_file(archive_path), "bytes": archive_path.stat().st_size},
        },
        "one_shot_evaluation": {"allowed": True, "executed": False},
        "saas_integration_authorized": False,
    }
    atomic_json(registry_path, registry)
    print(
        json.dumps(
            {
                "status": registry["status"],
                "rows": len(rows),
                "manifest_sha256": registry["artifacts"][manifest_path.name]["sha256"],
                "one_shot_executed": False,
            },
            indent=2,
        )
    )


if __name__ == "__main__":
    main()
