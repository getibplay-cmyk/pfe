#!/usr/bin/env python3
"""Train an S7 colour v8 architecture candidate on a CUDA runtime.

This candidate-selection command never loads calibration or final images.
Only validation metrics may influence architecture and epoch selection.  A
separate command calibrates and development-qualifies the selected checkpoint.
Laravel integration remains closed regardless of this command.
"""

from __future__ import annotations

import argparse
import csv
import hashlib
import json
import os
import random
import tempfile
import time
from collections import Counter, defaultdict
from datetime import datetime, timezone
from pathlib import Path

import numpy as np
import torch
from PIL import Image, ImageOps
from sklearn.metrics import confusion_matrix, f1_score, recall_score
from torch import nn
from torch.utils.data import DataLoader, Dataset, WeightedRandomSampler
from torchvision import transforms
from torchvision.models import (
    ConvNeXt_Tiny_Weights,
    EfficientNet_V2_S_Weights,
    MobileNet_V3_Large_Weights,
    convnext_tiny,
    efficientnet_v2_s,
    mobilenet_v3_large,
)


SEED = 20260822
SUPPORTED = ("black", "blue", "gray", "green", "orange", "red", "white", "yellow")
REJECT = "__reject__"
CLASSES = SUPPORTED + (REJECT,)
THRESHOLD = 0.90
DEVELOPMENT_GATES = {
    "macro_f1_min": 0.90,
    "balanced_accuracy_min": 0.90,
    "min_class_recall_min": 0.85,
    "ece_max": 0.05,
    "support_min_per_class": 20,
    "accepted_precision_min_at_0_90": 0.95,
    "coverage_min_at_0_90": 0.50,
    "reject_false_acceptance_max_at_0_90": 0.05,
}
FUTURE_EXTERNAL_GATES = {
    "macro_f1_min": 0.85,
    "balanced_accuracy_min": 0.85,
    "min_class_recall_min": 0.80,
    "ece_max": 0.05,
    "support_min_per_class": 20,
    "accepted_precision_min_at_0_90": 0.95,
    "coverage_min_at_0_90": 0.50,
}


def utc_now() -> str:
    return datetime.now(timezone.utc).replace(microsecond=0).isoformat().replace("+00:00", "Z")


def sha256_file(path: Path, chunk_size: int = 8 * 1024 * 1024) -> str:
    digest = hashlib.sha256()
    with path.open("rb") as stream:
        for chunk in iter(lambda: stream.read(chunk_size), b""):
            digest.update(chunk)
    return digest.hexdigest()


def atomic_json(path: Path, payload: dict) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
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


def seed_everything(seed: int) -> None:
    random.seed(seed)
    np.random.seed(seed)
    torch.manual_seed(seed)
    torch.cuda.manual_seed_all(seed)
    if torch.backends.cudnn.is_available():
        torch.backends.cudnn.benchmark = False
        torch.backends.cudnn.deterministic = True


def read_manifest(path: Path, dataset_root: Path) -> list[dict]:
    rows = []
    with path.open("r", encoding="utf-8", newline="") as stream:
        for row in csv.DictReader(stream):
            target = row["target"]
            if target not in CLASSES:
                raise ValueError(f"Unsupported target {target!r}")
            image_path = (dataset_root / row["relative_path"]).resolve()
            if dataset_root not in image_path.parents or not image_path.is_file():
                raise ValueError(f"Missing or escaped image path: {row['relative_path']}")
            row["path"] = str(image_path)
            rows.append(row)
    if not rows:
        raise ValueError("Empty development manifest")
    if set(row["split"] for row in rows) != {"train", "validation", "calibration"}:
        raise ValueError("Expected train, validation and calibration splits")
    hashes_by_split: dict[str, set[str]] = defaultdict(set)
    for row in rows:
        hashes_by_split[row["split"]].add(row["sha256"])
    if any(hashes_by_split[left] & hashes_by_split[right] for left, right in (("train", "validation"), ("train", "calibration"), ("validation", "calibration"))):
        raise ValueError("Exact SHA-256 leakage across development splits")
    return rows


