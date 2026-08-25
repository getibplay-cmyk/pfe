#!/usr/bin/env python3
"""Train and evaluate the E2.2 Moroccan plate character-detector challenger.

The model consumes only bounded plate crops. Selection uses clean development
validation; calibration is reported after selection and never influences it.
No ``test`` split is accepted and every dataset source must be admitted by the
public source registry for ``character_detection``.
"""

from __future__ import annotations

import argparse
import csv
import json
import math
import os
import random
import sys
import tempfile
import time
from collections import Counter, defaultdict
from pathlib import Path
from typing import Any, Mapping, Sequence


REPOSITORY_ROOT = Path(__file__).resolve().parents[3]
if str(REPOSITORY_ROOT) not in sys.path:
    sys.path.insert(0, str(REPOSITORY_ROOT))

from scripts.intelligence.vehicle_plate.character_detector import (
    CHARACTER_ALPHABET,
    CHARACTER_PROTOCOL_VERSION,
    CLASS_TO_ID,
    ID_TO_CLASS,
    MODEL_NUM_CLASSES,
    CharacterDetection,
    DetectionCounts,
    load_source_registry,
    match_character_detections,
    reconstruct_moroccan_plate,
    require_admitted_source,
)
from scripts.intelligence.vehicle_plate.protocol import (
    PROTOCOL_VERSION,
    ProtocolError,
    character_error_rate,
    file_sha256,
    sha256sum_lines,
)


TRAINER_VERSION = "1.0.0"
ARCHITECTURE = "torchvision_ssdlite320_mobilenet_v3_large"
EXPECTED_SPLITS = ("train", "validation", "calibration")
DEFAULT_SOURCE_REGISTRY = (
    REPOSITORY_ROOT
    / "docs/intelligence/evidence/moroccan-anpr-public-source-registry-v1.json"
)


def _read_json(path: Path) -> Mapping[str, Any]:
    document = json.loads(path.read_text(encoding="utf-8"))
    if not isinstance(document, dict):
        raise ProtocolError(f"Objet JSON attendu: {path}.")
    return document


def _validate_coco_document(document: Mapping[str, Any], path: Path) -> None:
    categories = document.get("categories")
    expected = [
        {"id": CLASS_TO_ID[character], "name": character, "supercategory": "character"}
        for character in CHARACTER_ALPHABET
    ]
    if categories != expected:
        raise ProtocolError(f"Alphabet COCO inattendu dans {path}.")
    images = document.get("images")
    annotations = document.get("annotations")
    if not isinstance(images, list) or not images:
        raise ProtocolError(f"Aucune image COCO dans {path}.")
    if not isinstance(annotations, list) or not annotations:
        raise ProtocolError(f"Aucune annotation COCO dans {path}.")
    image_ids = {int(item["id"]) for item in images}
    if len(image_ids) != len(images):
        raise ProtocolError(f"Identifiants image COCO dupliqués dans {path}.")
    annotation_ids: set[int] = set()
    for item in annotations:
        annotation_id = int(item["id"])
        if annotation_id in annotation_ids:
            raise ProtocolError(f"Identifiant annotation COCO dupliqué dans {path}.")
        annotation_ids.add(annotation_id)
        if int(item["image_id"]) not in image_ids:
            raise ProtocolError(f"Annotation orpheline dans {path}.")
        if int(item["category_id"]) not in ID_TO_CLASS:
            raise ProtocolError(f"Classe COCO hors alphabet dans {path}.")
        bbox = item.get("bbox")
        if not isinstance(bbox, list) or len(bbox) != 4:
            raise ProtocolError(f"Boîte COCO invalide dans {path}.")
        x, y, width, height = (float(value) for value in bbox)
        if not all(math.isfinite(value) for value in (x, y, width, height)):
            raise ProtocolError(f"Boîte COCO non finie dans {path}.")
        if x < 0 or y < 0 or width <= 0 or height <= 0:
            raise ProtocolError(f"Boîte COCO hors limites dans {path}.")


