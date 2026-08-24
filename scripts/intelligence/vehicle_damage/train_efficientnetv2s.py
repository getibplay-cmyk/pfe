#!/usr/bin/env python3
"""Train, calibrate and qualify EfficientNetV2-S for vehicle damage assistance.

The final test split is evaluated exactly once, after model selection and threshold
selection. An ONNX artifact is exported only when every preregistered gate passes.
"""

from __future__ import annotations

import argparse
import csv
import hashlib
import json
import os
import platform
import random
import sys
import time
from collections import Counter
from contextlib import nullcontext
from pathlib import Path
from typing import Iterable, Mapping, Sequence

import numpy as np
import torch
from PIL import Image
from sklearn.metrics import (
    average_precision_score,
    balanced_accuracy_score,
    brier_score_loss,
    f1_score,
    precision_score,
    recall_score,
    roc_auc_score,
)
from torch import nn
from torch.utils.data import DataLoader, Dataset
from torchvision import transforms
from torchvision.models import EfficientNet_V2_S_Weights, efficientnet_v2_s

from protocol import (
    PROTOCOL_VERSION,
    TEST_LOCK_NAME,
    evaluate_release_gate,
    grouped_bootstrap_indices,
    load_manifest,
    read_completed_run,
    remove_stale_model_export,
    sha256sum_lines,
    validate_manifest,
    verify_manifest_files,
    write_json,
    write_run_completion,
)


SEED = 20260823
IMAGE_SIZE = 384
IMAGENET_MEAN = (0.485, 0.456, 0.406)
IMAGENET_STD = (0.229, 0.224, 0.225)


def seed_everything(seed: int) -> None:
    os.environ["PYTHONHASHSEED"] = str(seed)
    random.seed(seed)
    np.random.seed(seed)
    torch.manual_seed(seed)
    torch.cuda.manual_seed_all(seed)
    torch.backends.cudnn.benchmark = False
    torch.backends.cudnn.deterministic = True
    torch.use_deterministic_algorithms(True, warn_only=True)


class ManifestDataset(Dataset):
    def __init__(self, rows: Sequence[Mapping[str, str]], data_root: Path, transform) -> None:
        self.rows = list(rows)
        self.data_root = data_root
        self.transform = transform

    def __len__(self) -> int:
        return len(self.rows)

    def __getitem__(self, index: int):
        row = self.rows[index]
        image_path = self.data_root / row["image_path"]
        with Image.open(image_path) as image:
            rgb = image.convert("RGB")
            tensor = self.transform(rgb)
        return tensor, int(row["label"]), index


def build_transform(training: bool):
    if training:
        return transforms.Compose(
            [
                transforms.RandomResizedCrop(
                    IMAGE_SIZE,
                    scale=(0.75, 1.0),
                    ratio=(0.90, 1.10),
                    interpolation=transforms.InterpolationMode.BILINEAR,
                ),
                transforms.RandomHorizontalFlip(p=0.5),
                transforms.RandomRotation(5, interpolation=transforms.InterpolationMode.BILINEAR),
                transforms.ColorJitter(brightness=0.15, contrast=0.15, saturation=0.10, hue=0.02),
                transforms.ToTensor(),
                transforms.Normalize(IMAGENET_MEAN, IMAGENET_STD),
                transforms.RandomErasing(p=0.10, scale=(0.02, 0.08), ratio=(0.5, 2.0)),
            ]
        )
    return transforms.Compose(
        [
            transforms.Resize(384, interpolation=transforms.InterpolationMode.BILINEAR),
            transforms.CenterCrop(IMAGE_SIZE),
            transforms.ToTensor(),
            transforms.Normalize(IMAGENET_MEAN, IMAGENET_STD),
        ]
    )


def build_model() -> nn.Module:
    weights = EfficientNet_V2_S_Weights.IMAGENET1K_V1
    model = efficientnet_v2_s(weights=weights)
    in_features = model.classifier[-1].in_features
    model.classifier[-1] = nn.Linear(in_features, 2)
    return model


def set_feature_training(model: nn.Module, enabled: bool) -> None:
    for parameter in model.features.parameters():
        parameter.requires_grad = enabled
    for parameter in model.classifier.parameters():
        parameter.requires_grad = True