class ColorDataset(Dataset):
    def __init__(self, rows: list[dict], transform) -> None:
        self.rows = rows
        self.transform = transform

    def __len__(self) -> int:
        return len(self.rows)

    def __getitem__(self, index: int):
        row = self.rows[index]
        with Image.open(row["path"]) as opened:
            opened.load()
            image = ImageOps.exif_transpose(opened).convert("RGB")
        return self.transform(image), CLASSES.index(row["target"]), index


def transforms_for(split: str, image_size: int = 224):
    normalize = transforms.Normalize(mean=(0.485, 0.456, 0.406), std=(0.229, 0.224, 0.225))
    resize_size = max(image_size + 32, round(image_size * 256 / 224))
    if split == "train":
        return transforms.Compose(
            [
                transforms.Resize((resize_size, resize_size), interpolation=transforms.InterpolationMode.BICUBIC),
                transforms.RandomResizedCrop(image_size, scale=(0.78, 1.0), ratio=(0.88, 1.12), interpolation=transforms.InterpolationMode.BICUBIC),
                transforms.RandomHorizontalFlip(),
                # Brightness and contrast preserve the semantic colour. Hue and
                # saturation jitter are intentionally forbidden.
                transforms.ColorJitter(brightness=0.25, contrast=0.20, saturation=0.0, hue=0.0),
                transforms.RandomAffine(degrees=4, translate=(0.05, 0.05), scale=(0.96, 1.04)),
                transforms.ToTensor(),
                normalize,
                transforms.RandomErasing(p=0.15, scale=(0.02, 0.08), ratio=(0.5, 2.0), value="random"),
            ]
        )
    return transforms.Compose(
        [
            transforms.Resize((resize_size, resize_size), interpolation=transforms.InterpolationMode.BICUBIC),
            transforms.CenterCrop(image_size),
            transforms.ToTensor(),
            normalize,
        ]
    )


def build_model(name: str, pretrained: bool = True) -> nn.Module:
    if name == "convnext_tiny":
        model = convnext_tiny(weights=ConvNeXt_Tiny_Weights.DEFAULT if pretrained else None)
        model.classifier[2] = nn.Linear(model.classifier[2].in_features, len(CLASSES))
        return model
    if name == "efficientnet_v2_s":
        model = efficientnet_v2_s(weights=EfficientNet_V2_S_Weights.DEFAULT if pretrained else None)
        model.classifier[1] = nn.Linear(model.classifier[1].in_features, len(CLASSES))
        return model
    if name == "mobilenet_v3_large":
        model = mobilenet_v3_large(weights=MobileNet_V3_Large_Weights.DEFAULT if pretrained else None)
        model.classifier[3] = nn.Linear(model.classifier[3].in_features, len(CLASSES))
        return model
    raise ValueError(f"Unknown model {name!r}")


def expected_calibration_error(confidence: np.ndarray, correct: np.ndarray, bins: int = 15) -> float:
    error = 0.0
    edges = np.linspace(0.0, 1.0, bins + 1)
    for index in range(bins):
        upper = confidence <= edges[index + 1] if index == bins - 1 else confidence < edges[index + 1]
        mask = (confidence >= edges[index]) & upper
        if mask.any():
            error += float(mask.mean()) * abs(float(correct[mask].mean()) - float(confidence[mask].mean()))
    return float(error)


def softmax(logits: np.ndarray, temperature: float = 1.0) -> np.ndarray:
    shifted = logits.astype(np.float64) / max(float(temperature), 1e-6)
    shifted -= shifted.max(axis=1, keepdims=True)
    probabilities = np.exp(shifted)
    return probabilities / probabilities.sum(axis=1, keepdims=True)