def audit_dataset_bundles(
    dataset_dirs: Sequence[Path],
    *,
    source_registry_path: Path,
) -> dict[str, Any]:
    """Reject test rows, unadmitted sources, leakage and exact duplicates."""

    if not dataset_dirs:
        raise ProtocolError("Au moins un bundle de caractères est obligatoire.")
    registry = load_source_registry(source_registry_path)
    seen_sha: dict[str, tuple[Path, str]] = {}
    group_splits: dict[tuple[str, str], str] = {}
    source_counts: Counter[str] = Counter()
    split_counts: Counter[str] = Counter()
    bundle_reports: list[dict[str, Any]] = []
    for dataset_dir in dataset_dirs:
        root = dataset_dir.resolve()
        manifest_path = root / "manifest.csv"
        report_path = root / "generation_report.json"
        if not manifest_path.is_file() or not report_path.is_file():
            raise ProtocolError(f"Bundle incomplet: {root}.")
        report = _read_json(report_path)
        if report.get("qualification_claim") is not False:
            raise ProtocolError(f"Bundle avec revendication de qualification: {root}.")
        if report.get("final_test_opened") is not False:
            raise ProtocolError(f"Bundle ayant ouvert le test final: {root}.")
        with manifest_path.open("r", encoding="utf-8", newline="") as handle:
            rows = list(csv.DictReader(handle))
        if not rows:
            raise ProtocolError(f"Manifeste vide: {manifest_path}.")
        for row in rows:
            split = str(row.get("split") or "")
            if split not in EXPECTED_SPLITS:
                raise ProtocolError(f"Split interdit {split!r} dans {manifest_path}.")
            if row.get("holdout_role") != "development":
                raise ProtocolError(f"Rôle holdout interdit dans {manifest_path}.")
            source_id = str(row.get("source_id") or "")
            require_admitted_source(
                registry, source_id=source_id, task="character_detection"
            )
            image_path = root / str(row.get("image_path") or "")
            if not image_path.is_file():
                raise ProtocolError(f"Image absente du bundle: {image_path}.")
            expected_sha = str(row.get("sha256") or "")
            if file_sha256(image_path) != expected_sha:
                raise ProtocolError(f"SHA-256 image invalide: {image_path}.")
            previous = seen_sha.get(expected_sha)
            if previous is not None and previous != (root, str(row.get("sample_id"))):
                raise ProtocolError(
                    "Image exacte dupliquée entre bundles/splits: "
                    f"{previous[0]} et {root}."
                )
            seen_sha[expected_sha] = (root, str(row.get("sample_id")))
            group_key = (source_id, str(row.get("group_id") or ""))
            prior_split = group_splits.setdefault(group_key, split)
            if prior_split != split:
                raise ProtocolError(
                    f"Fuite de groupe {group_key!r} entre {prior_split} et {split}."
                )
            source_counts[source_id] += 1
            split_counts[split] += 1
        for split in EXPECTED_SPLITS:
            annotation_path = root / "annotations" / f"instances_{split}.json"
            if not annotation_path.is_file():
                raise ProtocolError(f"Annotations {split} absentes: {annotation_path}.")
            _validate_coco_document(_read_json(annotation_path), annotation_path)
        bundle_reports.append(
            {
                "dataset_dir": os.fspath(root),
                "manifest_sha256": file_sha256(manifest_path),
                "generation_report_sha256": file_sha256(report_path),
                "contains_real_vehicle_data": bool(report.get("contains_real_vehicle_data")),
            }
        )
    missing = [split for split in EXPECTED_SPLITS if split_counts[split] < 1]
    if missing:
        raise ProtocolError("Splits de développement vides: " + ", ".join(missing))
    return {
        "bundles": bundle_reports,
        "rows": sum(split_counts.values()),
        "split_counts": dict(sorted(split_counts.items())),
        "source_counts": dict(sorted(source_counts.items())),
        "unique_sha256_images": len(seen_sha),
        "groups": len(group_splits),
        "final_test_opened": False,
        "qualification_claim": False,
    }