def build_optimizer(model: nn.Module, learning_rate: float, full_finetune: bool):
    if full_finetune:
        return torch.optim.AdamW(
            [
                {"params": model.features.parameters(), "lr": learning_rate * 0.10},
                {"params": model.classifier.parameters(), "lr": learning_rate},
            ],
            weight_decay=1e-4,
        )
    return torch.optim.AdamW(model.classifier.parameters(), lr=learning_rate, weight_decay=1e-4)


def amp_context(device: torch.device, enabled: bool):
    if not enabled:
        return nullcontext()
    return torch.autocast(device_type=device.type, dtype=torch.float16)


def make_scaler(enabled: bool):
    try:
        return torch.amp.GradScaler("cuda", enabled=enabled)
    except (AttributeError, TypeError):
        return torch.cuda.amp.GradScaler(enabled=enabled)


def train_one_epoch(model, loader, optimizer, criterion, scaler, device, use_amp: bool) -> float:
    model.train()
    total_loss = 0.0
    total_items = 0
    for images, labels, _ in loader:
        images = images.to(device, non_blocking=True)
        labels = labels.to(device, non_blocking=True)
        optimizer.zero_grad(set_to_none=True)
        with amp_context(device, use_amp):
            logits = model(images)
            loss = criterion(logits, labels)
        scaler.scale(loss).backward()
        scaler.unscale_(optimizer)
        torch.nn.utils.clip_grad_norm_(model.parameters(), max_norm=1.0)
        scaler.step(optimizer)
        scaler.update()
        total_loss += float(loss.detach().cpu()) * labels.size(0)
        total_items += labels.size(0)
    return total_loss / max(total_items, 1)


@torch.inference_mode()
def collect_logits(model, loader, criterion, device) -> tuple[np.ndarray, np.ndarray, float]:
    model.eval()
    logits_parts: list[np.ndarray] = []
    label_parts: list[np.ndarray] = []
    total_loss = 0.0
    total_items = 0
    for images, labels, _ in loader:
        images = images.to(device, non_blocking=True)
        labels = labels.to(device, non_blocking=True)
        logits = model(images)
        loss = criterion(logits, labels)
        logits_parts.append(logits.cpu().numpy())
        label_parts.append(labels.cpu().numpy())
        total_loss += float(loss.cpu()) * labels.size(0)
        total_items += labels.size(0)
    return (
        np.concatenate(logits_parts),
        np.concatenate(label_parts),
        total_loss / max(total_items, 1),
    )


def softmax_damage_probability(logits: np.ndarray, temperature: float) -> np.ndarray:
    scaled = logits / float(temperature)
    scaled -= scaled.max(axis=1, keepdims=True)
    exp = np.exp(scaled)
    return exp[:, 1] / exp.sum(axis=1)


def expected_calibration_error(labels: np.ndarray, probabilities: np.ndarray, bins: int = 15) -> float:
    predictions = (probabilities >= 0.5).astype(np.int64)
    confidences = np.maximum(probabilities, 1.0 - probabilities)
    edges = np.linspace(0.0, 1.0, bins + 1)
    ece = 0.0
    for lower, upper in zip(edges[:-1], edges[1:]):
        include = (confidences > lower) & (confidences <= upper)
        if not include.any():
            continue
        accuracy = float((predictions[include] == labels[include]).mean())
        confidence = float(confidences[include].mean())
        ece += float(include.mean()) * abs(accuracy - confidence)
    return ece


def classification_metrics(labels: np.ndarray, probabilities: np.ndarray, threshold: float) -> dict[str, float]:
    predictions = (probabilities >= threshold).astype(np.int64)
    negatives = labels == 0
    specificity = float((predictions[negatives] == 0).mean()) if negatives.any() else 0.0
    result = {
        "balanced_accuracy": float(balanced_accuracy_score(labels, predictions)),
        "macro_f1": float(f1_score(labels, predictions, average="macro", zero_division=0)),
        "damage_recall": float(recall_score(labels, predictions, pos_label=1, zero_division=0)),
        "damage_precision": float(precision_score(labels, predictions, pos_label=1, zero_division=0)),
        "specificity": specificity,
        "brier": float(brier_score_loss(labels, probabilities)),
        "ece": expected_calibration_error(labels, probabilities),
        "threshold": float(threshold),
    }
    if len(np.unique(labels)) == 2:
        result["roc_auc"] = float(roc_auc_score(labels, probabilities))
        result["pr_auc"] = float(average_precision_score(labels, probabilities))
    return result