def metric_bundle(
    labels: np.ndarray,
    probabilities: np.ndarray,
    gates: dict = DEVELOPMENT_GATES,
    required_labels: tuple[str, ...] = SUPPORTED,
) -> dict:
    if not required_labels:
        raise ValueError("At least one required label is needed")
    supported_mask = np.isin(labels, required_labels)
    labels = labels[supported_mask]
    probabilities = probabilities[supported_mask]
    if not len(labels):
        raise ValueError("No supported rows available for metrics")
    support = Counter(str(label) for label in labels)
    true_index = np.asarray([CLASSES.index(str(label)) for label in labels], dtype=int)
    predicted_index = probabilities.argmax(axis=1)
    supported_confidence = probabilities[:, : len(SUPPORTED)].max(axis=1)
    accepted = (predicted_index != CLASSES.index(REJECT)) & (supported_confidence >= THRESHOLD)
    correct = predicted_index == true_index
    required_indices = np.asarray([CLASSES.index(label) for label in required_labels], dtype=int)
    recalls = recall_score(true_index, predicted_index, labels=required_indices, average=None, zero_division=0)
    metrics = {
        "rows": int(len(labels)),
        "evaluated_classes": list(required_labels),
        "macro_f1": float(f1_score(true_index, predicted_index, labels=required_indices, average="macro", zero_division=0)),
        "balanced_accuracy": float(recalls.mean()),
        "min_class_recall": float(recalls.min()),
        "worst_class": required_labels[int(recalls.argmin())],
        "per_class_recall": {label: float(value) for label, value in zip(required_labels, recalls)},
        "support": {label: int(support.get(label, 0)) for label in required_labels},
        "ece": expected_calibration_error(supported_confidence, correct),
        "accepted_precision_at_0_90": float(correct[accepted].mean()) if accepted.any() else 0.0,
        "coverage_at_0_90": float(accepted.mean()),
        "accepted_rows_at_0_90": int(accepted.sum()),
        "confusion_matrix": confusion_matrix(true_index, predicted_index, labels=np.arange(len(CLASSES))).tolist(),
    }
    metrics["gate_checks"] = {
        "macro_f1": metrics["macro_f1"] >= gates["macro_f1_min"],
        "balanced_accuracy": metrics["balanced_accuracy"] >= gates["balanced_accuracy_min"],
        "min_class_recall": metrics["min_class_recall"] >= gates["min_class_recall_min"],
        "ece": metrics["ece"] <= gates["ece_max"],
        "support": all(metrics["support"][label] >= gates["support_min_per_class"] for label in required_labels),
        "accepted_precision_at_0_90": metrics["accepted_precision_at_0_90"] >= gates["accepted_precision_min_at_0_90"],
        "coverage_at_0_90": metrics["coverage_at_0_90"] >= gates["coverage_min_at_0_90"],
    }
    metrics["gate_passed"] = all(metrics["gate_checks"].values())
    return metrics


def reject_bundle(labels: np.ndarray, probabilities: np.ndarray) -> dict:
    mask = labels == REJECT
    probabilities = probabilities[mask]
    if not len(probabilities):
        return {"rows": 0, "false_acceptance_rate_at_0_90": 1.0, "gate_passed": False}
    predicted = probabilities.argmax(axis=1)
    supported_confidence = probabilities[:, : len(SUPPORTED)].max(axis=1)
    false_accept = (predicted != CLASSES.index(REJECT)) & (supported_confidence >= THRESHOLD)
    rate = float(false_accept.mean())
    return {
        "rows": int(len(probabilities)),
        "false_accepted_rows_at_0_90": int(false_accept.sum()),
        "false_acceptance_rate_at_0_90": rate,
        "gate_max": DEVELOPMENT_GATES["reject_false_acceptance_max_at_0_90"],
        "gate_passed": rate <= DEVELOPMENT_GATES["reject_false_acceptance_max_at_0_90"],
    }


@torch.inference_mode()
def infer(model: nn.Module, loader: DataLoader, device: torch.device) -> tuple[np.ndarray, np.ndarray, np.ndarray]:
    model.eval()
    logits, labels, row_indices = [], [], []
    for images, targets, indices in loader:
        output = model(images.to(device, non_blocking=True))
        logits.append(output.cpu().numpy())
        labels.append(targets.numpy())
        row_indices.append(indices.numpy())
    return np.concatenate(logits), np.concatenate(labels), np.concatenate(row_indices)


def temperature_from_calibration(logits: np.ndarray, labels: np.ndarray) -> tuple[float, dict]:
    label_indices = np.asarray([CLASSES.index(str(label)) for label in labels], dtype=np.int64)
    temperatures = np.linspace(0.45, 2.50, 412)
    losses = []
    for temperature in temperatures:
        probabilities = softmax(logits, float(temperature))
        losses.append(float(-np.log(np.clip(probabilities[np.arange(len(labels)), label_indices], 1e-12, 1.0)).mean()))
    best = int(np.argmin(losses))
    return float(temperatures[best]), {
        "method": "prediction_blind_scalar_grid_minimum_nll_on_calibration_only",
        "grid_min": float(temperatures[0]),
        "grid_max": float(temperatures[-1]),
        "grid_points": len(temperatures),
        "minimum_nll": losses[best],
    }


