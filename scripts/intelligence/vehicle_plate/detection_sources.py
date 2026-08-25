#!/usr/bin/env python3
"""Prepare audited, detection-only development sources for Moroccan ANPR.

This module deliberately does not expose an OCR target.  CCPD filenames contain
Chinese plate sequences, but only their bounding-box field is parsed.  Open
Images candidates remain disabled until every selected image has an explicit
per-item licence/attribution review.
"""

from __future__ import annotations

import argparse
import csv
import hashlib
import json
import math
import os
import shutil
import tempfile
from collections import Counter, defaultdict
from dataclasses import asdict, dataclass
from pathlib import Path
from typing import Any, Iterable, Mapping, Sequence


REPOSITORY_ROOT = Path(__file__).resolve().parents[3]

from scripts.intelligence.vehicle_plate.protocol import (
    ProtocolError,
    file_sha256,
    sha256sum_lines,
)


SOURCE_PREPARATION_VERSION = "1.0.1"
CCPD_SOURCE_ID = "ccpd_official_mit"
CCPD_LICENSE_ID = "MIT"
CCPD_CANONICAL_URL = "https://github.com/detectRecog/CCPD"
CCPD_PINNED_REVISION = "02aaea15137c4d2fe662e57d257c6822356e9304"
CCPD_OFFICIAL_ARCHIVE_URL = (
    "https://drive.google.com/file/d/1rdEsCUcIUaYOVRkx5IMTRNA7PcGMmSgc/view"
)
OPEN_IMAGES_SOURCE_ID = "open_images_v7_vehicle_registration_plate"
OPEN_IMAGES_PLATE_MID = "/m/01jfm_"
OPEN_IMAGES_ANNOTATION_LICENSE = "CC-BY-4.0"
OPEN_IMAGES_CANONICAL_URL = (
    "https://storage.googleapis.com/openimages/web/download_v7.html"
)
OPEN_IMAGES_ALLOWED_IMAGE_LICENSES = frozenset(
    {
        "http://creativecommons.org/licenses/by/2.0/",
        "https://creativecommons.org/licenses/by/2.0/",
    }
)
OPEN_IMAGES_ALLOWED_BOX_SOURCES = frozenset({"xclick"})
DEVELOPMENT_SPLITS = ("train", "validation", "calibration")
COCO_CATEGORIES = [{"id": 1, "name": "plate", "supercategory": "plate"}]


@dataclass(frozen=True)
class PixelBox:
    x_min: int
    y_min: int
    x_max: int
    y_max: int

    @property
    def width(self) -> int:
        return self.x_max - self.x_min

    @property
    def height(self) -> int:
        return self.y_max - self.y_min

    def validate(self, *, image_width: int | None = None, image_height: int | None = None) -> None:
        if min(self.x_min, self.y_min) < 0 or self.width <= 0 or self.height <= 0:
            raise ProtocolError(f"Boîte CCPD invalide: {self}.")
        if image_width is not None and self.x_max > image_width:
            raise ProtocolError(f"Boîte CCPD hors largeur image: {self} > {image_width}.")
        if image_height is not None and self.y_max > image_height:
            raise ProtocolError(f"Boîte CCPD hors hauteur image: {self} > {image_height}.")

    def as_coco(self) -> list[int]:
        return [self.x_min, self.y_min, self.width, self.height]


@dataclass(frozen=True)
class CcpdFilenameAnnotation:
    area_ratio: int
    horizontal_tilt: int
    vertical_tilt: int
    box: PixelBox
    vertices: tuple[tuple[int, int], ...]
    brightness: int
    blurriness: int
    ignored_sequence_field: bool = True


@dataclass(frozen=True)
class PreparedDetectionBundle:
    output_dir: Path
    images: int
    groups: int
    near_duplicate_pairs: int
    manifest_sha256: str

    def as_dict(self) -> dict[str, object]:
        return {
            "output_dir": os.fspath(self.output_dir),
            "images": self.images,
            "groups": self.groups,
            "near_duplicate_pairs": self.near_duplicate_pairs,
            "manifest_sha256": self.manifest_sha256,
            "final_test_opened": False,
            "qualification_claim": False,
        }