def fit_temperature(logits: np.ndarray, labels: np.ndarray, device: torch.device) -> float:
    logits_tensor = torch.tensor(logits, dtype=torch.float32, device=device)
    labels_tensor = torch.tensor(labels, dtype=torch.long, device=device)
    log_temperature = torch.zeros(1, dtype=torch.float32, device=device, requires_grad=True)
    criterion = nn.CrossEntropyLoss()
    optimizer = torch.optim.LBFGS([log_temperature], lr=0.05, max_iter=100, line_search_fn="strong_wolfe")

    def closure():
        optimizer.zero_grad(set_to_none=True)
        temperature = log_temperature.exp().clamp(0.05, 20.0)
        loss = criterion(logits_tensor / temperature, labels_tensor)
        loss.backward()
        return loss

    optimizer.step(closure)
    return float(log_temperature.detach().exp().clamp(0.05, 20.0).cpu())


def choose_threshold(labels: np.ndarray, probabilities: np.ndarray, recall_floor: float = 0.75) -> float:
    candidates: list[tuple[float, float, float, float]] = []
    for threshold in np.linspace(0.05, 0.95, 181):
        metrics = classification_metrics(labels, probabilities, float(threshold))
        if metrics["damage_recall"] >= recall_floor:
            candidates.append(
                (
                    metrics["balanced_accuracy"],
                    metrics["macro_f1"],
                    -abs(float(threshold) - 0.5),
                    float(threshold),
                )
            )
    if not candidates:
        raise RuntimeError("Aucun seuil de calibration ne satisfait le rappel dommage >= 75%.")
    return max(candidates)[-1]


def bootstrap_intervals(
    labels: np.ndarray,
    probabilities: np.ndarray,
    group_ids: Sequence[str],
    threshold: float,
    iterations: int,
    seed: int,
) -> dict[str, dict[str, float]]:
    if len(labels) != len(group_ids):
        raise ValueError("labels et group_ids doivent avoir la même longueur")
    samples: dict[str, list[float]] = {
        "balanced_accuracy": [],
        "macro_f1": [],
        "damage_recall": [],
        "damage_precision": [],
        "specificity": [],
        "roc_auc": [],
        "pr_auc": [],
        "brier": [],
        "ece": [],
    }
    for sampled_indices in grouped_bootstrap_indices(group_ids, iterations, seed):
        indices = np.asarray(sampled_indices, dtype=np.int64)
        sampled_labels = labels[indices]
        if len(np.unique(sampled_labels)) < 2:
            continue
        sampled = classification_metrics(sampled_labels, probabilities[indices], threshold)
        for name in samples:
            if name in sampled:
                samples[name].append(sampled[name])
    return {
        name: {
            "lower_95": float(np.percentile(values, 2.5)),
            "upper_95": float(np.percentile(values, 97.5)),
        }
        for name, values in samples.items()
        if values
    }


def make_loaders(rows, data_root: Path, batch_size: int, workers: int):
    by_split = {split: [row for row in rows if row["split"] == split] for split in ("train", "validation", "calibration", "test")}
    generator = torch.Generator().manual_seed(SEED)
    loaders = {}
    for split, split_rows in by_split.items():
        loaders[split] = DataLoader(
            ManifestDataset(split_rows, data_root, build_transform(split == "train")),
            batch_size=batch_size,
            shuffle=split == "train",
            num_workers=workers,
            pin_memory=True,
            persistent_workers=workers > 0,
            generator=generator,
        )
    return loaders, by_split


def save_predictions(path: Path, rows, labels: np.ndarray, probabilities: np.ndarray, threshold: float) -> None:
    with path.open("w", encoding="utf-8", newline="") as handle:
        writer = csv.DictWriter(handle, fieldnames=("sample_sha256", "label", "probability_damage", "prediction"))
        writer.writeheader()
        for row, label, probability in zip(rows, labels, probabilities):
            writer.writerow(
                {
                    "sample_sha256": row["sha256"],
                    "label": int(label),
                    "probability_damage": f"{float(probability):.10f}",
                    "prediction": int(probability >= threshold),
                }
            )


