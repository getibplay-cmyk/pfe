#!/usr/bin/env python3
"""Convert approved HITL Supervisely polygons to one-class COCO detection data.

The legacy patch manifest is used only as an immutable source-image split map.
The default CLI refuses the legacy test split and verifies every raw image hash.
"""

from __future__ import annotations

import argparse
import json
import shutil
from collections import Counter
from pathlib import Path
from typing import Any, Sequence

from scripts.intelligence.vehicle_damage_v2.protocol import (
    SourceImage,
    assert_training_splits,
    derive_source_images,
    file_sha256,
    load_csv,
    polygon_area,
    validate_coco_document,
)


DAMAGE_CLASSES = frozenset(
    {
        "Dent",
        "Cracked",
        "Scratch",
        "Flaking",
        "Broken part",
        "Paint chip",
        "Missing part",
        "Corrosion",
    }
)


def _dimensions(payload: dict[str, Any]) -> tuple[int, int]:
    size = payload.get("size")
    if not isinstance(size, dict):
        raise ValueError("Annotation Supervisely sans bloc size.")
    width, height = size.get("width"), size.get("height")
    if not isinstance(width, int) or not isinstance(height, int) or min(width, height) < 1:
        raise ValueError("Dimensions Supervisely invalides.")
    return width, height


def _object_annotation(
    obj: dict[str, Any], image_id: int, annotation_id: int, width: int, height: int
) -> dict[str, Any] | None:
    class_name = obj.get("classTitle")
    if class_name not in DAMAGE_CLASSES:
        raise ValueError(f"Classe dommage inconnue: {class_name!r}")
    points = obj.get("points")
    exterior = points.get("exterior") if isinstance(points, dict) else None
    if not isinstance(exterior, list) or len(exterior) < 3:
        return None

    clipped: list[list[float]] = []
    for point in exterior:
        if not isinstance(point, list) or len(point) != 2:
            raise ValueError("Point polygonal Supervisely invalide.")
        x = min(max(float(point[0]), 0.0), float(width))
        y = min(max(float(point[1]), 0.0), float(height))
        clipped.append([x, y])
    area = polygon_area(clipped)
    xs = [point[0] for point in clipped]
    ys = [point[1] for point in clipped]
    x0, y0, x1, y1 = min(xs), min(ys), max(xs), max(ys)
    if area <= 0 or x1 <= x0 or y1 <= y0:
        return None
    return {
        "id": annotation_id,
        "image_id": image_id,
        "category_id": 0,
        "bbox": [x0, y0, x1 - x0, y1 - y0],
        "area": area,
        "iscrowd": 0,
        "segmentation": [[coordinate for point in clipped for coordinate in point]],
        "damage_type": class_name,
    }


def build_split(
    records: Sequence[SourceImage],
    split: str,
    hitl_root: Path,
    output_root: Path,
) -> dict[str, int]:
    images_dir = output_root / "images" / split
    annotations_dir = output_root / "annotations"
    images_dir.mkdir(parents=True, exist_ok=True)
    annotations_dir.mkdir(parents=True, exist_ok=True)

    images: list[dict[str, Any]] = []
    annotations: list[dict[str, Any]] = []
    class_counts: Counter[str] = Counter()
    annotation_id = 1
    for image_id, record in enumerate(sorted(records, key=lambda item: item.sha256), start=1):
        image_path = hitl_root / "Car parts dataset" / "File1" / "img" / record.image_name
        annotation_path = hitl_root / record.annotation_path
        if not image_path.is_file() or not annotation_path.is_file():
            raise FileNotFoundError(f"Image ou annotation HITL absente pour {record.sha256[:12]}.")
        if file_sha256(image_path) != record.sha256:
            raise ValueError(f"Empreinte brute différente pour {record.sha256[:12]}.")

        payload = json.loads(annotation_path.read_text(encoding="utf-8-sig"))
        width, height = _dimensions(payload)
        suffix = image_path.suffix.lower() or ".img"
        destination_name = f"{record.sha256[:24]}{suffix}"
        destination = images_dir / destination_name
        if destination.exists():
            if file_sha256(destination) != record.sha256:
                raise ValueError(f"Collision de fichier préparé: {destination_name}")
        else:
            shutil.copy2(image_path, destination)

        images.append(
            {
                "id": image_id,
                "file_name": f"{split}/{destination_name}",
                "width": width,
                "height": height,
                "source_sha256": record.sha256,
                "split": split,
            }
        )
        objects = payload.get("objects")
        if not isinstance(objects, list):
            raise ValueError("Annotation Supervisely sans liste objects.")
        for obj in objects:
            if not isinstance(obj, dict):
                raise ValueError("Objet Supervisely invalide.")
            annotation = _object_annotation(
                obj, image_id, annotation_id, width, height
            )
            if annotation is None:
                continue
            annotations.append(annotation)
            class_counts[str(annotation["damage_type"])] += 1
            annotation_id += 1

    document = {
        "info": {
            "description": "RentFleet damage v2 HITL detection split",
            "protocol_version": "2.0.0",
            "split": split,
        },
        "licenses": [
            {
                "id": 1,
                "name": "CC0-1.0",
                "url": "https://creativecommons.org/publicdomain/zero/1.0/",
            }
        ],
        "categories": [{"id": 0, "name": "visible_damage"}],
        "images": images,
        "annotations": annotations,
    }
    counts = validate_coco_document(document, split)
    annotation_file = annotations_dir / f"instances_{split}.json"
    annotation_file.write_text(
        json.dumps(document, ensure_ascii=False, indent=2, sort_keys=True) + "\n",
        encoding="utf-8",
    )
    return {
        **counts,
        "damage_types": len(class_counts),
        "image_bytes": sum(path.stat().st_size for path in images_dir.iterdir()),
    }


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--manifest", type=Path, required=True)
    parser.add_argument("--hitl-root", type=Path, required=True)
    parser.add_argument("--output-root", type=Path, required=True)
    parser.add_argument(
        "--splits",
        nargs="+",
        default=["train", "validation", "calibration"],
    )
    parser.add_argument("--smoke-images-per-split", type=int, default=0)
    args = parser.parse_args()

    splits = assert_training_splits(args.splits)
    if args.smoke_images_per_split < 0:
        raise ValueError("--smoke-images-per-split doit être >= 0")
    source_images = derive_source_images(load_csv(args.manifest))
    report: dict[str, Any] = {
        "protocol_version": "2.0.0",
        "legacy_test_read": False,
        "splits": {},
    }
    for split in splits:
        records = [record for record in source_images.values() if record.split == split]
        if args.smoke_images_per_split:
            records = sorted(records, key=lambda item: item.sha256)[
                : args.smoke_images_per_split
            ]
        report["splits"][split] = build_split(
            records, split, args.hitl_root, args.output_root
        )

    report_path = args.output_root / "preparation_report.json"
    report_path.write_text(
        json.dumps(report, ensure_ascii=False, indent=2, sort_keys=True) + "\n",
        encoding="utf-8",
    )
    print(json.dumps(report, ensure_ascii=False, sort_keys=True))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