def source_metrics(rows: list[dict], indices: np.ndarray, labels: np.ndarray, probabilities: np.ndarray) -> dict:
    sources = np.asarray([rows[index]["source"] for index in indices], dtype=str)
    result = {}
    for source in sorted(set(sources)):
        mask = sources == source
        supported_counts = Counter(labels[mask][labels[mask] != REJECT])
        evaluated = tuple(
            label
            for label in SUPPORTED
            if supported_counts.get(label, 0) >= DEVELOPMENT_GATES["support_min_per_class"]
        )
        result[source] = {
            "supported": metric_bundle(labels[mask], probabilities[mask], required_labels=evaluated) if evaluated else None,
            "reject": reject_bundle(labels[mask], probabilities[mask]),
        }
    return result


def validation_gate_bundle(metrics: dict, reject: dict, sources: dict) -> tuple[bool, dict]:
    supported_source_bundles = [entry["supported"] for entry in sources.values() if entry["supported"]]
    reject_source_bundles = [
        entry["reject"]
        for entry in sources.values()
        if entry["reject"]["rows"] >= DEVELOPMENT_GATES["support_min_per_class"]
    ]
    checks = {
        "supported": metrics["gate_passed"],
        "reject": reject["gate_passed"],
        "source_supported": bool(supported_source_bundles) and all(bundle["gate_passed"] for bundle in supported_source_bundles),
        "source_reject": all(bundle["gate_passed"] for bundle in reject_source_bundles),
    }
    return all(checks.values()), checks


def selection_key(metrics: dict) -> tuple:
    return (
        bool(metrics["gate_passed"]),
        metrics["min_class_recall"],
        metrics["macro_f1"],
        metrics["balanced_accuracy"],
        metrics["accepted_precision_at_0_90"],
        metrics["coverage_at_0_90"],
        -metrics["ece"],
    )