def _parse_point(value: str, *, field: str) -> tuple[int, int]:
    parts = value.split("&")
    if len(parts) != 2:
        raise ProtocolError(f"Point CCPD invalide dans {field}: {value!r}.")
    try:
        x, y = (int(part) for part in parts)
    except ValueError as error:
        raise ProtocolError(f"Coordonnée CCPD non entière dans {field}: {value!r}.") from error
    if x < 0 or y < 0:
        raise ProtocolError(f"Coordonnée CCPD négative dans {field}: {value!r}.")
    return x, y


def parse_ccpd_filename(path: str | Path) -> CcpdFilenameAnnotation:
    """Read geometry fields without decoding the embedded Chinese sequence."""

    stem = Path(path).stem
    fields = stem.split("-")
    if len(fields) != 7:
        raise ProtocolError(
            f"Nom CCPD inattendu ({len(fields)} champs au lieu de 7): {Path(path).name}."
        )
    try:
        area_ratio = int(fields[0])
        horizontal_tilt, vertical_tilt = (int(value) for value in fields[1].split("_"))
        brightness = int(fields[5])
        blurriness = int(fields[6])
    except (TypeError, ValueError) as error:
        raise ProtocolError(f"Métadonnée numérique CCPD invalide: {Path(path).name}.") from error

    box_points = fields[2].split("_")
    if len(box_points) != 2:
        raise ProtocolError(f"Champ boîte CCPD invalide: {fields[2]!r}.")
    left_top = _parse_point(box_points[0], field="bounding_box")
    right_bottom = _parse_point(box_points[1], field="bounding_box")
    box = PixelBox(left_top[0], left_top[1], right_bottom[0], right_bottom[1])
    box.validate()

    vertex_values = fields[3].split("_")
    if len(vertex_values) != 4:
        raise ProtocolError(f"Champ sommets CCPD invalide: {fields[3]!r}.")
    vertices = tuple(_parse_point(value, field="vertices") for value in vertex_values)

    # fields[4] is intentionally never split or decoded.  It is Chinese OCR
    # truth and is outside the only authorised task: plate localisation.
    return CcpdFilenameAnnotation(
        area_ratio=area_ratio,
        horizontal_tilt=horizontal_tilt,
        vertical_tilt=vertical_tilt,
        box=box,
        vertices=vertices,
        brightness=brightness,
        blurriness=blurriness,
    )


def ccpd_partition(path: Path, root: Path) -> str:
    relative_parts = path.relative_to(root).parts[:-1]
    candidates = [part.lower() for part in relative_parts if part.lower().startswith("ccpd")]
    return candidates[-1] if candidates else "ccpd_unknown"


def deterministic_development_split(group_id: str, *, seed: int) -> str:
    digest = hashlib.sha256(f"{seed}:{group_id}".encode("utf-8")).digest()
    bucket = int.from_bytes(digest[:8], "big") % 100
    if bucket < 80:
        return "train"
    if bucket < 90:
        return "validation"
    return "calibration"


def _image_fingerprint(path: Path) -> tuple[int, int, str, int]:
    try:
        from PIL import Image
    except ImportError as error:
        raise ProtocolError("Pillow est requis pour préparer les images CCPD.") from error
    with Image.open(path) as opened:
        opened.verify()
    with Image.open(path) as opened:
        image = opened.convert("L")
        width, height = image.size
        resized = image.resize((9, 8))
        flattened = getattr(resized, "get_flattened_data", resized.getdata)
        pixels = list(flattened())
    bits = 0
    for row in range(8):
        offset = row * 9
        for column in range(8):
            bits = (bits << 1) | int(pixels[offset + column] > pixels[offset + column + 1])
    return width, height, file_sha256(path), bits


class _UnionFind:
    def __init__(self, count: int) -> None:
        self.parent = list(range(count))

    def find(self, item: int) -> int:
        while self.parent[item] != item:
            self.parent[item] = self.parent[self.parent[item]]
            item = self.parent[item]
        return item

    def union(self, left: int, right: int) -> None:
        left_root, right_root = self.find(left), self.find(right)
        if left_root != right_root:
            self.parent[max(left_root, right_root)] = min(left_root, right_root)


