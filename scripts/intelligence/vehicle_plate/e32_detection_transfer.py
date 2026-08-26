#!/usr/bin/env python3
"""E3.2 balanced CCPD transfer for the Moroccan plate detector.

This module deliberately has no knowledge of private Drive paths or artifact
identifiers.  A private, already-consumed development adapter supplies the
Moroccan records at runtime; CCPD is loaded from the public E3.1 COCO bundle.
The final Moroccan holdout is never accepted by this API.

Selection is conservative: a challenger epoch must remain within a frozen
non-inferiority margin on both Moroccan development anchors and then improve
the preregistered three-domain key.  Calibration happens only after model
selection.  No result produced here is a qualification or a SaaS release.
"""

from __future__ import annotations

import csv
import hashlib
import io
import json
import math
import os
import random
import shutil
import time
from collections import Counter, defaultdict
from pathlib import Path
from typing import Any, Mapping, Sequence


TRAINER_VERSION = "1.0.0"
ARCHITECTURE = "fasterrcnn_resnet50_fpn_v2"
SEED = 20260825
MIN_SIZE = 768
MAX_SIZE = 1280
EPOCHS = 3
BATCH_SIZE = 1
ACCUMULATION_STEPS = 2
LEARNING_RATE = 2.5e-5
WEIGHT_DECAY = 1.0e-4
SCORE_FLOOR = 0.001
IOU_THRESHOLD = 0.50
NONINFERIORITY_MAP50 = 0.02
NONINFERIORITY_RECALL = 0.02
RECALL_CALIBRATION_FLOOR = 0.95
EXPECTED_CCPD_SPLITS = ("train", "validation", "calibration")
PRIVATE_KEYS = (
    "training",
    "primary_validation",
    "secondary_validation",
    "primary_calibration",
    "secondary_calibration",
)
SOURCE_QUOTAS_PER_EPOCH = {
    "primary_moroccan_cc0_v2": 1536,
    "secondary_moroccan_cc_by_sa_v2": 1536,
    "hf_generic_cc_by_4": 1536,
    "ccpd_official_mit": 1536,
}
ANCHOR_DOMAINS = ("moroccan_primary", "moroccan_secondary_consumed")
ALL_DEVELOPMENT_DOMAINS = (*ANCHOR_DOMAINS, "ccpd_public")


class E32ProtocolError(ValueError):
    """Raised when an E3.2 scientific contract is violated."""


def file_sha256(path: str | Path) -> str:
    digest = hashlib.sha256()
    with Path(path).open("rb") as handle:
        for chunk in iter(lambda: handle.read(1024 * 1024), b""):
            digest.update(chunk)
    return digest.hexdigest()


def _read_json(path: str | Path) -> Mapping[str, Any]:
    document = json.loads(Path(path).read_text(encoding="utf-8"))
    if not isinstance(document, dict):
        raise E32ProtocolError(f"Objet JSON attendu: {path}.")
    return document


def _atomic_json(path: Path, payload: Mapping[str, Any]) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    temporary = path.with_name(f".{path.name}.tmp")
    temporary.write_text(
        json.dumps(payload, ensure_ascii=False, sort_keys=True, indent=2) + "\n",
        encoding="utf-8",
    )
    temporary.replace(path)


def _atomic_torch_save(path: Path, payload: Mapping[str, Any]) -> None:
    import torch

    path.parent.mkdir(parents=True, exist_ok=True)
    temporary = path.with_name(f".{path.name}.tmp")
    torch.save(dict(payload), temporary)
    temporary.replace(path)


def _validate_box(box: Sequence[float], *, width: int, height: int, source: str) -> list[float]:
    if len(box) != 4:
        raise E32ProtocolError(f"Boîte invalide dans {source}.")
    x1, y1, x2, y2 = (float(value) for value in box)
    if not all(math.isfinite(value) for value in (x1, y1, x2, y2)):
        raise E32ProtocolError(f"Boîte non finie dans {source}.")
    if x1 < 0 or y1 < 0 or x2 > width or y2 > height or x2 <= x1 or y2 <= y1:
        raise E32ProtocolError(f"Boîte hors limites dans {source}.")
    return [x1, y1, x2, y2]


def _validate_ccpd_document(document: Mapping[str, Any], *, path: Path, split: str) -> None:
    if split not in EXPECTED_CCPD_SPLITS:
        raise E32ProtocolError(f"Split CCPD interdit: {split!r}.")
    if document.get("categories") != [
        {"id": 1, "name": "plate", "supercategory": "plate"}
    ]:
        raise E32ProtocolError(f"Catégorie COCO inattendue: {path}.")
    images = document.get("images")
    annotations = document.get("annotations")
    if not isinstance(images, list) or not images:
        raise E32ProtocolError(f"Aucune image COCO: {path}.")
    if not isinstance(annotations, list) or not annotations:
        raise E32ProtocolError(f"Aucune annotation COCO: {path}.")
    image_ids = {int(item["id"]) for item in images}
    if len(image_ids) != len(images):
        raise E32ProtocolError(f"Identifiants image dupliqués: {path}.")
    annotation_ids: set[int] = set()
    for annotation in annotations:
        annotation_id = int(annotation["id"])
        if annotation_id in annotation_ids:
            raise E32ProtocolError(f"Identifiants annotation dupliqués: {path}.")
        annotation_ids.add(annotation_id)
        if int(annotation["image_id"]) not in image_ids:
            raise E32ProtocolError(f"Annotation orpheline: {path}.")
        if int(annotation["category_id"]) != 1:
            raise E32ProtocolError(f"Classe CCPD autre que plaque: {path}.")
        bbox = annotation.get("bbox")
        if not isinstance(bbox, list) or len(bbox) != 4:
            raise E32ProtocolError(f"Boîte COCO invalide: {path}.")


