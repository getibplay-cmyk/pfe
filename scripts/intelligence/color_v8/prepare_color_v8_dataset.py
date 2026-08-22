#!/usr/bin/env python3
"""Build the licence-audited S7 colour v8 development archive.

This command deliberately moves every Markovka image, including the retired
v7.2.1 final, into *development* for v8.  It never creates the new v8 final.
The new final must come from a separately frozen source and is handled by a
different one-shot command.
"""

from __future__ import annotations

import argparse
import csv
import hashlib
import io
import json
import os
import tempfile
import zipfile
from collections import Counter, defaultdict
from dataclasses import dataclass
from datetime import datetime, timezone
from pathlib import Path
from typing import Iterable

import cv2
import numpy as np
from PIL import Image, ImageOps


SEED = 20260822
SUPPORTED = ("black", "blue", "gray", "green", "orange", "red", "white", "yellow")
REJECT = "__reject__"
ONTOLOGY = SUPPORTED + (REJECT,)
SOURCE_ORDER = {
    "hammadjavaid_car_color_apache_2": 0,
    "julichitai_multilabel_cc0": 1,
    "wikimedia_commons_per_file_licensed": 2,
    "markovka_car_dataset_v3_provider_apache_2": 3,
}
MARKOVKA_LABELS = {
    "black": "black",
    "blue": "blue",
    "grey": "gray",
    "green": "green",
    "orange": "orange",
    "red": "red",
    "white": "white",
    "yellow": "yellow",
    "brown": REJECT,
    "cyan": REJECT,
    "violet": REJECT,
}


def utc_now() -> str:
    return datetime.now(timezone.utc).replace(microsecond=0).isoformat().replace("+00:00", "Z")


def sha256_file(path: Path, chunk_size: int = 8 * 1024 * 1024) -> str:
    digest = hashlib.sha256()
    with path.open("rb") as stream:
        for chunk in iter(lambda: stream.read(chunk_size), b""):
            digest.update(chunk)
    return digest.hexdigest()


def sha256_bytes(payload: bytes) -> str:
    return hashlib.sha256(payload).hexdigest()


def stable_hash(*values: str) -> str:
    return hashlib.sha256("|".join(values).encode("utf-8")).hexdigest()


def phash64(image: Image.Image) -> int:
    gray = np.asarray(image.convert("L").resize((32, 32), Image.Resampling.LANCZOS), dtype=np.float32)
    values = cv2.dct(gray)[:8, :8].reshape(-1)
    median = float(np.median(values[1:]))
    value = 0
    for bit in values > median:
        value = (value << 1) | int(bit)
    return value


def inspect_image(payload: bytes) -> tuple[int, int, int]:
    with Image.open(io.BytesIO(payload)) as opened:
        opened.load()
        image = ImageOps.exif_transpose(opened).convert("RGB")
    width, height = image.size
    return width, height, phash64(image)


def inspect_image_path(path: Path) -> tuple[int, int, int]:
    with Image.open(path) as opened:
        opened.load()
        image = ImageOps.exif_transpose(opened).convert("RGB")
    width, height = image.size
    return width, height, phash64(image)


@dataclass(frozen=True)
class Candidate:
    source: str
    provider_label: str
    target: str
    provider_split: str
    proposed_split: str
    original_name: str
    file_path: Path | None
    payload: bytes | None
    sha256: str
    phash: int
    width: int
    height: int
    license_id: str
    license_url: str
    source_url: str
    attribution: str = ""

    def read_payload(self) -> bytes:
        if self.file_path is not None:
            return self.file_path.read_bytes()
        if self.payload is not None:
            return self.payload
        raise RuntimeError(f"Candidate {self.original_name!r} has no payload source")