def _near_duplicate_groups(
    fingerprints: Sequence[tuple[str, int]], *, maximum_hamming_distance: int
) -> tuple[list[str], list[tuple[int, int, int]]]:
    if not 0 <= maximum_hamming_distance <= 4:
        raise ProtocolError("La distance perceptuelle doit être comprise entre 0 et 4.")
    union_find = _UnionFind(len(fingerprints))
    exact: dict[str, int] = {}
    for index, (sha256, _) in enumerate(fingerprints):
        previous = exact.setdefault(sha256, index)
        if previous != index:
            union_find.union(previous, index)

    # Five disjoint bit bands guarantee that hashes at Hamming distance <= 4
    # share at least one intact band.  Candidate pairs are then checked exactly.
    buckets: dict[tuple[int, int], list[int]] = defaultdict(list)
    pairs: set[tuple[int, int]] = set()
    widths = (13, 13, 13, 13, 12)
    for index, (_, perceptual_hash) in enumerate(fingerprints):
        shift = 64
        candidates: set[int] = set()
        for band_index, width in enumerate(widths):
            shift -= width
            value = (perceptual_hash >> shift) & ((1 << width) - 1)
            candidates.update(buckets[(band_index, value)])
            buckets[(band_index, value)].append(index)
        for other in candidates:
            distance = (perceptual_hash ^ fingerprints[other][1]).bit_count()
            if distance <= maximum_hamming_distance:
                pairs.add((other, index))
                union_find.union(other, index)

    members: dict[int, list[int]] = defaultdict(list)
    for index in range(len(fingerprints)):
        members[union_find.find(index)].append(index)
    cluster_id_by_index: list[str] = [""] * len(fingerprints)
    for indices in members.values():
        stable_key = "|".join(sorted(fingerprints[index][0] for index in indices))
        cluster_id = "ccpd-scene-" + hashlib.sha256(stable_key.encode("ascii")).hexdigest()[:16]
        for index in indices:
            cluster_id_by_index[index] = cluster_id
    pair_details = [
        (left, right, (fingerprints[left][1] ^ fingerprints[right][1]).bit_count())
        for left, right in sorted(pairs)
    ]
    return cluster_id_by_index, pair_details


def _select_ccpd_images(
    root: Path,
    *,
    seed: int,
    maximum_per_partition: int,
) -> tuple[list[Path], Counter[str], Counter[str]]:
    if maximum_per_partition < 1:
        raise ProtocolError("maximum_per_partition doit être >= 1.")
    by_partition: dict[str, list[Path]] = defaultdict(list)
    annotated_partition_counts: Counter[str] = Counter()
    ignored_unannotated_partition_counts: Counter[str] = Counter()
    for path in root.rglob("*"):
        if path.is_file() and path.suffix.lower() in {".jpg", ".jpeg"}:
            partition = ccpd_partition(path, root)
            # The official CCPD2019 archive includes ccpd_np, a negative-image
            # partition whose simple numeric filenames carry no plate geometry.
            # It cannot be materialised as a positive one-box detection sample.
            if partition == "ccpd_np":
                ignored_unannotated_partition_counts[partition] += 1
                continue
            parse_ccpd_filename(path)
            by_partition[partition].append(path)
            annotated_partition_counts[partition] += 1
    if not by_partition:
        raise ProtocolError(f"Aucune image CCPD trouvée sous {root}.")
    selected: list[Path] = []
    for partition, paths in sorted(by_partition.items()):
        ordered = sorted(
            paths,
            key=lambda path: hashlib.sha256(
                f"{seed}:{partition}:{path.relative_to(root).as_posix()}".encode("utf-8")
            ).digest(),
        )
        selected.extend(ordered[:maximum_per_partition])
    return (
        sorted(selected, key=lambda path: path.relative_to(root).as_posix()),
        annotated_partition_counts,
        ignored_unannotated_partition_counts,
    )


def _assert_mit_license(path: Path) -> None:
    if not path.is_file():
        raise FileNotFoundError(path)
    text = path.read_text(encoding="utf-8", errors="replace")
    required = (
        "MIT License",
        "Copyright (c) 2017 CCPD",
        "Permission is hereby granted, free of charge",
    )
    if any(fragment not in text for fragment in required):
        raise ProtocolError("Le fichier fourni ne prouve pas la licence MIT officielle de CCPD.")