def load_ccpd_records(bundle_root: str | Path) -> dict[str, list[dict[str, Any]]]:
    """Load the public, detection-only E3.1 bundle and reject any test role."""

    root = Path(bundle_root).resolve()
    report_path = root / "generation_report.json"
    manifest_path = root / "manifest.csv"
    if not report_path.is_file() or not manifest_path.is_file():
        raise E32ProtocolError(f"Bundle E3.1 incomplet: {root}.")
    report = _read_json(report_path)
    safeguards = report.get("safeguards") or {}
    if report.get("status") != "development_detection_source_bundle_not_qualified":
        raise E32ProtocolError("Statut E3.1 inattendu.")
    if report.get("source", {}).get("source_id") != "ccpd_official_mit":
        raise E32ProtocolError("Le bundle E3.1 n'est pas le CCPD officiel audité.")
    required_false = (
        "contains_test_split",
        "final_test_opened",
        "qualification_claim",
        "saas_integration_allowed",
        "ccpd_sequence_field_parsed",
        "ccpd_sequence_field_used_as_ocr_truth",
    )
    for name in required_false:
        if safeguards.get(name) is not False:
            raise E32ProtocolError(f"Garde-fou E3.1 invalide: {name}.")

    manifest_rows: dict[str, Mapping[str, str]] = {}
    split_groups: dict[str, set[str]] = defaultdict(set)
    with manifest_path.open("r", encoding="utf-8", newline="") as handle:
        for row in csv.DictReader(handle):
            split = str(row.get("split") or "")
            if split not in EXPECTED_CCPD_SPLITS:
                raise E32ProtocolError(f"Split interdit dans le manifeste E3.1: {split!r}.")
            if row.get("holdout_role") != "development":
                raise E32ProtocolError("Rôle holdout non développement dans E3.1.")
            if row.get("source_id") != "ccpd_official_mit":
                raise E32ProtocolError("Source autre que CCPD dans E3.1.")
            if row.get("ocr_truth_used") != "false":
                raise E32ProtocolError("Une vérité OCR CCPD a été activée.")
            sample_id = str(row.get("sample_id") or "")
            if not sample_id or sample_id in manifest_rows:
                raise E32ProtocolError("Sample E3.1 vide ou dupliqué.")
            manifest_rows[sample_id] = row
            split_groups[split].add(str(row.get("group_id") or ""))
    for left_index, left in enumerate(EXPECTED_CCPD_SPLITS):
        for right in EXPECTED_CCPD_SPLITS[left_index + 1 :]:
            overlap = split_groups[left] & split_groups[right]
            if overlap:
                raise E32ProtocolError(f"Fuite de groupes CCPD entre {left} et {right}.")

    result: dict[str, list[dict[str, Any]]] = {}
    seen_samples: set[str] = set()
    for split in EXPECTED_CCPD_SPLITS:
        annotation_path = root / "annotations" / f"instances_{split}.json"
        document = _read_json(annotation_path)
        _validate_ccpd_document(document, path=annotation_path, split=split)
        annotations_by_image: dict[int, list[Mapping[str, Any]]] = defaultdict(list)
        for annotation in document["annotations"]:
            annotations_by_image[int(annotation["image_id"])].append(annotation)
        records: list[dict[str, Any]] = []
        for image in document["images"]:
            sample_id = str(image.get("sample_id") or "")
            row = manifest_rows.get(sample_id)
            if row is None or str(row.get("split")) != split:
                raise E32ProtocolError(f"COCO/manifeste incohérent pour {sample_id!r}.")
            image_path = root / str(image["file_name"])
            if not image_path.is_file():
                raise E32ProtocolError(f"Image CCPD absente: {image_path}.")
            width, height = int(image["width"]), int(image["height"])
            boxes: list[list[float]] = []
            for annotation in annotations_by_image[int(image["id"])]:
                x, y, box_width, box_height = (float(value) for value in annotation["bbox"])
                boxes.append(
                    _validate_box(
                        [x, y, x + box_width, y + box_height],
                        width=width,
                        height=height,
                        source=sample_id,
                    )
                )
            if not boxes:
                raise E32ProtocolError(f"Image CCPD sans plaque: {sample_id}.")
            records.append(
                {
                    "image_id": f"ccpd-{sample_id}",
                    "image_path": image_path,
                    "source": "ccpd_official_mit",
                    "source_partition": str(image.get("source_partition") or ""),
                    "producer_split": split,
                    "role": {
                        "train": "training",
                        "validation": "development_selection",
                        "calibration": "development_calibration",
                    }[split],
                    "group_id": str(image.get("group_id") or ""),
                    "width": width,
                    "height": height,
                    "boxes": boxes,
                }
            )
            seen_samples.add(sample_id)
        result[split] = records
    if seen_samples != set(manifest_rows):
        raise E32ProtocolError("Le COCO E3.1 ne couvre pas exactement le manifeste.")
    return result