def main() -> None:
    parser = argparse.ArgumentParser()
    parser.add_argument("--dataset-root", type=Path, required=True)
    parser.add_argument("--manifest", type=Path, required=True)
    parser.add_argument("--output-dir", type=Path, required=True)
    parser.add_argument("--model-name", choices=("convnext_tiny", "efficientnet_v2_s", "mobilenet_v3_large"), default="convnext_tiny")
    parser.add_argument("--epochs", type=int, default=18)
    parser.add_argument("--batch-size", type=int, default=64)
    parser.add_argument("--workers", type=int, default=4)
    parser.add_argument("--learning-rate", type=float, default=2.5e-4)
    parser.add_argument("--weight-decay", type=float, default=1e-4)
    parser.add_argument("--patience", type=int, default=5)
    parser.add_argument("--image-size", type=int, default=224)
    parser.add_argument("--no-pretrained", action="store_true")
    parser.add_argument("--allow-cpu-smoke", action="store_true")
    args = parser.parse_args()

    if args.epochs < 1 or args.batch_size < 1 or args.image_size < 64:
        raise ValueError("epochs and batch-size must be positive; image-size must be >= 64")

    seed_everything(SEED)
    dataset_root = args.dataset_root.resolve()
    manifest = args.manifest.resolve()
    output = args.output_dir.resolve()
    output.mkdir(parents=True, exist_ok=True)
    if any(output.iterdir()):
        raise FileExistsError(f"Output directory must be empty: {output}")
    if not torch.cuda.is_available() and not args.allow_cpu_smoke:
        raise RuntimeError("CUDA GPU required. Select a Colab GPU runtime; CPU training is intentionally refused.")
    device = torch.device("cuda" if torch.cuda.is_available() else "cpu")
    rows = read_manifest(manifest, dataset_root)
    by_split = {split: [row for row in rows if row["split"] == split] for split in ("train", "validation", "calibration")}

    train_dataset = ColorDataset(by_split["train"], transforms_for("train", args.image_size))
    validation_dataset = ColorDataset(by_split["validation"], transforms_for("validation", args.image_size))
    counts = Counter(row["target"] for row in by_split["train"])
    sample_weights = np.asarray([1.0 / np.sqrt(max(counts[row["target"]], 1)) for row in by_split["train"]], dtype=np.float64)
    sampler = WeightedRandomSampler(sample_weights, num_samples=len(sample_weights), replacement=True, generator=torch.Generator().manual_seed(SEED))
    common_loader = {"num_workers": args.workers, "pin_memory": device.type == "cuda", "persistent_workers": args.workers > 0}
    train_loader = DataLoader(train_dataset, batch_size=args.batch_size, sampler=sampler, drop_last=True, **common_loader)
    validation_loader = DataLoader(validation_dataset, batch_size=args.batch_size * 2, shuffle=False, **common_loader)

    pretrained = not args.no_pretrained
    model = build_model(args.model_name, pretrained=pretrained).to(device)
    class_weights = torch.tensor(
        [1.0 / np.sqrt(max(counts[label], 1)) for label in CLASSES],
        dtype=torch.float32,
        device=device,
    )
    class_weights /= class_weights.mean()
    criterion = nn.CrossEntropyLoss(weight=class_weights, label_smoothing=0.03)
    optimizer = torch.optim.AdamW(model.parameters(), lr=args.learning_rate, weight_decay=args.weight_decay)
    scheduler = torch.optim.lr_scheduler.CosineAnnealingLR(optimizer, T_max=max(args.epochs, 1), eta_min=args.learning_rate * 0.03)
    scaler = torch.amp.GradScaler("cuda", enabled=device.type == "cuda")

    history = []
    best_key = None
    best_state = None
    stale_epochs = 0
    started = time.monotonic()
    for epoch in range(1, args.epochs + 1):
        model.train()
        losses = []
        for images, targets, _ in train_loader:
            images = images.to(device, non_blocking=True)
            targets = targets.to(device, non_blocking=True)
            optimizer.zero_grad(set_to_none=True)
            with torch.amp.autocast(device_type=device.type, enabled=device.type == "cuda"):
                outputs = model(images)
                loss = criterion(outputs, targets)
            scaler.scale(loss).backward()
            scaler.unscale_(optimizer)
            nn.utils.clip_grad_norm_(model.parameters(), max_norm=5.0)
            scaler.step(optimizer)
            scaler.update()
            losses.append(float(loss.detach().cpu()))
        scheduler.step()
        validation_logits, validation_targets, validation_indices = infer(model, validation_loader, device)
        validation_labels = np.asarray([CLASSES[index] for index in validation_targets], dtype=str)
        epoch_probabilities = softmax(validation_logits)
        validation_metrics = metric_bundle(validation_labels, epoch_probabilities)
        epoch_reject = reject_bundle(validation_labels, epoch_probabilities)
        epoch_sources = source_metrics(by_split["validation"], validation_indices, validation_labels, epoch_probabilities)
        epoch_gate_passed, epoch_gate_checks = validation_gate_bundle(validation_metrics, epoch_reject, epoch_sources)
        key = (epoch_gate_passed, *selection_key(validation_metrics)[1:])
        if best_key is None or key > best_key:
            best_key = key
            best_state = {name: value.detach().cpu().clone() for name, value in model.state_dict().items()}
            stale_epochs = 0
        else:
            stale_epochs += 1
        record = {
            "epoch": epoch,
            "training_loss": float(np.mean(losses)),
            "learning_rate": float(optimizer.param_groups[0]["lr"]),
            "validation": validation_metrics,
            "validation_gate_checks": epoch_gate_checks,
            "elapsed_seconds": round(time.monotonic() - started, 1),
        }
        history.append(record)
        print(json.dumps({"epoch": epoch, "loss": record["training_loss"], "macro_f1": validation_metrics["macro_f1"], "balanced_accuracy": validation_metrics["balanced_accuracy"], "min_recall": validation_metrics["min_class_recall"], "stale_epochs": stale_epochs}), flush=True)
        if stale_epochs >= args.patience:
            break
    if best_state is None:
        raise RuntimeError("Training did not produce a checkpoint")
    model.load_state_dict(best_state)

    validation_logits, validation_targets, validation_indices = infer(model, validation_loader, device)
    validation_labels = np.asarray([CLASSES[index] for index in validation_targets], dtype=str)
    validation_probabilities = softmax(validation_logits)

    validation_metrics = metric_bundle(validation_labels, validation_probabilities)
    validation_reject = reject_bundle(validation_labels, validation_probabilities)
    validation_sources = source_metrics(by_split["validation"], validation_indices, validation_labels, validation_probabilities)
    candidate_validation_gate_passed, candidate_validation_gate_checks = validation_gate_bundle(
        validation_metrics,
        validation_reject,
        validation_sources,
    )

    state_path = output / f"S7_COLOR_V8_{args.model_name.upper()}_CANDIDATE_STATE.pt"
    torch.save(
        {
            "schema_version": "8.0.0",
            "stage": "candidate_selection",
            "architecture": args.model_name,
            "pretrained_initialization": pretrained,
            "classes": CLASSES,
            "image_size": args.image_size,
            "temperature": None,
            "confidence_threshold": None,
            "state_dict": best_state,
        },
        state_path,
    )
    probabilities_path = output / f"S7_COLOR_V8_{args.model_name.upper()}_VALIDATION_OUTPUTS.npz"
    np.savez_compressed(
        probabilities_path,
        classes=np.asarray(CLASSES, dtype=str),
        validation_labels=validation_labels,
        validation_row_indices=validation_indices,
        validation_logits=validation_logits,
        validation_probabilities=validation_probabilities,
    )
    history_path = output / f"S7_COLOR_V8_{args.model_name.upper()}_HISTORY.json"
    atomic_json(history_path, {"schema_version": "8.0.0", "history": history})
    report_path = output / f"S7_COLOR_V8_{args.model_name.upper()}_CANDIDATE_REPORT.json"
    report = {
        "schema_version": "8.0.0",
        "created_at_utc": utc_now(),
        "stage": "candidate_selection_validation_only",
        "candidate": args.model_name,
        "device": str(device),
        "gpu_name": torch.cuda.get_device_name(0) if device.type == "cuda" else None,
        "ontology": list(CLASSES),
        "manifest": {"path": str(manifest), "sha256": sha256_file(manifest), "rows": len(rows)},
        "training": {
            "seed": SEED,
            "epochs_requested": args.epochs,
            "epochs_completed": len(history),
            "batch_size": args.batch_size,
            "image_size": args.image_size,
            "learning_rate": args.learning_rate,
            "weight_decay": args.weight_decay,
            "pretrained_initialization": pretrained,
            "colour_preserving_augmentation": True,
            "hue_and_saturation_jitter": False,
            "train_support": {label: int(counts.get(label, 0)) for label in CLASSES},
        },
        "selection_protocol": {
            "architecture_and_epoch_selected_on": "validation_only",
            "calibration_images_loaded": False,
            "final_images_loaded": False,
            "selection_order": ["candidate_validation_gate_passed", "min_class_recall", "macro_f1", "balanced_accuracy", "accepted_precision_at_0_90", "coverage_at_0_90", "negative_ece"],
        },
        "gates": {"candidate_validation": DEVELOPMENT_GATES},
        "metrics": {
            "validation": validation_metrics,
            "validation_reject": validation_reject,
            "validation_by_source": validation_sources,
        },
        "decisions": {
            "candidate_validation_gate_passed": candidate_validation_gate_passed,
            "candidate_validation_gate_checks": candidate_validation_gate_checks,
            "eligible_for_architecture_selection": True,
            "development_gate_passed": False,
            "new_external_final_authorized": False,
            "new_external_final_executed": False,
            "saas_integration_authorized": False,
            "automatic_action_authorized": False,
            "human_validation_required": True,
        },
        "artifacts": {},
    }
    atomic_json(report_path, report)
    report["artifacts"] = {
        path.name: {"sha256": sha256_file(path), "bytes": path.stat().st_size}
        for path in (state_path, probabilities_path, history_path)
    }
    atomic_json(report_path, report)
    print(
        json.dumps(
            {
                "status": "CANDIDATE_READY",
                "candidate_validation_gate_passed": candidate_validation_gate_passed,
                "report": str(report_path),
                "validation_selection_key": [candidate_validation_gate_passed, *selection_key(validation_metrics)[1:]],
                "external_final_authorized": False,
            },
            indent=2,
        )
    )


if __name__ == "__main__":
    main()