class CocoCharacterDataset:
    """Small custom COCO reader; imports Torch and Pillow only at runtime."""

    def __init__(
        self,
        dataset_dir: Path,
        split: str,
        *,
        clean_only: bool = False,
    ) -> None:
        if split not in EXPECTED_SPLITS:
            raise ProtocolError(f"Split de caractères interdit: {split!r}.")
        self.root = dataset_dir.resolve()
        self.split = split
        self.document = _read_json(
            self.root / "annotations" / f"instances_{split}.json"
        )
        _validate_coco_document(
            self.document,
            self.root / "annotations" / f"instances_{split}.json",
        )
        annotations_by_image: dict[int, list[Mapping[str, Any]]] = defaultdict(list)
        for annotation in self.document["annotations"]:
            annotations_by_image[int(annotation["image_id"])].append(annotation)
        images = list(self.document["images"])
        if clean_only:
            images = [
                item
                for item in images
                if str(item.get("variant_id") or "original") in {"variant-00", "original"}
            ]
        if not images:
            raise ProtocolError(f"Aucune image évaluable dans {self.root} ({split}).")
        self.images = images
        self.annotations_by_image = annotations_by_image

    def __len__(self) -> int:
        return len(self.images)

    def __getitem__(self, index: int):
        import torch
        from PIL import Image
        from torchvision.transforms.functional import pil_to_tensor

        metadata = self.images[index]
        image_path = self.root / str(metadata["file_name"])
        with Image.open(image_path) as opened:
            image = opened.convert("RGB")
        width, height = image.size
        if width != int(metadata["width"]) or height != int(metadata["height"]):
            raise ProtocolError(f"Dimensions image/COCO incohérentes: {image_path}.")
        records = sorted(
            self.annotations_by_image[int(metadata["id"])],
            key=lambda item: int(item.get("reading_index", item["id"])),
        )
        boxes: list[list[float]] = []
        labels: list[int] = []
        areas: list[float] = []
        for record in records:
            x, y, box_width, box_height = (float(value) for value in record["bbox"])
            boxes.append([x, y, x + box_width, y + box_height])
            labels.append(int(record["category_id"]))
            areas.append(box_width * box_height)
        target = {
            "boxes": torch.tensor(boxes, dtype=torch.float32),
            "labels": torch.tensor(labels, dtype=torch.int64),
            "image_id": torch.tensor([int(metadata["id"])], dtype=torch.int64),
            "area": torch.tensor(areas, dtype=torch.float32),
            "iscrowd": torch.zeros((len(boxes),), dtype=torch.int64),
        }
        audit_metadata = {
            "dataset_dir": os.fspath(self.root),
            "sample_id": str(metadata["sample_id"]),
            "group_id": str(metadata["group_id"]),
            "target": str(metadata["target"]),
            "recognition_text": str(metadata["recognition_text"]),
            "format_profile": str(metadata["format_profile"]),
            "variant_id": str(metadata.get("variant_id") or "original"),
            "source_id": str(metadata["source_id"]),
            "width": width,
            "height": height,
        }
        return pil_to_tensor(image).float().div(255.0), target, audit_metadata


class ConcatenatedDataset:
    def __init__(self, datasets: Sequence[CocoCharacterDataset]) -> None:
        self.datasets = tuple(datasets)
        self.offsets: list[int] = []
        total = 0
        for dataset in self.datasets:
            total += len(dataset)
            self.offsets.append(total)

    def __len__(self) -> int:
        return self.offsets[-1] if self.offsets else 0

    def __getitem__(self, index: int):
        if index < 0:
            index += len(self)
        if not 0 <= index < len(self):
            raise IndexError(index)
        start = 0
        for dataset, stop in zip(self.datasets, self.offsets, strict=True):
            if index < stop:
                return dataset[index - start]
            start = stop
        raise IndexError(index)


def _collate(batch: Sequence[tuple[Any, Any, Any]]):
    images, targets, metadata = zip(*batch, strict=True)
    return list(images), list(targets), list(metadata)


def _make_model():
    from torchvision.models import MobileNet_V3_Large_Weights
    from torchvision.models.detection import ssdlite320_mobilenet_v3_large

    return ssdlite320_mobilenet_v3_large(
        weights=None,
        weights_backbone=MobileNet_V3_Large_Weights.IMAGENET1K_V1,
        num_classes=MODEL_NUM_CLASSES,
        trainable_backbone_layers=4,
        score_thresh=0.01,
        nms_thresh=0.45,
        detections_per_img=40,
    )