class CalibratedDamageModel(nn.Module):
    def __init__(self, model: nn.Module, temperature: float) -> None:
        super().__init__()
        self.model = model
        self.register_buffer("temperature", torch.tensor(float(temperature)))

    def forward(self, images):
        logits = self.model(images) / self.temperature
        return torch.softmax(logits, dim=1)[:, 1]


def environment_payload() -> dict[str, object]:
    return {
        "python": sys.version,
        "platform": platform.platform(),
        "torch": torch.__version__,
        "torchvision": __import__("torchvision").__version__,
        "cuda_available": torch.cuda.is_available(),
        "cuda_version": torch.version.cuda,
        "gpu": torch.cuda.get_device_name(0) if torch.cuda.is_available() else None,
    }


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--manifest", required=True)
    parser.add_argument("--data-root", required=True)
    parser.add_argument("--license-root", required=True)
    parser.add_argument("--output", required=True)
    parser.add_argument("--epochs", type=int, default=15)
    parser.add_argument("--head-epochs", type=int, default=3)
    parser.add_argument("--patience", type=int, default=4)
    parser.add_argument("--batch-size", type=int, default=16)
    parser.add_argument("--workers", type=int, default=2)
    parser.add_argument("--learning-rate", type=float, default=3e-4)
    parser.add_argument("--bootstrap", type=int, default=1000)
    parser.add_argument("--resume", action="store_true")
    args = parser.parse_args()

    output = Path(args.output)
    output.mkdir(parents=True, exist_ok=True)
    completed_gate = read_completed_run(output)
    if completed_gate is not None:
        if not args.resume:
            raise RuntimeError(
                "Ce dossier contient déjà un run terminé; utilisez un nouvel --output."
            )
        print(
            json.dumps(
                {
                    "status": "ALREADY_COMPLETE",
                    "release_gate_passed": completed_gate,
                    "test_re_evaluated": False,
                },
                sort_keys=True,
            )
        )
        return 0 if completed_gate else 2

    test_lock = output / TEST_LOCK_NAME
    if test_lock.is_file():
        raise RuntimeError(
            "STOP: le verrou du test final existe sans attestation de fin. "
            "Le test a pu être touché; reprenez dans un nouveau dossier --output."
        )
    ambiguous_final_artifacts = [
        name
        for name in (
            "metrics.json",
            "test_predictions.csv",
            "model_card.json",
            "model.onnx",
            "STOP_NOT_QUALIFIED.json",
            "SHA256SUMS",
        )
        if (output / name).exists()
    ]
    if ambiguous_final_artifacts:
        raise RuntimeError(
            "STOP: artefacts finaux sans attestation complète: "
            + ", ".join(ambiguous_final_artifacts)
            + ". Utilisez un nouveau dossier --output."
        )

    if not torch.cuda.is_available():
        raise RuntimeError("GPU CUDA obligatoire pour ce lot Colab; aucun entraînement CPU silencieux.")
    if args.head_epochs < 0 or args.head_epochs >= args.epochs:
        raise ValueError("head-epochs doit être positif et strictement inférieur à epochs.")

    seed_everything(SEED)
    rows = load_manifest(args.manifest)
    manifest_report = validate_manifest(rows)
    verify_manifest_files(rows, Path(args.data_root), Path(args.license_root))
    write_json(output / "manifest_summary.json", manifest_report.as_dict())
    write_json(output / "environment.json", environment_payload())

    loaders, split_rows = make_loaders(rows, Path(args.data_root), args.batch_size, args.workers)
    train_counts = Counter(int(row["label"]) for row in split_rows["train"])
    total_train = sum(train_counts.values())
    class_weights = torch.tensor(
        [total_train / (2 * train_counts[label]) for label in (0, 1)], dtype=torch.float32
    )

    device = torch.device("cuda")
    model = build_model().to(device)
    criterion = nn.CrossEntropyLoss(weight=class_weights.to(device))
    use_amp = True
    scaler = make_scaler(use_amp)
    checkpoint_path = output / "checkpoint_last.pt"
    best_path = output / "best_state.pt"
    history: list[dict[str, float | int | str]] = []
    start_epoch = 0
    best_score = -1.0
    epochs_without_improvement = 0
    checkpoint = None
    if args.resume and checkpoint_path.is_file():
        checkpoint = torch.load(checkpoint_path, map_location=device, weights_only=False)
        model.load_state_dict(checkpoint["model_state"])
        start_epoch = int(checkpoint["epoch"]) + 1
        best_score = float(checkpoint["best_score"])
        epochs_without_improvement = int(checkpoint["epochs_without_improvement"])
        history = list(checkpoint["history"])

    current_stage = None
    optimizer = None
    scheduler = None
    started_at = time.time()
    for epoch in range(start_epoch, args.epochs):
        stage = "head" if epoch < args.head_epochs else "finetune"
        if stage != current_stage:
            full_finetune = stage == "finetune"
            set_feature_training(model, full_finetune)
            optimizer = build_optimizer(model, args.learning_rate if full_finetune else args.learning_rate * 2, full_finetune)
            scheduler = torch.optim.lr_scheduler.CosineAnnealingLR(
                optimizer, T_max=max(args.epochs - epoch, 1), eta_min=args.learning_rate * 0.01
            )
            if checkpoint is not None and checkpoint.get("stage") == stage:
                optimizer.load_state_dict(checkpoint["optimizer_state"])
                scheduler.load_state_dict(checkpoint["scheduler_state"])
            current_stage = stage

        train_loss = train_one_epoch(model, loaders["train"], optimizer, criterion, scaler, device, use_amp)
        validation_logits, validation_labels, validation_loss = collect_logits(
            model, loaders["validation"], criterion, device
        )
        validation_probabilities = softmax_damage_probability(validation_logits, 1.0)
        validation_metrics = classification_metrics(validation_labels, validation_probabilities, 0.5)
        score = validation_metrics["macro_f1"]
        scheduler.step()
        history.append(
            {
                "epoch": epoch + 1,
                "stage": stage,
                "train_loss": train_loss,
                "validation_loss": validation_loss,
                "validation_macro_f1": score,
                "validation_balanced_accuracy": validation_metrics["balanced_accuracy"],
            }
        )
        if score > best_score + 1e-6:
            best_score = score
            epochs_without_improvement = 0
            torch.save(model.state_dict(), best_path)
        else:
            epochs_without_improvement += 1

        torch.save(
            {
                "epoch": epoch,
                "stage": stage,
                "model_state": model.state_dict(),
                "optimizer_state": optimizer.state_dict(),
                "scheduler_state": scheduler.state_dict(),
                "best_score": best_score,
                "epochs_without_improvement": epochs_without_improvement,
                "history": history,
            },
            checkpoint_path,
        )
        write_json(output / "history.json", {"epochs": history})
        print(json.dumps(history[-1], sort_keys=True), flush=True)
        if stage == "finetune" and epochs_without_improvement >= args.patience:
            break

    if not best_path.is_file():
        raise RuntimeError("Aucun meilleur checkpoint n'a été produit.")
    model.load_state_dict(torch.load(best_path, map_location=device, weights_only=True))

    calibration_logits, calibration_labels, _ = collect_logits(model, loaders["calibration"], criterion, device)
    temperature = fit_temperature(calibration_logits, calibration_labels, device)
    calibration_probabilities = softmax_damage_probability(calibration_logits, temperature)
    threshold = choose_threshold(calibration_labels, calibration_probabilities, recall_floor=0.75)
    calibration_metrics = classification_metrics(calibration_labels, calibration_probabilities, threshold)

    # This durable lock is written before the first test read. If the process
    # crashes after this point, a resumed command must use a new output folder.
    write_json(
        test_lock,
        {
            "protocol_version": PROTOCOL_VERSION,
            "status": "FINAL_TEST_LOCKED",
            "test_rows": len(split_rows["test"]),
        },
    )
    # The frozen test set is touched only here, after all choices are locked.
    test_logits, test_labels, test_loss = collect_logits(model, loaders["test"], criterion, device)
    test_probabilities = softmax_damage_probability(test_logits, temperature)
    test_metrics = classification_metrics(test_labels, test_probabilities, threshold)
    test_metrics["loss"] = test_loss
    intervals = bootstrap_intervals(
        test_labels,
        test_probabilities,
        [row["group_id"] for row in split_rows["test"]],
        threshold,
        args.bootstrap,
        SEED + 1,
    )
    per_source_metrics: dict[str, dict[str, float]] = {}
    test_sources = np.array([row["source_id"] for row in split_rows["test"]])
    for source_id in sorted(set(test_sources)):
        source_mask = test_sources == source_id
        if len(np.unique(test_labels[source_mask])) < 2:
            raise RuntimeError(
                f"Le test de la source {source_id!r} ne contient pas les deux labels; "
                "aucune métrique par source fiable n'est possible."
            )
        per_source_metrics[source_id] = classification_metrics(
            test_labels[source_mask], test_probabilities[source_mask], threshold
        )
    gate = evaluate_release_gate(test_metrics)

    metrics_payload = {
        "protocol_version": PROTOCOL_VERSION,
        "seed": SEED,
        "calibration": {
            "temperature": temperature,
            "threshold": threshold,
            "metrics": calibration_metrics,
        },
        "test": {
            "metrics": test_metrics,
            "bootstrap_95": intervals,
            "per_source_metrics": per_source_metrics,
        },
        "release_gate": gate.as_dict(),
        "elapsed_seconds": time.time() - started_at,
    }
    write_json(output / "metrics.json", metrics_payload)
    save_predictions(output / "test_predictions.csv", split_rows["test"], test_labels, test_probabilities, threshold)

    model_card = {
        "model_id": "rentfleet-vehicle-damage-efficientnetv2s-v1",
        "task": "binary_consultative_vehicle_damage_presence",
        "architecture": "torchvision.efficientnet_v2_s",
        "weights_initialization": "EfficientNet_V2_S_Weights.IMAGENET1K_V1",
        "classes": {"0": "aucun_dommage_visible", "1": "dommage_visible"},
        "input": {
            "color": "RGB",
            "resize": 384,
            "crop": 384,
            "mean": IMAGENET_MEAN,
            "std": IMAGENET_STD,
        },
        "temperature": temperature,
        "decision_threshold": threshold,
        "release_gate": gate.as_dict(),
        "limitations": [
            "Assistant consultatif; validation humaine obligatoire.",
            "Ne détermine ni responsabilité, ni coût, ni décision contractuelle.",
            "La localisation du dommage n'est pas couverte par cette version binaire.",
            "Refuser ou réexaminer les images floues, sombres ou hors domaine.",
        ],
    }
    write_json(output / "model_card.json", model_card)

    if gate.passed:
        (output / "STOP_NOT_QUALIFIED.json").unlink(missing_ok=True)
        calibrated = CalibratedDamageModel(model.eval(), temperature).to(device)
        dummy = torch.zeros(1, 3, IMAGE_SIZE, IMAGE_SIZE, device=device)
        torch.onnx.export(
            calibrated,
            dummy,
            output / "model.onnx",
            input_names=["image"],
            output_names=["probability_damage"],
            dynamic_axes={"image": {0: "batch"}, "probability_damage": {0: "batch"}},
            opset_version=18,
            dynamo=False,
        )
    else:
        # Never leave a usable-looking export from another attempt in a failed
        # output. The run guard above normally prevents reuse; this is a final
        # fail-closed defence.
        remove_stale_model_export(output)
        write_json(
            output / "STOP_NOT_QUALIFIED.json",
            {"status": "STOP", "reasons": list(gate.reasons), "onnx_exported": False},
        )

    evidence_files = [
        path
        for path in output.iterdir()
        if path.is_file() and path.name not in {"SHA256SUMS", "checkpoint_last.pt"}
    ]
    (output / "SHA256SUMS").write_text(
        "\n".join(sha256sum_lines(evidence_files, output)) + "\n", encoding="utf-8"
    )
    write_run_completion(output, gate.passed)
    print(json.dumps({"release_gate": gate.as_dict(), "output": str(output)}, ensure_ascii=False, indent=2))
    return 0 if gate.passed else 2


if __name__ == "__main__":
    raise SystemExit(main())