def prepare_ccpd_detection_bundle(
    *,
    input_root: str | Path,
    output_dir: str | Path,
    license_path: str | Path,
    seed: int = 20260825,
    maximum_per_partition: int = 1024,
    maximum_hamming_distance: int = 4,
) -> PreparedDetectionBundle:
    source_root = Path(input_root).resolve()
    destination = Path(output_dir).resolve()
    license_file = Path(license_path).resolve()
    if not source_root.is_dir():
        raise FileNotFoundError(source_root)
    if destination.exists():
        raise FileExistsError(destination)
    _assert_mit_license(license_file)

    (
        selected,
        annotated_partition_counts,
        ignored_unannotated_partition_counts,
    ) = _select_ccpd_images(
        source_root, seed=int(seed), maximum_per_partition=int(maximum_per_partition)
    )
    records: list[dict[str, Any]] = []
    for path in selected:
        annotation = parse_ccpd_filename(path)
        width, height, sha256, perceptual_hash = _image_fingerprint(path)
        annotation.box.validate(image_width=width, image_height=height)
        relative_path = path.relative_to(source_root).as_posix()
        sample_id = "ccpd-" + hashlib.sha256(relative_path.encode("utf-8")).hexdigest()[:20]
        records.append(
            {
                "source_path": path,
                "source_relative_path": relative_path,
                "source_partition": ccpd_partition(path, source_root),
                "sample_id": sample_id,
                "width": width,
                "height": height,
                "sha256": sha256,
                "perceptual_hash": perceptual_hash,
                "annotation": annotation,
            }
        )

    group_ids, near_pairs = _near_duplicate_groups(
        [(str(record["sha256"]), int(record["perceptual_hash"])) for record in records],
        maximum_hamming_distance=int(maximum_hamming_distance),
    )
    for record, group_id in zip(records, group_ids):
        record["group_id"] = group_id
        record["split"] = deterministic_development_split(group_id, seed=int(seed))

    split_counts = Counter(str(record["split"]) for record in records)
    missing = [split for split in DEVELOPMENT_SPLITS if split_counts[split] == 0]
    if missing:
        raise ProtocolError("Split CCPD vide après sélection: " + ", ".join(missing))

    destination.parent.mkdir(parents=True, exist_ok=True)
    with tempfile.TemporaryDirectory(prefix=f".{destination.name}-", dir=destination.parent) as temp:
        root = Path(temp) / "bundle"
        root.mkdir()
        licence_output = root / "licenses" / "CCPD-MIT.txt"
        licence_output.parent.mkdir(parents=True)
        shutil.copyfile(license_file, licence_output)

        coco_documents = {
            split: {"images": [], "annotations": [], "categories": COCO_CATEGORIES}
            for split in DEVELOPMENT_SPLITS
        }
        manifest_rows: list[dict[str, object]] = []
        for image_id, record in enumerate(
            sorted(records, key=lambda item: str(item["sample_id"])), start=1
        ):
            split = str(record["split"])
            suffix = Path(record["source_path"]).suffix.lower()
            image_relative = Path("images") / split / f"{record['sample_id']}{suffix}"
            image_output = root / image_relative
            image_output.parent.mkdir(parents=True, exist_ok=True)
            shutil.copyfile(Path(record["source_path"]), image_output)
            if file_sha256(image_output) != record["sha256"]:
                raise ProtocolError(f"Copie CCPD non fidèle: {image_output}.")
            annotation: CcpdFilenameAnnotation = record["annotation"]
            coco_documents[split]["images"].append(
                {
                    "id": image_id,
                    "file_name": image_relative.as_posix(),
                    "width": int(record["width"]),
                    "height": int(record["height"]),
                    "sample_id": record["sample_id"],
                    "group_id": record["group_id"],
                    "source_id": CCPD_SOURCE_ID,
                    "source_partition": record["source_partition"],
                }
            )
            coco_documents[split]["annotations"].append(
                {
                    "id": image_id,
                    "image_id": image_id,
                    "category_id": 1,
                    "bbox": annotation.box.as_coco(),
                    "area": annotation.box.width * annotation.box.height,
                    "iscrowd": 0,
                }
            )
            manifest_rows.append(
                {
                    "sample_id": record["sample_id"],
                    "image_path": image_relative.as_posix(),
                    "group_id": record["group_id"],
                    "split": split,
                    "holdout_role": "development",
                    "task": "detection",
                    "source_id": CCPD_SOURCE_ID,
                    "source_partition": record["source_partition"],
                    "source_relative_path": record["source_relative_path"],
                    "source_url": CCPD_CANONICAL_URL,
                    "license_id": CCPD_LICENSE_ID,
                    "license_proof": "licenses/CCPD-MIT.txt",
                    "sha256": record["sha256"],
                    "dhash64": f"{int(record['perceptual_hash']):016x}",
                    "bbox_xywh": ",".join(str(value) for value in annotation.box.as_coco()),
                    "ocr_truth_used": "false",
                    "qualification_claim": "false",
                }
            )

        annotation_paths: dict[str, str] = {}
        for split, document in coco_documents.items():
            annotation_path = root / "annotations" / f"instances_{split}.json"
            annotation_path.parent.mkdir(parents=True, exist_ok=True)
            annotation_path.write_text(
                json.dumps(document, ensure_ascii=False, sort_keys=True, indent=2) + "\n",
                encoding="utf-8",
            )
            annotation_paths[split] = annotation_path.relative_to(root).as_posix()

        manifest_path = root / "manifest.csv"
        fieldnames = list(manifest_rows[0])
        with manifest_path.open("w", encoding="utf-8", newline="") as handle:
            writer = csv.DictWriter(handle, fieldnames=fieldnames, lineterminator="\n")
            writer.writeheader()
            writer.writerows(manifest_rows)

        pair_rows = [
            {
                "left_sample_id": records[left]["sample_id"],
                "right_sample_id": records[right]["sample_id"],
                "hamming_distance": distance,
                "shared_group_id": group_ids[left],
            }
            for left, right, distance in near_pairs
        ]
        pair_path = root / "near_duplicate_review.csv"
        with pair_path.open("w", encoding="utf-8", newline="") as handle:
            writer = csv.DictWriter(
                handle,
                fieldnames=(
                    "left_sample_id",
                    "right_sample_id",
                    "hamming_distance",
                    "shared_group_id",
                ),
                lineterminator="\n",
            )
            writer.writeheader()
            writer.writerows(pair_rows)

        report = {
            "schema_version": "1.0.0",
            "preparation_version": SOURCE_PREPARATION_VERSION,
            "status": "development_detection_source_bundle_not_qualified",
            "source": {
                "source_id": CCPD_SOURCE_ID,
                "canonical_url": CCPD_CANONICAL_URL,
                "official_archive_url": CCPD_OFFICIAL_ARCHIVE_URL,
                "pinned_repository_revision": CCPD_PINNED_REVISION,
                "license_id": CCPD_LICENSE_ID,
                "license_sha256": file_sha256(licence_output),
            },
            "configuration": {
                "seed": int(seed),
                "maximum_per_partition": int(maximum_per_partition),
                "maximum_dhash_hamming_distance": int(maximum_hamming_distance),
                "split_policy": "group_hash_80_10_10",
            },
            "artifacts": {
                "images": len(records),
                "groups": len(set(group_ids)),
                "split_counts": dict(sorted(split_counts.items())),
                "source_partition_counts": dict(
                    sorted(Counter(str(record["source_partition"]) for record in records).items())
                ),
                "near_duplicate_pairs": len(near_pairs),
                "manifest": "manifest.csv",
                "manifest_sha256": file_sha256(manifest_path),
                "near_duplicate_review": "near_duplicate_review.csv",
                "coco_annotations": annotation_paths,
            },
            "source_discovery": {
                "jpeg_images": (
                    sum(annotated_partition_counts.values())
                    + sum(ignored_unannotated_partition_counts.values())
                ),
                "annotated_geometry_images": sum(annotated_partition_counts.values()),
                "ignored_unannotated_images": sum(
                    ignored_unannotated_partition_counts.values()
                ),
                "annotated_partition_counts": dict(
                    sorted(annotated_partition_counts.items())
                ),
                "ignored_unannotated_partition_counts": dict(
                    sorted(ignored_unannotated_partition_counts.items())
                ),
            },
            "safeguards": {
                "detection_boxes_only": True,
                "unannotated_negative_images_used_as_positive": False,
                "ccpd_sequence_field_parsed": False,
                "ccpd_sequence_field_used_as_ocr_truth": False,
                "contains_test_split": False,
                "final_test_opened": False,
                "qualification_claim": False,
                "saas_integration_allowed": False,
            },
            "limits": [
                "CCPD is a Chinese inter-domain development source, not Moroccan qualification evidence.",
                "The unannotated ccpd_np negative-image partition is excluded from the positive one-box bundle.",
                "The original upstream test subsets are consumed as development data only.",
                "A new source-disjoint Moroccan holdout remains mandatory after model and threshold freeze.",
            ],
        }
        report_path = root / "generation_report.json"
        report_path.write_text(
            json.dumps(report, ensure_ascii=False, sort_keys=True, indent=2) + "\n",
            encoding="utf-8",
        )
        checksum_candidates = [
            path for path in root.rglob("*") if path.is_file() and path.name != "SHA256SUMS"
        ]
        (root / "SHA256SUMS").write_text(
            "\n".join(sha256sum_lines(checksum_candidates, root)) + "\n",
            encoding="utf-8",
        )
        manifest_sha256 = file_sha256(manifest_path)
        root.replace(destination)

    return PreparedDetectionBundle(
        output_dir=destination,
        images=len(records),
        groups=len(set(group_ids)),
        near_duplicate_pairs=len(near_pairs),
        manifest_sha256=manifest_sha256,
    )