def _to_device(target: Mapping[str, Any], device: Any) -> dict[str, Any]:
    return {
        key: value.to(device) if hasattr(value, "to") else value
        for key, value in target.items()
    }


def _train_epoch(model: Any, loader: Any, optimizer: Any, scaler: Any, device: Any) -> float:
    import torch

    model.train()
    running_loss = 0.0
    batches = 0
    for images, targets, _ in loader:
        images = [image.to(device, non_blocking=True) for image in images]
        targets = [_to_device(target, device) for target in targets]
        optimizer.zero_grad(set_to_none=True)
        with torch.autocast(
            device_type=device.type,
            dtype=torch.float16,
            enabled=(device.type == "cuda"),
        ):
            losses = model(images, targets)
            loss = sum(losses.values())
        if not torch.isfinite(loss):
            raise ProtocolError(f"Loss non finie pendant E2.2: {float(loss.detach())}.")
        scaler.scale(loss).backward()
        scaler.unscale_(optimizer)
        torch.nn.utils.clip_grad_norm_(model.parameters(), max_norm=10.0)
        scaler.step(optimizer)
        scaler.update()
        running_loss += float(loss.detach().cpu())
        batches += 1
    return running_loss / max(batches, 1)


def _prediction_items(output: Mapping[str, Any], score_threshold: float) -> list[CharacterDetection]:
    predictions: list[CharacterDetection] = []
    for box, label, score in zip(
        output["boxes"].detach().cpu().tolist(),
        output["labels"].detach().cpu().tolist(),
        output["scores"].detach().cpu().tolist(),
        strict=True,
    ):
        class_id = int(label)
        if class_id not in ID_TO_CLASS or float(score) < score_threshold:
            continue
        predictions.append(
            CharacterDetection(
                ID_TO_CLASS[class_id],
                tuple(float(value) for value in box),
                float(score),
            )
        )
    return predictions


def _target_items(target: Mapping[str, Any]) -> list[CharacterDetection]:
    return [
        CharacterDetection(
            ID_TO_CLASS[int(label)],
            tuple(float(value) for value in box),
            1.0,
        )
        for box, label in zip(
            target["boxes"].detach().cpu().tolist(),
            target["labels"].detach().cpu().tolist(),
            strict=True,
        )
    ]


def _evaluate(model: Any, loader: Any, device: Any, score_threshold: float) -> dict[str, Any]:
    import torch

    model.eval()
    predictions_for_cer: list[str] = []
    targets_for_cer: list[str] = []
    accepted = 0
    exact = 0
    format_totals: Counter[str] = Counter()
    format_exact: Counter[str] = Counter()
    counts = DetectionCounts(0, 0, 0)
    failures: Counter[str] = Counter()
    latencies_ms: list[float] = []
    with torch.inference_mode():
        for images, targets, metadata_items in loader:
            device_images = [image.to(device, non_blocking=True) for image in images]
            if device.type == "cuda":
                torch.cuda.synchronize()
            started = time.perf_counter()
            outputs = model(device_images)
            if device.type == "cuda":
                torch.cuda.synchronize()
            elapsed_ms = (time.perf_counter() - started) * 1000.0
            latencies_ms.extend([elapsed_ms / len(images)] * len(images))
            for output, target, metadata in zip(outputs, targets, metadata_items, strict=True):
                predicted_items = _prediction_items(output, score_threshold)
                target_items = _target_items(target)
                sample_counts = match_character_detections(predicted_items, target_items)
                counts = DetectionCounts(
                    counts.true_positives + sample_counts.true_positives,
                    counts.false_positives + sample_counts.false_positives,
                    counts.false_negatives + sample_counts.false_negatives,
                )
                reading = reconstruct_moroccan_plate(
                    predicted_items,
                    image_width=float(metadata["width"]),
                    image_height=float(metadata["height"]),
                    score_threshold=score_threshold,
                )
                target_value = str(metadata["target"])
                prediction_value = reading.canonical or ""
                targets_for_cer.append(target_value)
                predictions_for_cer.append(prediction_value)
                format_name = str(metadata["format_profile"])
                format_totals[format_name] += 1
                if reading.accepted:
                    accepted += 1
                else:
                    failures.update(reading.reasons)
                if prediction_value == target_value:
                    exact += 1
                    format_exact[format_name] += 1
    total = len(targets_for_cer)
    sorted_latency = sorted(latencies_ms)
    p95_index = max(0, math.ceil(0.95 * len(sorted_latency)) - 1)
    return {
        "images": total,
        "full_plate_exact": exact / total if total else 0.0,
        "cer": character_error_rate(predictions_for_cer, targets_for_cer) if total else 1.0,
        "selective_coverage": accepted / total if total else 0.0,
        "character_precision_iou50": counts.precision,
        "character_recall_iou50": counts.recall,
        "format_exact": {
            name: format_exact[name] / count
            for name, count in sorted(format_totals.items())
        },
        "format_counts": dict(sorted(format_totals.items())),
        "rejection_reasons": dict(sorted(failures.items())),
        "latency_ms_per_crop_median": statistics_median(sorted_latency),
        "latency_ms_per_crop_p95": sorted_latency[p95_index] if sorted_latency else None,
    }