def normalize_private_records(
    records: Mapping[str, Any], *, validate_files: bool = True
) -> dict[str, list[dict[str, Any]]]:
    """Validate only already-consumed development roles from the private adapter."""

    if not isinstance(records, Mapping):
        raise E32ProtocolError("Le contrat privé doit être un mapping.")
    normalized: dict[str, list[dict[str, Any]]] = {}
    for key in PRIVATE_KEYS:
        rows = records.get(key)
        if not isinstance(rows, list) or not rows:
            raise E32ProtocolError(f"Cohorte privée absente ou vide: {key}.")
        output_rows: list[dict[str, Any]] = []
        for raw in rows:
            if not isinstance(raw, Mapping):
                raise E32ProtocolError(f"Ligne privée invalide dans {key}.")
            role = str(raw.get("role") or "").lower()
            forbidden = ("independent", "future_holdout", "final_holdout", "qualification")
            if any(token in role for token in forbidden):
                raise E32ProtocolError(f"Rôle privé interdit dans E3.2: {role!r}.")
            source = str(raw.get("source") or "")
            image_id = str(raw.get("image_id") or "")
            image_path = Path(raw.get("image_path") or "").resolve()
            width, height = int(raw.get("width") or 0), int(raw.get("height") or 0)
            boxes_raw = raw.get("boxes")
            if source not in SOURCE_QUOTAS_PER_EPOCH or source == "ccpd_official_mit":
                raise E32ProtocolError(f"Source privée non préenregistrée: {source!r}.")
            if not image_id or width < 1 or height < 1 or not isinstance(boxes_raw, Sequence):
                raise E32ProtocolError(f"Métadonnées privées invalides dans {key}.")
            if validate_files and not image_path.is_file():
                raise E32ProtocolError(f"Image privée absente: {image_path}.")
            boxes = [
                _validate_box(box, width=width, height=height, source=image_id)
                for box in boxes_raw
            ]
            if not boxes:
                raise E32ProtocolError(f"Image privée sans plaque: {image_id}.")
            output_rows.append(
                {
                    **dict(raw),
                    "image_id": image_id,
                    "image_path": image_path,
                    "source": source,
                    "role": role,
                    "width": width,
                    "height": height,
                    "boxes": boxes,
                }
            )
        normalized[key] = output_rows
    return normalized


def _deduplicate_by_source_image(records: Sequence[Mapping[str, Any]]) -> list[dict[str, Any]]:
    unique: dict[tuple[str, str], dict[str, Any]] = {}
    for record in records:
        key = (str(record["source"]), str(record["image_id"]))
        unique.setdefault(key, dict(record))
    return [unique[key] for key in sorted(unique)]


def _sample_quota(
    records: Sequence[dict[str, Any]], quota: int, *, rng: random.Random
) -> list[dict[str, Any]]:
    if not records or quota < 1:
        raise E32ProtocolError("Source vide ou quota invalide dans le plan équilibré.")
    ordered = list(records)
    selected: list[dict[str, Any]] = []
    while len(selected) < quota:
        cycle = ordered.copy()
        rng.shuffle(cycle)
        selected.extend(cycle[: quota - len(selected)])
    return selected


def balanced_epoch_records(
    private_training: Sequence[Mapping[str, Any]],
    ccpd_training: Sequence[Mapping[str, Any]],
    *,
    epoch: int,
    seed: int = SEED,
    quotas: Mapping[str, int] = SOURCE_QUOTAS_PER_EPOCH,
) -> list[dict[str, Any]]:
    """Create a deterministic equal-source epoch without split leakage."""

    if epoch < 1:
        raise E32ProtocolError("L'époque doit être positive.")
    pools: dict[str, list[dict[str, Any]]] = defaultdict(list)
    for record in _deduplicate_by_source_image([*private_training, *ccpd_training]):
        pools[str(record["source"])].append(record)
    if set(pools) != set(quotas):
        raise E32ProtocolError(
            f"Sources du plan équilibré inattendues: {sorted(pools)}; attendu {sorted(quotas)}."
        )
    rng = random.Random(int(seed) + int(epoch) * 1_000_003)
    selected: list[dict[str, Any]] = []
    for source in sorted(quotas):
        selected.extend(_sample_quota(pools[source], int(quotas[source]), rng=rng))
    rng.shuffle(selected)
    return selected


def box_iou(box: Sequence[float], target: Sequence[float]) -> float:
    x1 = max(float(box[0]), float(target[0]))
    y1 = max(float(box[1]), float(target[1]))
    x2 = min(float(box[2]), float(target[2]))
    y2 = min(float(box[3]), float(target[3]))
    intersection = max(0.0, x2 - x1) * max(0.0, y2 - y1)
    box_area = max(0.0, float(box[2]) - float(box[0])) * max(
        0.0, float(box[3]) - float(box[1])
    )
    target_area = max(0.0, float(target[2]) - float(target[0])) * max(
        0.0, float(target[3]) - float(target[1])
    )
    union = box_area + target_area - intersection
    return intersection / union if union > 0 else 0.0


