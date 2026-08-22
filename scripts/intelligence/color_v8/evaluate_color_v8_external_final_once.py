#!/usr/bin/env python3
"""Execute the frozen S7 colour v8 external final exactly once.

All structural and cryptographic checks happen before the one-shot start token
is created.  The token is created immediately before the first inference and
blocks every later attempt, including after a runtime failure.
"""

from __future__ import annotations

import argparse
import json
import os
from collections import Counter
from pathlib import Path

import numpy as np
import torch
from torch.utils.data import DataLoader

from train_color_v8 import (
    CLASSES,
    FUTURE_EXTERNAL_GATES,
    ColorDataset,
    atomic_json,
    build_model,
    infer,
    metric_bundle,
    reject_bundle,
    seed_everything,
    sha256_file,
    softmax,
    transforms_for,
    utc_now,
)


def load_checkpoint(path: Path) -> dict:
    try:
        return torch.load(path, map_location="cpu", weights_only=True)
    except TypeError:
        return torch.load(path, map_location="cpu")


def read_final_rows(path: Path, dataset_root: Path) -> list[dict]:
    rows = [json.loads(line) for line in path.read_text(encoding="utf-8").splitlines() if line.strip()]
    if not rows:
        raise ValueError("Empty external final manifest")
    seen = set()
    for row in rows:
        if row["target"] not in CLASSES:
            raise ValueError(f"Unsupported final target {row['target']!r}")
        image_path = (dataset_root / row["relative_path"]).resolve()
        if dataset_root not in image_path.parents or not image_path.is_file():
            raise ValueError(f"Missing or escaped final image path: {row['relative_path']}")
        digest = sha256_file(image_path)
        if digest != row["sha256"] or digest in seen:
            raise ValueError(f"Final image SHA-256 mismatch or duplicate: {image_path}")
        row["path"] = str(image_path)
        seen.add(digest)
    support = Counter(row["target"] for row in rows)
    missing = {label: support.get(label, 0) for label in CLASSES if support.get(label, 0) < 20}
    if missing:
        raise ValueError(f"External final support below 20: {missing}")
    return rows


def create_start_token(path: Path, payload: dict) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    descriptor = os.open(path, os.O_WRONLY | os.O_CREAT | os.O_EXCL, 0o600)
    with os.fdopen(descriptor, "w", encoding="utf-8") as stream:
        json.dump(payload, stream, indent=2, sort_keys=True)
        stream.write("\n")
        stream.flush()
        os.fsync(stream.fileno())