def from_v7_manifest(path: Path) -> Iterable[Candidate]:
    accepted_sources = {
        "hammadjavaid_car_color_apache_2": ("Apache-2.0", "https://www.apache.org/licenses/LICENSE-2.0.txt", "https://www.kaggle.com/datasets/hammadjavaid/car-color-classification-dataset"),
        "julichitai_multilabel_cc0": ("CC0-1.0", "https://creativecommons.org/publicdomain/zero/1.0/", "https://www.kaggle.com/datasets/julichitai/multilabel-small-car-and-color-dataset"),
    }
    with path.open("r", encoding="utf-8", newline="") as stream:
        for row in csv.DictReader(stream):
            source = row["source"]
            if source not in accepted_sources:
                continue
            image_path = Path(row["path"])
            width, height, computed_phash = inspect_image_path(image_path)
            expected_phash = int(row["phash64"], 16)
            # The original preparation used OpenCV DCT. A differing implementation
            # is not a data error, so retain the already-frozen ledger value.
            phash = expected_phash if expected_phash else computed_phash
            license_id, license_url, source_url = accepted_sources[source]
            yield Candidate(
                source=source,
                provider_label=row["target"],
                target=row["target"],
                provider_split=row["provider_split"],
                proposed_split=row["development_split"],
                original_name=image_path.name,
                file_path=image_path,
                payload=None,
                sha256=sha256_file(image_path),
                phash=phash,
                width=width,
                height=height,
                license_id=license_id,
                license_url=license_url,
                source_url=source_url,
            )


def from_commons_manifest(path: Path) -> Iterable[Candidate]:
    for line in path.read_text(encoding="utf-8").splitlines():
        if not line.strip():
            continue
        row = json.loads(line)
        image_path = Path(row["absolute_path"])
        digest = sha256_file(image_path)
        if digest != row["acquired_sha256"]:
            raise ValueError(f"Commons SHA-256 drift: {image_path}")
        width, height, computed_phash = inspect_image_path(image_path)
        yield Candidate(
            source="wikimedia_commons_per_file_licensed",
            provider_label=row["weak_label"],
            target=row["target"],
            provider_split="curated_train",
            proposed_split="train",
            original_name=image_path.name,
            file_path=image_path,
            payload=None,
            sha256=digest,
            phash=int(row.get("phash64") or f"{computed_phash:016x}", 16),
            width=width,
            height=height,
            license_id=row["license"],
            license_url=row["license_url"],
            source_url=row["file_description_url"],
            attribution=" | ".join(value for value in (row.get("artist_text", ""), row.get("credit_text", "")) if value),
        )


def from_markovka_archive(path: Path) -> list[Candidate]:
    candidates = []
    with zipfile.ZipFile(path) as archive:
        for info in sorted(archive.infolist(), key=lambda item: item.filename):
            if info.is_dir() or not info.filename.startswith("car_color_dataset/train/"):
                continue
            parts = Path(info.filename).parts
            if len(parts) < 4:
                continue
            provider_label = parts[2]
            target = MARKOVKA_LABELS.get(provider_label.lower())
            if target is None:
                continue
            payload = archive.read(info)
            width, height, phash = inspect_image(payload)
            candidates.append(
                Candidate(
                    source="markovka_car_dataset_v3_provider_apache_2",
                    provider_label=provider_label,
                    target=target,
                    provider_split="train",
                    proposed_split="markovka_unassigned",
                    original_name=info.filename,
                    file_path=None,
                    payload=payload,
                    sha256=sha256_bytes(payload),
                    phash=phash,
                    width=width,
                    height=height,
                    license_id="Apache-2.0-provider-declared",
                    license_url="https://www.apache.org/licenses/LICENSE-2.0.txt",
                    source_url="https://www.kaggle.com/datasets/markovka/car-dataset",
                )
            )
    return candidates


def band_keys(value: int) -> tuple[tuple[int, int], ...]:
    # Eight 8-bit bands guarantee that two 64-bit hashes at Hamming distance
    # <= 4 share at least one exact band, avoiding false negatives in lookup.
    return tuple((band, (value >> (8 * band)) & 0xFF) for band in range(8))