def evaluate_predictions(
    records: Sequence[Mapping[str, Any]],
    predictions: Mapping[str, Mapping[str, Sequence[Any]]],
    *,
    score_threshold: float,
    iou_threshold: float = IOU_THRESHOLD,
) -> dict[str, Any]:
    """Compute one-class AP and operating metrics without a COCO dependency."""

    records_by_id = {str(record["image_id"]): record for record in records}
    if set(predictions) != set(records_by_id):
        raise E32ProtocolError("Les prédictions ne couvrent pas exactement la cohorte.")
    total_ground_truth = sum(len(record["boxes"]) for record in records)
    detections: list[tuple[float, str, int, Sequence[float]]] = []
    for image_id, prediction in predictions.items():
        scores = list(prediction.get("scores", []))
        boxes = list(prediction.get("boxes", []))
        if len(scores) != len(boxes):
            raise E32ProtocolError(f"Scores/boîtes incohérents pour {image_id}.")
        for index, (score, detected_box) in enumerate(zip(scores, boxes, strict=True)):
            if float(score) >= score_threshold:
                detections.append((float(score), image_id, index, detected_box))
    detections.sort(key=lambda item: (-item[0], item[1], item[2]))
    matched: dict[str, set[int]] = {image_id: set() for image_id in records_by_id}
    true_positive: list[int] = []
    false_positive: list[int] = []
    for _, image_id, _, detected_box in detections:
        targets = list(records_by_id[image_id]["boxes"])
        available = [index for index in range(len(targets)) if index not in matched[image_id]]
        if available:
            best_index = max(available, key=lambda index: box_iou(detected_box, targets[index]))
            best_iou = box_iou(detected_box, targets[best_index])
        else:
            best_index, best_iou = -1, 0.0
        is_match = best_index >= 0 and best_iou >= iou_threshold
        if is_match:
            matched[image_id].add(best_index)
        true_positive.append(1 if is_match else 0)
        false_positive.append(0 if is_match else 1)

    cumulative_tp: list[int] = []
    cumulative_fp: list[int] = []
    running_tp = running_fp = 0
    for tp, fp in zip(true_positive, false_positive, strict=True):
        running_tp += tp
        running_fp += fp
        cumulative_tp.append(running_tp)
        cumulative_fp.append(running_fp)
    recalls = [value / max(total_ground_truth, 1) for value in cumulative_tp]
    precisions = [
        tp / max(tp + fp, 1)
        for tp, fp in zip(cumulative_tp, cumulative_fp, strict=True)
    ]
    average_precision = 0.0
    if recalls:
        recall_curve = [0.0, *recalls, 1.0]
        precision_curve = [0.0, *precisions, 0.0]
        for index in range(len(precision_curve) - 2, -1, -1):
            precision_curve[index] = max(precision_curve[index], precision_curve[index + 1])
        for index in range(len(recall_curve) - 1):
            if recall_curve[index + 1] != recall_curve[index]:
                average_precision += (
                    recall_curve[index + 1] - recall_curve[index]
                ) * precision_curve[index + 1]
    tp_count = sum(true_positive)
    fp_count = sum(false_positive)
    fn_count = total_ground_truth - tp_count
    precision = tp_count / max(tp_count + fp_count, 1)
    recall = tp_count / max(total_ground_truth, 1)
    f1 = 2 * precision * recall / max(precision + recall, 1.0e-12)
    return {
        "score_threshold": float(score_threshold),
        "iou_threshold": float(iou_threshold),
        "images": len(records),
        "ground_truth_boxes": int(total_ground_truth),
        "predictions": len(detections),
        "true_positives": int(tp_count),
        "false_positives": int(fp_count),
        "false_negatives": int(fn_count),
        "precision": float(precision),
        "recall": float(recall),
        "f1": float(f1),
        "map50": float(average_precision),
    }


def aggregate_domain_metrics(domains: Mapping[str, Mapping[str, Any]]) -> dict[str, Any]:
    if set(domains) != set(ALL_DEVELOPMENT_DOMAINS):
        raise E32ProtocolError(f"Domaines inattendus: {sorted(domains)}.")
    map_values = [float(domains[name]["map50"]) for name in ALL_DEVELOPMENT_DOMAINS]
    recall_values = [float(domains[name]["recall"]) for name in ALL_DEVELOPMENT_DOMAINS]
    return {
        "domains": {name: dict(domains[name]) for name in ALL_DEVELOPMENT_DOMAINS},
        "worst_domain_map50": min(map_values),
        "worst_domain_recall": min(recall_values),
        "macro_domain_map50": sum(map_values) / len(map_values),
    }


def metric_key(metrics: Mapping[str, Any]) -> tuple[float, float, float]:
    return (
        float(metrics["worst_domain_map50"]),
        float(metrics["worst_domain_recall"]),
        float(metrics["macro_domain_map50"]),
    )


def anchor_noninferior(
    candidate: Mapping[str, Any], incumbent: Mapping[str, Any]
) -> bool:
    candidate_domains = candidate["domains"]
    incumbent_domains = incumbent["domains"]
    return all(
        float(candidate_domains[name]["map50"])
        >= float(incumbent_domains[name]["map50"]) - NONINFERIORITY_MAP50
        and float(candidate_domains[name]["recall"])
        >= float(incumbent_domains[name]["recall"]) - NONINFERIORITY_RECALL
        for name in ANCHOR_DOMAINS
    )


def select_candidate_epoch(
    incumbent: Mapping[str, Any], epoch_rows: Sequence[Mapping[str, Any]]
) -> dict[str, Any]:
    """Select only a non-inferior epoch that improves the three-domain key."""

    eligible = [
        row for row in epoch_rows if anchor_noninferior(row["development"], incumbent)
    ]
    if not eligible:
        return {
            "selected": "incumbent",
            "epoch": 0,
            "reason": "all_challenger_epochs_rejected_by_moroccan_noninferiority",
        }
    best = max(eligible, key=lambda row: metric_key(row["development"]))
    if metric_key(best["development"]) <= metric_key(incumbent):
        return {
            "selected": "incumbent",
            "epoch": 0,
            "reason": "challenger_did_not_improve_preregistered_three_domain_key",
        }
    return {
        "selected": "challenger",
        "epoch": int(best["epoch"]),
        "reason": "challenger_improves_three_domain_key_and_moroccan_anchors_are_noninferior",
    }


def select_calibration_threshold(rows: Sequence[Mapping[str, Any]]) -> tuple[dict[str, Any], str]:
    if not rows:
        raise E32ProtocolError("Grille de calibration vide.")
    eligible = [
        row
        for row in rows
        if all(
            float(row[f"{name}_recall"]) >= RECALL_CALIBRATION_FLOOR
            for name in ALL_DEVELOPMENT_DOMAINS
        )
    ]
    if eligible:
        return (
            dict(
                max(
                    eligible,
                    key=lambda row: (
                        float(row["macro_f1"]),
                        float(row["worst_domain_precision"]),
                        float(row["score_threshold"]),
                    ),
                )
            ),
            "recall_constraint_satisfied_on_all_three_development_domains",
        )
    return (
        dict(
            max(
                rows,
                key=lambda row: (
                    float(row["worst_domain_recall"]),
                    float(row["macro_f1"]),
                    float(row["worst_domain_precision"]),
                    -float(row["score_threshold"]),
                ),
            )
        ),
        "fallback_maximize_worst_domain_recall_then_macro_f1",
    )