def main() -> None:
    parser = argparse.ArgumentParser()
    parser.add_argument("--dataset-root", type=Path, required=True)
    parser.add_argument("--final-manifest", type=Path, required=True)
    parser.add_argument("--final-registry", type=Path, required=True)
    parser.add_argument("--qualification-dir", type=Path, required=True)
    parser.add_argument("--one-shot-ledger-dir", type=Path, required=True)
    parser.add_argument("--output-dir", type=Path, required=True)
    parser.add_argument("--batch-size", type=int, default=128)
    parser.add_argument("--workers", type=int, default=4)
    parser.add_argument("--allow-cpu-smoke", action="store_true")
    args = parser.parse_args()

    seed_everything(20260822)
    dataset_root = args.dataset_root.resolve()
    final_manifest = args.final_manifest.resolve()
    final_registry_path = args.final_registry.resolve()
    qualification = args.qualification_dir.resolve()
    one_shot_ledger = args.one_shot_ledger_dir.resolve()
    output = args.output_dir.resolve()
    output.mkdir(parents=True, exist_ok=True)
    if any(output.iterdir()):
        raise FileExistsError(f"Output directory must be empty: {output}")
    if not torch.cuda.is_available() and not args.allow_cpu_smoke:
        raise RuntimeError("CUDA GPU required. Select a Colab GPU runtime; CPU final evaluation is intentionally refused.")
    device = torch.device("cuda" if torch.cuda.is_available() else "cpu")

    qualification_report_path = qualification / "S7_COLOR_V8_DEVELOPMENT_QUALIFICATION_REPORT.json"
    qualification_report = json.loads(qualification_report_path.read_text(encoding="utf-8"))
    if qualification_report.get("decisions", {}).get("new_external_final_authorized") is not True:
        raise PermissionError("Development gate did not authorize the new external final")
    state_name = "S7_COLOR_V8_DEVELOPMENT_QUALIFIED_STATE.pt"
    state_path = qualification / state_name
    state_metadata = qualification_report.get("artifacts", {}).get(state_name, {})
    state_sha256 = sha256_file(state_path)
    if state_sha256 != state_metadata.get("sha256"):
        raise ValueError("Qualified state SHA-256 mismatch")
    checkpoint = load_checkpoint(state_path)
    if checkpoint.get("stage") != "development_qualified" or tuple(checkpoint.get("classes", ())) != CLASSES:
        raise ValueError("Checkpoint is not development-qualified")

    final_registry = json.loads(final_registry_path.read_text(encoding="utf-8"))
    if final_registry.get("status") != "FROZEN_PREDICTION_BLIND_NOT_EXECUTED":
        raise ValueError("External final registry is not frozen and untouched")
    if final_registry.get("selection", {}).get("candidate_model_used") is not False:
        raise ValueError("External final was not selected prediction-blind")
    manifest_sha256 = sha256_file(final_manifest)
    expected_manifest = final_registry.get("artifacts", {}).get(final_manifest.name, {}).get("sha256")
    if manifest_sha256 != expected_manifest:
        raise ValueError("External final manifest SHA-256 mismatch")
    if final_registry.get("independence", {}).get("development_manifest_sha256") != checkpoint["development_manifest_sha256"]:
        raise ValueError("External final independence was checked against a different development manifest")
    rows = read_final_rows(final_manifest, dataset_root)

    model = build_model(checkpoint["architecture"], pretrained=False)
    model.load_state_dict(checkpoint["state_dict"], strict=True)
    model.to(device)
    dataset = ColorDataset(rows, transforms_for("final", int(checkpoint["image_size"])))
    loader = DataLoader(
        dataset,
        batch_size=args.batch_size,
        shuffle=False,
        num_workers=args.workers,
        pin_memory=device.type == "cuda",
        persistent_workers=args.workers > 0,
    )

    start_token_path = one_shot_ledger / "S7_COLOR_V8_EXTERNAL_FINAL_ONCE_STARTED.json"
    completion_path = one_shot_ledger / "S7_COLOR_V8_EXTERNAL_FINAL_ONCE_COMPLETED.json"
    if completion_path.exists():
        raise FileExistsError(f"External final was already completed: {completion_path}")
    create_start_token(
        start_token_path,
        {
            "schema_version": "8.0.0",
            "status": "INFERENCE_STARTED_FINAL_NOW_BURNED",
            "started_at_utc": utc_now(),
            "final_manifest_sha256": manifest_sha256,
            "qualified_state_sha256": state_sha256,
            "qualification_report_sha256": sha256_file(qualification_report_path),
        },
    )

    try:
        logits, targets, row_indices = infer(model, loader, device)
        labels = np.asarray([CLASSES[index] for index in targets], dtype=str)
        probabilities = softmax(logits, float(checkpoint["temperature"]))
        supported_metrics = metric_bundle(labels, probabilities, gates=FUTURE_EXTERNAL_GATES)
        reject_metrics = reject_bundle(labels, probabilities)
        external_gate_checks = {
            "supported": supported_metrics["gate_passed"],
            "reject_false_acceptance": reject_metrics["gate_passed"],
        }
        external_gate_passed = all(external_gate_checks.values())

        outputs_path = output / "S7_COLOR_V8_EXTERNAL_FINAL_ONCE_OUTPUTS.npz"
        np.savez_compressed(
            outputs_path,
            classes=np.asarray(CLASSES, dtype=str),
            labels=labels,
            row_indices=row_indices,
            logits=logits,
            probabilities=probabilities,
        )
        report_path = output / "S7_COLOR_V8_EXTERNAL_FINAL_ONCE_REPORT.json"
        report = {
            "schema_version": "8.0.0",
            "created_at_utc": utc_now(),
            "stage": "external_final_executed_once",
            "device": str(device),
            "gpu_name": torch.cuda.get_device_name(0) if device.type == "cuda" else None,
            "architecture": checkpoint["architecture"],
            "ontology": list(CLASSES),
            "final_manifest": {"sha256": manifest_sha256, "rows": len(rows)},
            "qualified_state_sha256": state_sha256,
            "qualification_report_sha256": sha256_file(qualification_report_path),
            "temperature": float(checkpoint["temperature"]),
            "confidence_threshold": float(checkpoint["confidence_threshold"]),
            "gates": {"supported": FUTURE_EXTERNAL_GATES, "reject_false_acceptance_max_at_0_90": 0.05},
            "metrics": {"supported": supported_metrics, "reject": reject_metrics},
            "gate_checks": external_gate_checks,
            "decisions": {
                "external_gate_passed": external_gate_passed,
                "external_final_executed_exactly_once": True,
                "deployment_export_authorized": external_gate_passed,
                "saas_integration_gate_passed": external_gate_passed,
                "saas_integration_executed": False,
                "feature_flag_default": False,
                "automatic_business_action_authorized": False,
                "human_validation_required": True,
            },
            "artifacts": {
                outputs_path.name: {"sha256": sha256_file(outputs_path), "bytes": outputs_path.stat().st_size}
            },
        }
        atomic_json(report_path, report)
        atomic_json(
            completion_path,
            {
                "schema_version": "8.0.0",
                "status": "COMPLETED_PASS" if external_gate_passed else "COMPLETED_FAIL",
                "completed_at_utc": utc_now(),
                "start_token_sha256": sha256_file(start_token_path),
                "final_report_sha256": sha256_file(report_path),
                "external_gate_passed": external_gate_passed,
            },
        )
    except Exception as error:
        atomic_json(
            completion_path,
            {
                "schema_version": "8.0.0",
                "status": "FAILED_AFTER_INFERENCE_STARTED_FINAL_REMAINS_BURNED",
                "completed_at_utc": utc_now(),
                "start_token_sha256": sha256_file(start_token_path),
                "error_type": type(error).__name__,
                "error": str(error),
                "external_gate_passed": False,
            },
        )
        raise

    print(
        json.dumps(
            {
                "status": "EXTERNAL_FINAL_PASS" if external_gate_passed else "EXTERNAL_FINAL_FAIL",
                "report": str(report_path),
                "saas_integration_gate_passed": external_gate_passed,
                "saas_integration_executed": False,
            },
            indent=2,
        )
    )


if __name__ == "__main__":
    main()