def _required_value(row: Mapping[str, str], name: str, *, source: str) -> str:
    value = str(row.get(name) or "").strip()
    if not value:
        raise ProtocolError(f"Champ {name!r} absent dans {source}.")
    return value


def prepare_open_images_candidate_manifest(
    *,
    boxes_csv: str | Path,
    image_metadata_csv: str | Path,
    output_path: str | Path,
    maximum_images: int = 2048,
    seed: int = 20260825,
) -> dict[str, object]:
    """Create a no-download candidate list pending per-image licence review."""

    boxes_path = Path(boxes_csv).resolve()
    metadata_path = Path(image_metadata_csv).resolve()
    destination = Path(output_path).resolve()
    if destination.exists():
        raise FileExistsError(destination)
    if maximum_images < 1:
        raise ProtocolError("maximum_images doit être >= 1.")

    boxes_by_image: dict[str, list[list[float]]] = defaultdict(list)
    box_source_counts: Counter[str] = Counter()
    with boxes_path.open("r", encoding="utf-8", newline="") as handle:
        reader = csv.DictReader(handle)
        for row in reader:
            if str(row.get("LabelName") or "") != OPEN_IMAGES_PLATE_MID:
                continue
            source = str(row.get("Source") or "")
            if source not in OPEN_IMAGES_ALLOWED_BOX_SOURCES:
                continue
            if any(str(row.get(field) or "0") != "0" for field in ("IsGroupOf", "IsDepiction", "IsInside")):
                continue
            try:
                x_min = float(row["XMin"])
                x_max = float(row["XMax"])
                y_min = float(row["YMin"])
                y_max = float(row["YMax"])
            except (KeyError, TypeError, ValueError) as error:
                raise ProtocolError("Coordonnées Open Images invalides.") from error
            if not all(math.isfinite(value) for value in (x_min, x_max, y_min, y_max)):
                raise ProtocolError("Coordonnée Open Images non finie.")
            if not (0 <= x_min < x_max <= 1 and 0 <= y_min < y_max <= 1):
                raise ProtocolError("Boîte Open Images hors intervalle normalisé.")
            image_id = _required_value(row, "ImageID", source=os.fspath(boxes_path))
            boxes_by_image[image_id].append([x_min, y_min, x_max, y_max])
            box_source_counts[source] += 1
    if not boxes_by_image:
        raise ProtocolError("Aucune boîte manuelle Vehicle registration plate trouvée.")

    selected_ids = sorted(
        boxes_by_image,
        key=lambda image_id: hashlib.sha256(f"{seed}:{image_id}".encode("ascii")).digest(),
    )[: int(maximum_images)]
    selected_set = set(selected_ids)
    metadata_by_image: dict[str, Mapping[str, str]] = {}
    with metadata_path.open("r", encoding="utf-8", newline="") as handle:
        reader = csv.DictReader(handle)
        for row in reader:
            image_id = str(row.get("ImageID") or "")
            if image_id in selected_set:
                metadata_by_image[image_id] = row
    missing_metadata = sorted(selected_set - metadata_by_image.keys())
    if missing_metadata:
        raise ProtocolError(
            f"Métadonnées Open Images absentes pour {len(missing_metadata)} images."
        )

    rows: list[dict[str, object]] = []
    for image_id in selected_ids:
        metadata = metadata_by_image[image_id]
        license_url = _required_value(
            metadata, "License", source=os.fspath(metadata_path)
        )
        if license_url not in OPEN_IMAGES_ALLOWED_IMAGE_LICENSES:
            continue
        original_url = _required_value(
            metadata, "OriginalURL", source=os.fspath(metadata_path)
        )
        landing_url = _required_value(
            metadata, "OriginalLandingURL", source=os.fspath(metadata_path)
        )
        author = _required_value(metadata, "Author", source=os.fspath(metadata_path))
        rows.append(
            {
                "image_id": image_id,
                "source_id": OPEN_IMAGES_SOURCE_ID,
                "source_split": "validation_remapped_to_development",
                "plate_boxes_normalized_json": json.dumps(
                    boxes_by_image[image_id], separators=(",", ":")
                ),
                "original_url": original_url,
                "original_landing_url": landing_url,
                "license_url": license_url,
                "author": author,
                "author_profile_url": str(metadata.get("AuthorProfileURL") or ""),
                "title": str(metadata.get("Title") or ""),
                "per_item_license_metadata_passed": "true",
                "external_landing_page_review": "pending",
                "image_download_allowed": "false",
                "training_enabled": "false",
                "ocr_truth_available": "false",
                "final_test_role": "false",
            }
        )
    if not rows:
        raise ProtocolError("Aucun candidat Open Images ne passe les métadonnées CC BY 2.0.")

    destination.parent.mkdir(parents=True, exist_ok=True)
    with destination.open("w", encoding="utf-8", newline="") as handle:
        writer = csv.DictWriter(handle, fieldnames=list(rows[0]), lineterminator="\n")
        writer.writeheader()
        writer.writerows(rows)
    return {
        "schema_version": "1.0.0",
        "preparation_version": SOURCE_PREPARATION_VERSION,
        "status": "candidate_manifest_pending_per_image_license_review",
        "source_id": OPEN_IMAGES_SOURCE_ID,
        "canonical_url": OPEN_IMAGES_CANONICAL_URL,
        "plate_mid": OPEN_IMAGES_PLATE_MID,
        "annotation_license": OPEN_IMAGES_ANNOTATION_LICENSE,
        "rows": len(rows),
        "box_source_counts": dict(sorted(box_source_counts.items())),
        "manifest": os.fspath(destination),
        "manifest_sha256": file_sha256(destination),
        "images_downloaded": 0,
        "training_enabled": False,
        "qualification_claim": False,
        "final_test_opened": False,
    }