class PlateRecordDataset:
    """Runtime-only dataset so CI does not need Torch or Pillow."""

    def __init__(self, records: Sequence[Mapping[str, Any]], *, augment: bool) -> None:
        self.records = list(records)
        self.augment = bool(augment)

    def __len__(self) -> int:
        return len(self.records)

    def __getitem__(self, index: int):
        import torch
        from PIL import Image, ImageEnhance, ImageFilter
        from torchvision.transforms.functional import pil_to_tensor

        record = self.records[index]
        with Image.open(record["image_path"]) as opened:
            image = opened.convert("RGB")
        boxes = [[float(value) for value in box] for box in record["boxes"]]
        if self.augment and random.random() < 0.35:
            width = image.width
            image = image.transpose(Image.Transpose.FLIP_LEFT_RIGHT)
            boxes = [[width - box[2], box[1], width - box[0], box[3]] for box in boxes]
        if self.augment:
            if random.random() < 0.80:
                image = ImageEnhance.Brightness(image).enhance(random.uniform(0.65, 1.35))
            if random.random() < 0.80:
                image = ImageEnhance.Contrast(image).enhance(random.uniform(0.65, 1.35))
            if random.random() < 0.55:
                image = ImageEnhance.Color(image).enhance(random.uniform(0.55, 1.45))
            if random.random() < 0.35:
                image = ImageEnhance.Sharpness(image).enhance(random.uniform(0.45, 1.60))
            if random.random() < 0.35:
                image = image.filter(ImageFilter.GaussianBlur(radius=random.uniform(0.2, 1.8)))
            if random.random() < 0.30:
                buffer = io.BytesIO()
                image.save(buffer, format="JPEG", quality=random.randint(35, 82))
                buffer.seek(0)
                with Image.open(buffer) as compressed:
                    image = compressed.convert("RGB")
        tensor = pil_to_tensor(image).float().div_(255.0)
        if self.augment and random.random() < 0.30:
            tensor = (
                tensor + torch.randn_like(tensor) * random.uniform(0.005, 0.035)
            ).clamp_(0.0, 1.0)
        box_tensor = torch.tensor(boxes, dtype=torch.float32)
        target = {
            "boxes": box_tensor,
            "labels": torch.ones((len(boxes),), dtype=torch.int64),
            "image_id": torch.tensor([index], dtype=torch.int64),
            "area": (box_tensor[:, 2] - box_tensor[:, 0])
            * (box_tensor[:, 3] - box_tensor[:, 1]),
            "iscrowd": torch.zeros((len(boxes),), dtype=torch.int64),
        }
        return tensor, target, str(record["image_id"])


def _collate(batch: Sequence[tuple[Any, Any, str]]):
    images, targets, identifiers = zip(*batch, strict=True)
    return list(images), list(targets), list(identifiers)


def _seed_worker(worker_id: int) -> None:
    del worker_id
    import torch

    worker_seed = torch.initial_seed() % (2**32)
    random.seed(worker_seed)


def build_model():
    """Build without downloading TorchVision or backbone pretrained weights."""

    from torchvision.models.detection import fasterrcnn_resnet50_fpn_v2
    from torchvision.models.detection.faster_rcnn import FastRCNNPredictor

    model = fasterrcnn_resnet50_fpn_v2(
        weights=None,
        weights_backbone=None,
        trainable_backbone_layers=3,
        min_size=MIN_SIZE,
        max_size=MAX_SIZE,
        box_score_thresh=SCORE_FLOOR,
        box_nms_thresh=0.50,
        box_detections_per_img=100,
    )
    in_features = model.roi_heads.box_predictor.cls_score.in_features
    model.roi_heads.box_predictor = FastRCNNPredictor(in_features, 2)
    return model


def _load_model_state(checkpoint_path: Path) -> tuple[Any, Mapping[str, Any]]:
    import torch

    checkpoint = torch.load(checkpoint_path, map_location="cpu", weights_only=False)
    if not isinstance(checkpoint, Mapping) or "model_state_dict" not in checkpoint:
        raise E32ProtocolError("Checkpoint de développement incompatible.")
    architecture = str(checkpoint.get("architecture") or ARCHITECTURE)
    if architecture != ARCHITECTURE:
        raise E32ProtocolError(f"Architecture warm-start inattendue: {architecture}.")
    model = build_model()
    model.load_state_dict(checkpoint["model_state_dict"])
    return model, checkpoint