def statistics_median(values: Sequence[float]) -> float | None:
    if not values:
        return None
    middle = len(values) // 2
    if len(values) % 2:
        return float(values[middle])
    return float((values[middle - 1] + values[middle]) / 2.0)


def _candidate_key(metrics: Mapping[str, Any]) -> tuple[float, float, float]:
    format_values = list(metrics.get("format_exact", {}).values())
    worst_format = min((float(value) for value in format_values), default=0.0)
    return (
        worst_format,
        float(metrics["full_plate_exact"]),
        -float(metrics["cer"]),
    )


def _seed_everything(seed: int) -> None:
    random.seed(seed)
    os.environ["PYTHONHASHSEED"] = str(seed)
    try:
        import numpy as np

        np.random.seed(seed)
    except ImportError:
        pass
    import torch

    torch.manual_seed(seed)
    if torch.cuda.is_available():
        torch.cuda.manual_seed_all(seed)
    torch.backends.cudnn.benchmark = False
    torch.backends.cudnn.deterministic = True


def run_experiment(args: argparse.Namespace) -> Mapping[str, Any]:
    import torch
    import torchvision
    from torch.utils.data import DataLoader

    dataset_dirs = [Path(value).resolve() for value in args.dataset_dir]
    source_audit = audit_dataset_bundles(
        dataset_dirs,
        source_registry_path=Path(args.source_registry).resolve(),
    )
    output_dir = Path(args.output_dir).resolve()
    if output_dir.exists():
        raise FileExistsError(f"Aucun écrasement autorisé: {output_dir}.")
    output_dir.parent.mkdir(parents=True, exist_ok=True)
    if args.epochs < 1 or args.epochs > 200:
        raise ProtocolError("epochs doit être compris entre 1 et 200.")
    if args.batch_size < 2 or args.batch_size > 128:
        raise ProtocolError("batch_size doit être compris entre 2 et 128.")
    if not 0.05 <= args.score_threshold <= 0.95:
        raise ProtocolError("score_threshold doit être compris entre 0.05 et 0.95.")
    if not 0 <= int(args.num_workers) <= 8:
        raise ProtocolError("num_workers doit être compris entre 0 et 8.")
    if not 0.0 < float(args.learning_rate) <= 0.1:
        raise ProtocolError("learning_rate doit être compris dans ]0, 0.1].")
    if not 0.0 <= float(args.weight_decay) <= 0.1:
        raise ProtocolError("weight_decay doit être compris entre 0 et 0.1.")
    if not torch.cuda.is_available():
        raise ProtocolError("E2.2 exige un GPU CUDA Colab.")
    device = torch.device("cuda")
    _seed_everything(int(args.seed))

    train_dataset = ConcatenatedDataset(
        [CocoCharacterDataset(root, "train") for root in dataset_dirs]
    )
    validation_dataset = ConcatenatedDataset(
        [CocoCharacterDataset(root, "validation", clean_only=True) for root in dataset_dirs]
    )
    calibration_dataset = ConcatenatedDataset(
        [CocoCharacterDataset(root, "calibration", clean_only=True) for root in dataset_dirs]
    )
    generator = torch.Generator().manual_seed(int(args.seed))
    train_loader = DataLoader(
        train_dataset,
        batch_size=int(args.batch_size),
        shuffle=True,
        generator=generator,
        num_workers=int(args.num_workers),
        pin_memory=True,
        collate_fn=_collate,
        persistent_workers=bool(args.num_workers),
    )
    validation_loader = DataLoader(
        validation_dataset,
        batch_size=int(args.batch_size),
        shuffle=False,
        num_workers=int(args.num_workers),
        pin_memory=True,
        collate_fn=_collate,
        persistent_workers=bool(args.num_workers),
    )
    calibration_loader = DataLoader(
        calibration_dataset,
        batch_size=int(args.batch_size),
        shuffle=False,
        num_workers=int(args.num_workers),
        pin_memory=True,
        collate_fn=_collate,
        persistent_workers=bool(args.num_workers),
    )

    model = _make_model().to(device)
    optimizer = torch.optim.AdamW(
        (parameter for parameter in model.parameters() if parameter.requires_grad),
        lr=float(args.learning_rate),
        weight_decay=float(args.weight_decay),
    )
    scheduler = torch.optim.lr_scheduler.CosineAnnealingLR(
        optimizer, T_max=int(args.epochs), eta_min=float(args.learning_rate) * 0.05
    )
    scaler = torch.cuda.amp.GradScaler(enabled=True)

    with tempfile.TemporaryDirectory(prefix=".anpr-e2-character-", dir=output_dir.parent) as temporary:
        root = Path(temporary)
        checkpoints = root / "checkpoints"
        checkpoints.mkdir(parents=True, exist_ok=True)
        history: list[dict[str, Any]] = []
        best_key = (-1.0, -1.0, -1.0)
        best_epoch = 0
        best_state_path = checkpoints / "selected_model_state.pt"
        for epoch in range(1, int(args.epochs) + 1):
            train_loss = _train_epoch(model, train_loader, optimizer, scaler, device)
            validation_metrics = _evaluate(
                model, validation_loader, device, float(args.score_threshold)
            )
            scheduler.step()
            record = {
                "epoch": epoch,
                "train_loss": train_loss,
                "learning_rate": float(optimizer.param_groups[0]["lr"]),
                "validation": validation_metrics,
            }
            history.append(record)
            key = _candidate_key(validation_metrics)
            if key > best_key:
                best_key = key
                best_epoch = epoch
                torch.save(model.state_dict(), best_state_path)
            print(json.dumps(record, ensure_ascii=False, sort_keys=True), flush=True)

        selected_state = torch.load(best_state_path, map_location="cpu", weights_only=True)
        model.load_state_dict(selected_state, strict=True)
        model.to(device)
        selected_validation = _evaluate(
            model, validation_loader, device, float(args.score_threshold)
        )
        selected_calibration = _evaluate(
            model, calibration_loader, device, float(args.score_threshold)
        )
        checkpoint = {
            "schema_version": "1.0.0",
            "architecture": ARCHITECTURE,
            "trainer_version": TRAINER_VERSION,
            "character_protocol_version": CHARACTER_PROTOCOL_VERSION,
            "protocol_version": PROTOCOL_VERSION,
            "model_state_dict": {key: value.detach().cpu() for key, value in selected_state.items()},
            "class_to_id": CLASS_TO_ID,
            "num_classes_including_background": MODEL_NUM_CLASSES,
            "score_threshold": float(args.score_threshold),
            "selected_epoch": best_epoch,
            "seed": int(args.seed),
            "input_contract": "bounded_plate_crop_rgb_only",
            "qualification_claim": False,
            "final_test_opened": False,
        }
        checkpoint_path = checkpoints / "character_detector_ssdlite320.pt"
        torch.save(checkpoint, checkpoint_path)
        best_state_path.unlink()

        (root / "training_history.json").write_text(
            json.dumps(history, ensure_ascii=False, sort_keys=True, indent=2) + "\n",
            encoding="utf-8",
        )
        environment = {
            "python": sys.version,
            "torch": torch.__version__,
            "torchvision": torchvision.__version__,
            "cuda": torch.version.cuda,
            "cudnn": torch.backends.cudnn.version(),
            "gpu": torch.cuda.get_device_name(0),
        }
        report = {
            "schema_version": "1.0.0",
            "status": "synthetic_character_e2_complete_not_qualified"
            if not any(item["contains_real_vehicle_data"] for item in source_audit["bundles"])
            else "mixed_development_character_e2_complete_not_qualified",
            "architecture": ARCHITECTURE,
            "trainer_version": TRAINER_VERSION,
            "character_protocol_version": CHARACTER_PROTOCOL_VERSION,
            "protocol_version": PROTOCOL_VERSION,
            "repository_sha": str(args.repository_sha),
            "source_registry_sha256": file_sha256(Path(args.source_registry)),
            "source_audit": source_audit,
            "configuration": {
                "epochs": int(args.epochs),
                "batch_size": int(args.batch_size),
                "learning_rate": float(args.learning_rate),
                "weight_decay": float(args.weight_decay),
                "score_threshold": float(args.score_threshold),
                "seed": int(args.seed),
                "num_workers": int(args.num_workers),
                "alphabet": list(CHARACTER_ALPHABET),
                "num_classes_including_background": MODEL_NUM_CLASSES,
                "selection_order": [
                    "worst_format_validation_exact",
                    "aggregate_validation_exact",
                    "validation_cer",
                ],
                "calibration_used_for_selection": False,
            },
            "selection": {
                "selected_epoch": best_epoch,
                "validation": selected_validation,
                "calibration": selected_calibration,
            },
            "environment": environment,
            "artifacts": {
                "checkpoint": "checkpoints/character_detector_ssdlite320.pt",
                "checkpoint_sha256": file_sha256(checkpoint_path),
                "history": "training_history.json",
            },
            "input_contract": "vehicle_image -> plate_detector -> bounded_plate_crop -> character_detector -> Moroccan grammar -> abstention",
            "full_frame_ocr_allowed": False,
            "synthetic_only": not any(
                item["contains_real_vehicle_data"] for item in source_audit["bundles"]
            ),
            "qualification_claim": False,
            "final_test_opened": False,
            "saas_integration_allowed": False,
            "replacement_of_paddleocr_allowed": False,
            "limits": [
                "This challenger is not selected against PaddleOCR until licensed real Moroccan validation is available.",
                "Synthetic exact match is not real-photo accuracy.",
                "The independent Moroccan holdout remains unopened.",
            ],
        }
        report_path = root / "E2_CHARACTER_COMPLETE.json"
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
        root.replace(output_dir)
    return report