def build_parser() -> argparse.ArgumentParser:
    parser = argparse.ArgumentParser(description=__doc__)
    subparsers = parser.add_subparsers(dest="command", required=True)
    ccpd = subparsers.add_parser("ccpd", help="Préparer un bundle CCPD boîtes-only")
    ccpd.add_argument("--input-root", required=True, type=Path)
    ccpd.add_argument("--output-dir", required=True, type=Path)
    ccpd.add_argument("--license", required=True, type=Path)
    ccpd.add_argument("--seed", type=int, default=20260825)
    ccpd.add_argument("--maximum-per-partition", type=int, default=1024)
    ccpd.add_argument("--maximum-dhash-distance", type=int, default=4)

    open_images = subparsers.add_parser(
        "open-images-candidates",
        help="Préparer un manifeste sans téléchargement, en attente de revue licence",
    )
    open_images.add_argument("--boxes-csv", required=True, type=Path)
    open_images.add_argument("--image-metadata-csv", required=True, type=Path)
    open_images.add_argument("--output", required=True, type=Path)
    open_images.add_argument("--maximum-images", type=int, default=2048)
    open_images.add_argument("--seed", type=int, default=20260825)
    return parser


def main(argv: Sequence[str] | None = None) -> int:
    parser = build_parser()
    arguments = parser.parse_args(argv)
    try:
        if arguments.command == "ccpd":
            result: object = prepare_ccpd_detection_bundle(
                input_root=arguments.input_root,
                output_dir=arguments.output_dir,
                license_path=arguments.license,
                seed=arguments.seed,
                maximum_per_partition=arguments.maximum_per_partition,
                maximum_hamming_distance=arguments.maximum_dhash_distance,
            ).as_dict()
        else:
            result = prepare_open_images_candidate_manifest(
                boxes_csv=arguments.boxes_csv,
                image_metadata_csv=arguments.image_metadata_csv,
                output_path=arguments.output,
                maximum_images=arguments.maximum_images,
                seed=arguments.seed,
            )
    except (FileExistsError, FileNotFoundError, ProtocolError) as error:
        parser.exit(2, f"Erreur: {error}\n")
    print(json.dumps(result, ensure_ascii=False, sort_keys=True, indent=2))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