def _predict(
    model: Any, records: Sequence[Mapping[str, Any]], device: Any
) -> tuple[dict[str, dict[str, Any]], dict[str, float]]:
    import torch
    from torch.utils.data import DataLoader

    loader = DataLoader(
        PlateRecordDataset(records, augment=False),
        batch_size=1,
        shuffle=False,
        num_workers=2,
        pin_memory=True,
        collate_fn=_collate,
        persistent_workers=True,
    )
    model.eval()
    predictions: dict[str, dict[str, Any]] = {}
    latencies_ms: list[float] = []
    with torch.inference_mode():
        for images, _, image_ids in loader:
            images = [image.to(device, non_blocking=True) for image in images]
            if device.type == "cuda":
                torch.cuda.synchronize()
            started = time.perf_counter()
            outputs = model(images)
            if device.type == "cuda":
                torch.cuda.synchronize()
            latencies_ms.append((time.perf_counter() - started) * 1000.0)
            output = outputs[0]
            predictions[image_ids[0]] = {
                "boxes": output["boxes"].detach().cpu().tolist(),
                "scores": output["scores"].detach().cpu().tolist(),
            }
    ordered = sorted(latencies_ms)
    p95_index = max(0, math.ceil(0.95 * len(ordered)) - 1)
    latency = {
        "median_ms_per_image": ordered[len(ordered) // 2] if ordered else 0.0,
        "p95_ms_per_image": ordered[p95_index] if ordered else 0.0,
    }
    return predictions, latency


def _evaluate_domains(
    model: Any,
    cohorts: Mapping[str, Sequence[Mapping[str, Any]]],
    device: Any,
) -> tuple[dict[str, Any], dict[str, dict[str, Any]]]:
    domains: dict[str, Mapping[str, Any]] = {}
    predictions: dict[str, dict[str, Any]] = {}
    latency: dict[str, Mapping[str, float]] = {}
    for name in ALL_DEVELOPMENT_DOMAINS:
        domain_predictions, domain_latency = _predict(model, cohorts[name], device)
        predictions[name] = domain_predictions
        domains[name] = evaluate_predictions(
            cohorts[name], domain_predictions, score_threshold=SCORE_FLOOR
        )
        latency[name] = domain_latency
    metrics = aggregate_domain_metrics(domains)
    metrics["latency"] = latency
    return metrics, predictions


def _train_epoch(model: Any, records: Sequence[Mapping[str, Any]], optimizer: Any, scaler: Any, device: Any) -> float:
    import torch
    from torch.utils.data import DataLoader

    generator = torch.Generator().manual_seed(SEED + len(records))
    loader = DataLoader(
        PlateRecordDataset(records, augment=True),
        batch_size=BATCH_SIZE,
        shuffle=True,
        num_workers=2,
        pin_memory=True,
        collate_fn=_collate,
        worker_init_fn=_seed_worker,
        generator=generator,
        persistent_workers=True,
    )
    model.train()
    optimizer.zero_grad(set_to_none=True)
    running_loss = 0.0
    batches = 0
    for batch_index, (images, targets, _) in enumerate(loader, start=1):
        images = [image.to(device, non_blocking=True) for image in images]
        targets = [
            {name: value.to(device, non_blocking=True) for name, value in target.items()}
            for target in targets
        ]
        with torch.autocast(
            device_type=device.type,
            dtype=torch.float16,
            enabled=device.type == "cuda",
        ):
            losses = model(images, targets)
            raw_loss = sum(losses.values())
            loss = raw_loss / ACCUMULATION_STEPS
        if not torch.isfinite(raw_loss):
            raise E32ProtocolError(f"Loss non finie au batch {batch_index}.")
        scaler.scale(loss).backward()
        if batch_index % ACCUMULATION_STEPS == 0 or batch_index == len(loader):
            scaler.unscale_(optimizer)
            torch.nn.utils.clip_grad_norm_(model.parameters(), 5.0)
            scaler.step(optimizer)
            scaler.update()
            optimizer.zero_grad(set_to_none=True)
        running_loss += float(raw_loss.detach().cpu())
        batches += 1
        if batch_index % 500 == 0 or batch_index == len(loader):
            print(
                json.dumps(
                    {
                        "event": "e32_training_progress",
                        "batch": batch_index,
                        "batches": len(loader),
                        "mean_loss": running_loss / batches,
                    }
                ),
                flush=True,
            )
    return running_loss / max(batches, 1)


def _calibration_rows(
    cohorts: Mapping[str, Sequence[Mapping[str, Any]]],
    predictions: Mapping[str, Mapping[str, Mapping[str, Sequence[Any]]]],
) -> list[dict[str, Any]]:
    rows: list[dict[str, Any]] = []
    thresholds = [round(0.05 + index * 0.025, 3) for index in range(37)]
    for threshold in thresholds:
        metrics = {
            name: evaluate_predictions(
                cohorts[name], predictions[name], score_threshold=threshold
            )
            for name in ALL_DEVELOPMENT_DOMAINS
        }
        row: dict[str, Any] = {"score_threshold": threshold}
        for name in ALL_DEVELOPMENT_DOMAINS:
            for metric in ("precision", "recall", "f1"):
                row[f"{name}_{metric}"] = metrics[name][metric]
        row["worst_domain_recall"] = min(
            metrics[name]["recall"] for name in ALL_DEVELOPMENT_DOMAINS
        )
        row["worst_domain_precision"] = min(
            metrics[name]["precision"] for name in ALL_DEVELOPMENT_DOMAINS
        )
        row["macro_f1"] = sum(
            metrics[name]["f1"] for name in ALL_DEVELOPMENT_DOMAINS
        ) / len(ALL_DEVELOPMENT_DOMAINS)
        rows.append(row)
    return rows


def _write_csv(path: Path, rows: Sequence[Mapping[str, Any]]) -> None:
    if not rows:
        raise E32ProtocolError(f"CSV vide: {path}.")
    path.parent.mkdir(parents=True, exist_ok=True)
    with path.open("w", encoding="utf-8", newline="") as handle:
        writer = csv.DictWriter(handle, fieldnames=list(rows[0]))
        writer.writeheader()
        writer.writerows(rows)


def _artifact_checksums(root: Path) -> None:
    paths = sorted(
        path
        for path in root.rglob("*")
        if path.is_file() and path.name != "SHA256SUMS"
    )
    (root / "SHA256SUMS").write_text(
        "\n".join(f"{file_sha256(path)} {path.relative_to(root).as_posix()}" for path in paths)
        + "\n",
        encoding="utf-8",
    )


def run_e32(
    *,
    private_records: Mapping[str, Any],
    ccpd_bundle_root: str | Path,
    warm_start_checkpoint: str | Path,
    output_dir: str | Path,
) -> dict[str, Any]:
    """Run or resume E3.2 in a private development output directory."""

    import torch

    output = Path(output_dir).resolve()
    complete_path = output / "E32_DEVELOPMENT_COMPLETE.json"
    if complete_path.exists():
        complete = dict(_read_json(complete_path))
        if complete.get("status") != "e32_development_complete_not_qualified":
            raise E32ProtocolError("Marqueur E3.2 existant incompatible.")
        return complete
    output.mkdir(parents=True, exist_ok=True)
    checkpoint_path = Path(warm_start_checkpoint).resolve()
    if not checkpoint_path.is_file():
        raise E32ProtocolError("Checkpoint warm-start privé absent.")
    private = normalize_private_records(private_records)
    ccpd = load_ccpd_records(ccpd_bundle_root)
    input_signature = {
        "trainer_version": TRAINER_VERSION,
        "architecture": ARCHITECTURE,
        "seed": SEED,
        "warm_start_sha256": file_sha256(checkpoint_path),
        "ccpd_manifest_sha256": file_sha256(Path(ccpd_bundle_root) / "manifest.csv"),
        "private_counts": {name: len(private[name]) for name in PRIVATE_KEYS},
        "ccpd_counts": {name: len(ccpd[name]) for name in EXPECTED_CCPD_SPLITS},
        "source_quotas_per_epoch": SOURCE_QUOTAS_PER_EPOCH,
    }
    input_signature_sha256 = hashlib.sha256(
        json.dumps(input_signature, sort_keys=True, separators=(",", ":")).encode("utf-8")
    ).hexdigest()

    if not torch.cuda.is_available():
        raise E32ProtocolError("E3.2 exige un GPU CUDA.")
    device = torch.device("cuda")
    torch.backends.cudnn.benchmark = True
    random.seed(SEED)
    torch.manual_seed(SEED)
    torch.cuda.manual_seed_all(SEED)

    validation_cohorts = {
        "moroccan_primary": private["primary_validation"],
        "moroccan_secondary_consumed": private["secondary_validation"],
        "ccpd_public": ccpd["validation"],
    }
    calibration_cohorts = {
        "moroccan_primary": private["primary_calibration"],
        "moroccan_secondary_consumed": private["secondary_calibration"],
        "ccpd_public": ccpd["calibration"],
    }

    baseline_model, warm_checkpoint = _load_model_state(checkpoint_path)
    baseline_model.to(device)
    print(json.dumps({"event": "e32_baseline_evaluation_start"}), flush=True)
    incumbent_metrics, _ = _evaluate_domains(baseline_model, validation_cohorts, device)
    baseline_model.to("cpu")
    del baseline_model
    torch.cuda.empty_cache()

    model, _ = _load_model_state(checkpoint_path)
    model.to(device)
    optimizer = torch.optim.AdamW(
        model.parameters(), lr=LEARNING_RATE, weight_decay=WEIGHT_DECAY
    )
    scheduler = torch.optim.lr_scheduler.CosineAnnealingLR(
        optimizer, T_max=EPOCHS, eta_min=2.0e-6
    )
    scaler = torch.amp.GradScaler("cuda", enabled=True)
    resume_path = output / "e32-training-resume.pt"
    candidate_path = output / "e32-challenger-best.pt"
    history_path = output / "training_history.json"
    history: list[dict[str, Any]] = []
    start_epoch = 1
    best_eligible_key = (-1.0, -1.0, -1.0)
    if resume_path.exists():
        resume = torch.load(resume_path, map_location="cpu", weights_only=False)
        if resume.get("input_signature_sha256") != input_signature_sha256:
            raise E32ProtocolError("Le resume E3.2 appartient à d'autres entrées.")
        model.load_state_dict(resume["model_state_dict"])
        optimizer.load_state_dict(resume["optimizer_state_dict"])
        scheduler.load_state_dict(resume["scheduler_state_dict"])
        scaler.load_state_dict(resume["scaler_state_dict"])
        history = list(resume.get("history") or [])
        start_epoch = int(resume["epoch"]) + 1
        eligible_history = [
            row for row in history if bool(row.get("moroccan_anchor_noninferior"))
        ]
        if eligible_history:
            best_eligible_key = max(
                metric_key(row["development"]) for row in eligible_history
            )

    for epoch in range(start_epoch, EPOCHS + 1):
        epoch_records = balanced_epoch_records(
            private["training"], ccpd["train"], epoch=epoch
        )
        source_counts = Counter(str(record["source"]) for record in epoch_records)
        started = time.time()
        loss = _train_epoch(model, epoch_records, optimizer, scaler, device)
        scheduler.step()
        development, _ = _evaluate_domains(model, validation_cohorts, device)
        row = {
            "epoch": epoch,
            "train_loss": loss,
            "learning_rate": optimizer.param_groups[0]["lr"],
            "runtime_seconds": time.time() - started,
            "source_counts": dict(sorted(source_counts.items())),
            "development": development,
            "selection_key": list(metric_key(development)),
            "moroccan_anchor_noninferior": anchor_noninferior(
                development, incumbent_metrics
            ),
        }
        history.append(row)
        current_key = metric_key(development)
        if row["moroccan_anchor_noninferior"] and current_key > best_eligible_key:
            best_eligible_key = current_key
            _atomic_torch_save(
                candidate_path,
                {
                    "stage": "E3.2",
                    "trainer_version": TRAINER_VERSION,
                    "architecture": ARCHITECTURE,
                    "min_size": MIN_SIZE,
                    "max_size": MAX_SIZE,
                    "input_signature_sha256": input_signature_sha256,
                    "initialization": "private_development_warm_start",
                    "torchvision_weights_downloaded_by_e32": False,
                    "upstream_pretrained_lineage_inherited": True,
                    "model_state_dict": model.state_dict(),
                    "best_epoch": epoch,
                    "best_key": list(best_eligible_key),
                },
            )
        _atomic_torch_save(
            resume_path,
            {
                "stage": "E3.2",
                "trainer_version": TRAINER_VERSION,
                "epoch": epoch,
                "input_signature_sha256": input_signature_sha256,
                "model_state_dict": model.state_dict(),
                "optimizer_state_dict": optimizer.state_dict(),
                "scheduler_state_dict": scheduler.state_dict(),
                "scaler_state_dict": scaler.state_dict(),
                "history": history,
            },
        )
        _atomic_json(
            history_path,
            {
                "stage": "E3.2",
                "trainer_version": TRAINER_VERSION,
                "history": history,
            },
        )
        print(
            json.dumps(
                {
                    "event": "e32_epoch_complete",
                    "epoch": epoch,
                    "loss": loss,
                    "selection_key": list(current_key),
                    "moroccan_anchor_noninferior": row["moroccan_anchor_noninferior"],
                }
            ),
            flush=True,
        )

    decision = select_candidate_epoch(incumbent_metrics, history)
    selected_path = output / "detector_e32_selected.pt"
    if decision["selected"] == "challenger":
        best_checkpoint = torch.load(candidate_path, map_location="cpu", weights_only=False)
        selected_model = build_model()
        selected_model.load_state_dict(best_checkpoint["model_state_dict"])
        shutil.copy2(candidate_path, selected_path)
    else:
        selected_model, _ = _load_model_state(checkpoint_path)
        shutil.copy2(checkpoint_path, selected_path)
    selected_model.to(device)
    selected_validation, _ = _evaluate_domains(
        selected_model, validation_cohorts, device
    )
    _, calibration_predictions = _evaluate_domains(
        selected_model, calibration_cohorts, device
    )
    calibration_rows = _calibration_rows(
        calibration_cohorts, calibration_predictions
    )
    selected_threshold, calibration_outcome = select_calibration_threshold(
        calibration_rows
    )
    _write_csv(output / "threshold_calibration.csv", calibration_rows)
    selected_model.to("cpu")
    del selected_model
    torch.cuda.empty_cache()

    private_metrics = {
        "schema_version": "1.0.0",
        "stage": "E3.2",
        "status": "private_development_metrics_not_independent_evidence",
        "incumbent": incumbent_metrics,
        "epochs": history,
        "selection": decision,
        "selected_validation": selected_validation,
        "calibrated_threshold": selected_threshold["score_threshold"],
        "calibration_outcome": calibration_outcome,
        "calibration_metrics": selected_threshold,
        "final_test_opened": False,
        "qualification_claim": False,
        "saas_integration_allowed": False,
    }
    _atomic_json(output / "private_development_metrics.json", private_metrics)
    public_ccpd = {
        "schema_version": "1.0.0",
        "stage": "E3.2",
        "status": "public_ccpd_development_metrics_not_moroccan_qualification",
        "source_id": "ccpd_official_mit",
        "validation": selected_validation["domains"]["ccpd_public"],
        "calibration_at_selected_threshold": {
            key.removeprefix("ccpd_public_"): value
            for key, value in selected_threshold.items()
            if key.startswith("ccpd_public_")
        },
        "calibrated_threshold_selected_across_private_and_public_development": True,
        "final_test_opened": False,
        "qualification_claim": False,
        "moroccan_accuracy_claim": False,
        "saas_integration_allowed": False,
    }
    _atomic_json(output / "public_ccpd_development_metrics.json", public_ccpd)
    provenance = {
        "schema_version": "1.0.0",
        "stage": "E3.2",
        "trainer_version": TRAINER_VERSION,
        "architecture": ARCHITECTURE,
        "configuration": {
            "seed": SEED,
            "min_size": MIN_SIZE,
            "max_size": MAX_SIZE,
            "epochs": EPOCHS,
            "batch_size": BATCH_SIZE,
            "gradient_accumulation_steps": ACCUMULATION_STEPS,
            "learning_rate": LEARNING_RATE,
            "weight_decay": WEIGHT_DECAY,
            "source_quotas_per_epoch": SOURCE_QUOTAS_PER_EPOCH,
            "noninferiority_map50": NONINFERIORITY_MAP50,
            "noninferiority_recall": NONINFERIORITY_RECALL,
        },
        "input_signature_sha256": input_signature_sha256,
        "input_signature": input_signature,
        "selected_model_sha256": file_sha256(selected_path),
        "torchvision_weights_downloaded_by_e32": False,
        "upstream_pretrained_lineage_inherited": True,
        "upstream_pretrained_lineage_relicensed_by_e32": False,
        "final_test_opened": False,
        "qualification_claim": False,
        "saas_integration_allowed": False,
    }
    _atomic_json(output / "provenance.json", provenance)
    if resume_path.exists():
        resume_path.unlink()
    _artifact_checksums(output)
    complete = {
        "schema_version": "1.0.0",
        "stage": "E3.2",
        "status": "e32_development_complete_not_qualified",
        "selected": decision["selected"],
        "selected_epoch": decision["epoch"],
        "selection_reason": decision["reason"],
        "calibrated_threshold": selected_threshold["score_threshold"],
        "public_ccpd_validation_map50": public_ccpd["validation"]["map50"],
        "public_ccpd_validation_recall": public_ccpd["validation"]["recall"],
        "final_test_opened": False,
        "qualification_claim": False,
        "saas_integration_allowed": False,
    }
    _atomic_json(complete_path, complete)
    _artifact_checksums(output)
    return complete


__all__ = [
    "ALL_DEVELOPMENT_DOMAINS",
    "E32ProtocolError",
    "SOURCE_QUOTAS_PER_EPOCH",
    "aggregate_domain_metrics",
    "anchor_noninferior",
    "balanced_epoch_records",
    "box_iou",
    "evaluate_predictions",
    "load_ccpd_records",
    "normalize_private_records",
    "run_e32",
    "select_calibration_threshold",
    "select_candidate_epoch",
]