def remove_duplicates(candidates: list[Candidate], radius: int = 4) -> tuple[list[Candidate], dict]:
    retained: list[Candidate] = []
    exact_seen: dict[str, Candidate] = {}
    band_index: dict[tuple[int, int], list[int]] = defaultdict(list)
    removed_exact = []
    removed_near = []
    for candidate in sorted(candidates, key=lambda item: (SOURCE_ORDER[item.source], item.sha256, item.original_name)):
        if candidate.sha256 in exact_seen:
            removed_exact.append((candidate, exact_seen[candidate.sha256]))
            continue
        possible = set()
        for key in band_keys(candidate.phash):
            possible.update(band_index.get(key, ()))
        near_index = next(
            (index for index in sorted(possible) if (candidate.phash ^ retained[index].phash).bit_count() <= radius),
            None,
        )
        if near_index is not None:
            removed_near.append((candidate, retained[near_index]))
            continue
        index = len(retained)
        retained.append(candidate)
        exact_seen[candidate.sha256] = candidate
        for key in band_keys(candidate.phash):
            band_index[key].append(index)
    return retained, {
        "exact_rows_removed": len(removed_exact),
        "near_rows_removed_hamming_le_4": len(removed_near),
        "exact_examples": [
            {"removed": left.original_name, "kept": right.original_name, "sha256": left.sha256}
            for left, right in removed_exact[:50]
        ],
        "near_examples": [
            {
                "removed": left.original_name,
                "kept": right.original_name,
                "distance": (left.phash ^ right.phash).bit_count(),
            }
            for left, right in removed_near[:50]
        ],
    }


def assign_markovka_splits(candidates: list[Candidate]) -> dict[str, str]:
    assignment = {}
    by_target: dict[str, list[Candidate]] = defaultdict(list)
    for candidate in candidates:
        if candidate.source == "markovka_car_dataset_v3_provider_apache_2":
            by_target[candidate.target].append(candidate)
    for target, rows in by_target.items():
        ordered = sorted(rows, key=lambda item: stable_hash("s7-color-v8-markovka", target, item.sha256))
        validation_end = round(len(ordered) * 0.15)
        calibration_end = validation_end + round(len(ordered) * 0.15)
        for index, candidate in enumerate(ordered):
            split = "validation" if index < validation_end else "calibration" if index < calibration_end else "train"
            assignment[candidate.sha256] = split
    return assignment


def safe_suffix(name: str) -> str:
    suffix = Path(name).suffix.lower()
    return suffix if suffix in {".jpg", ".jpeg", ".png", ".webp", ".bmp"} else ".jpg"


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