def build_parser() -> argparse.ArgumentParser:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument(
        "--dataset-dir",
        required=True,
        type=Path,
        action="append",
        help="Bundle COCO admis; répéter pour mélanger plusieurs sources auditées.",
    )
    parser.add_argument("--output-dir", required=True, type=Path)
    parser.add_argument("--source-registry", type=Path, default=DEFAULT_SOURCE_REGISTRY)
    parser.add_argument("--repository-sha", required=True)
    parser.add_argument("--epochs", type=int, default=18)
    parser.add_argument("--batch-size", type=int, default=16)
    parser.add_argument("--learning-rate", type=float, default=5e-4)
    parser.add_argument("--weight-decay", type=float, default=1e-4)
    parser.add_argument("--score-threshold", type=float, default=0.45)
    parser.add_argument("--seed", type=int, default=20260825)
    parser.add_argument("--num-workers", type=int, default=2)
    return parser


def main(argv: Sequence[str] | None = None) -> int:
    parser = build_parser()
    arguments = parser.parse_args(argv)
    try:
        report = run_experiment(arguments)
    except (FileExistsError, FileNotFoundError, ProtocolError) as error:
        parser.exit(2, f"Erreur: {error}\n")
    print(
        json.dumps(
            {
                "status": report["status"],
                "selected_epoch": report["selection"]["selected_epoch"],
                "validation": report["selection"]["validation"],
                "calibration": report["selection"]["calibration"],
                "qualification_claim": False,
                "final_test_opened": False,
            },
            ensure_ascii=False,
            sort_keys=True,
            indent=2,
        )
    )
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