def main() -> None:
    parser = argparse.ArgumentParser()
    parser.add_argument("--v7-manifest", type=Path, required=True)
    parser.add_argument("--commons-manifest", type=Path, required=True)
    parser.add_argument("--markovka-archive", type=Path, required=True)
    parser.add_argument("--v7-final-manifest", type=Path, required=True)
    parser.add_argument("--output-dir", type=Path, required=True)
    args = parser.parse_args()
    output = args.output_dir.resolve()
    output.mkdir(parents=True, exist_ok=True)
    if any(output.iterdir()):
        raise FileExistsError(f"Output directory must be empty: {output}")

    candidates = list(from_v7_manifest(args.v7_manifest.resolve()))
    candidates.extend(from_commons_manifest(args.commons_manifest.resolve()))
    candidates.extend(from_markovka_archive(args.markovka_archive.resolve()))
    raw_count = len(candidates)
    retained, duplicate_audit = remove_duplicates(candidates)
    markovka_splits = assign_markovka_splits(retained)

    retired_v7_hashes = {
        json.loads(line)["sha256"]
        for line in args.v7_final_manifest.read_text(encoding="utf-8").splitlines()
        if line.strip()
    }
    retired_present = sum(candidate.sha256 in retired_v7_hashes for candidate in retained)
    if retired_present < 1200:
        raise ValueError(f"Unexpected retired-v7 coverage after deduplication: {retired_present}")

    rows = []
    for candidate in retained:
        split = markovka_splits.get(candidate.sha256, candidate.proposed_split)
        relative_path = f"images/{candidate.source}/{candidate.target}/{candidate.sha256}{safe_suffix(candidate.original_name)}"
        rows.append(
            {
                "relative_path": relative_path,
                "target": candidate.target,
                "split": split,
                "source": candidate.source,
                "provider_split": candidate.provider_split,
                "provider_label": candidate.provider_label,
                "sha256": candidate.sha256,
                "phash64": f"{candidate.phash:016x}",
                "width": candidate.width,
                "height": candidate.height,
                "license_id": candidate.license_id,
                "license_url": candidate.license_url,
                "source_url": candidate.source_url,
                "attribution": candidate.attribution,
                "retired_v7_final_row": candidate.sha256 in retired_v7_hashes,
            }
        )
    rows.sort(key=lambda row: (row["split"], ONTOLOGY.index(row["target"]), row["source"], row["sha256"]))
    candidate_by_sha = {candidate.sha256: candidate for candidate in retained}

    manifest_path = output / "S7_COLOR_V8_DEVELOPMENT_MANIFEST.csv"
    with manifest_path.open("w", encoding="utf-8", newline="") as stream:
        writer = csv.DictWriter(stream, fieldnames=list(rows[0]))
        writer.writeheader()
        writer.writerows(rows)

    archive_path = output / "S7_COLOR_V8_DEVELOPMENT_DATA.zip"
    if archive_path.exists():
        raise FileExistsError(f"Refusing to overwrite {archive_path}")
    zip_timestamp = (2026, 8, 22, 0, 0, 0)
    with zipfile.ZipFile(archive_path, "w", compression=zipfile.ZIP_STORED, allowZip64=True) as archive:
        for row in rows:
            info = zipfile.ZipInfo(row["relative_path"], date_time=zip_timestamp)
            info.compress_type = zipfile.ZIP_STORED
            info.external_attr = 0o100644 << 16
            archive.writestr(info, candidate_by_sha[row["sha256"]].read_payload())
        info = zipfile.ZipInfo(manifest_path.name, date_time=zip_timestamp)
        info.compress_type = zipfile.ZIP_DEFLATED
        info.external_attr = 0o100644 << 16
        archive.writestr(info, manifest_path.read_bytes(), compress_type=zipfile.ZIP_DEFLATED, compresslevel=9)

    counts = Counter((row["split"], row["target"]) for row in rows)
    source_counts = Counter((row["source"], row["split"]) for row in rows)
    registry = {
        "schema_version": "8.0.0-development",
        "created_at_utc": utc_now(),
        "status": "DEVELOPMENT_ONLY_NEW_FINAL_NOT_CREATED",
        "ontology": list(ONTOLOGY),
        "seed": SEED,
        "raw_candidates": raw_count,
        "retained_rows": len(rows),
        "counts_by_split_and_target": {
            split: {target: int(counts.get((split, target), 0)) for target in ONTOLOGY}
            for split in ("train", "validation", "calibration")
        },
        "counts_by_source_and_split": {
            source: {split: int(source_counts.get((source, split), 0)) for split in ("train", "validation", "calibration")}
            for source in SOURCE_ORDER
        },
        "duplicate_audit": duplicate_audit,
        "retired_v7_final": {
            "role_in_v8": "development_only",
            "manifest_sha256": sha256_file(args.v7_final_manifest.resolve()),
            "manifest_rows": len(retired_v7_hashes),
            "retained_after_deduplication": retired_present,
            "never_eligible_for_v8_final": True,
        },
        "excluded_sources": {
            "brasarkaya_vehicle_color_dataset": "unknown licence; evaluation-only in v7 and excluded from v8 fit",
            "VeRi": "usage restriction; architecture research reference only",
            "UFPR-VCR": "non-commercial/restricted source",
        },
        "input_hashes": {
            "v7_manifest": sha256_file(args.v7_manifest.resolve()),
            "commons_manifest": sha256_file(args.commons_manifest.resolve()),
            "markovka_archive": sha256_file(args.markovka_archive.resolve()),
        },
        "artifacts": {
            manifest_path.name: {"sha256": sha256_file(manifest_path), "bytes": manifest_path.stat().st_size},
            archive_path.name: {"sha256": sha256_file(archive_path), "bytes": archive_path.stat().st_size},
        },
        "new_final": {
            "status": "NOT_CREATED",
            "required": True,
            "must_be_prediction_blind_and_one_shot": True,
        },
    }
    registry_path = output / "S7_COLOR_V8_DEVELOPMENT_REGISTRY.json"
    atomic_json(registry_path, registry)
    print(json.dumps({"archive": str(archive_path), "archive_sha256": registry["artifacts"][archive_path.name]["sha256"], "rows": len(rows), "status": registry["status"]}, indent=2))


if __name__ == "__main__":
    main()
