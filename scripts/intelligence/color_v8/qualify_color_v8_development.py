#!/usr/bin/env python3
"""Calibrate and development-qualify the validation-selected S7 colour v8.

This is the first command allowed to load calibration images.  It still cannot
load or execute the independent final, and it never authorizes SaaS integration.
"""

from __future__ import annotations

import argparse
import json
from pathlib import Path

import numpy as np
import torch
from torch.utils.data import DataLoader

from train_color_v8 import (
    CLASSES,
    DEVELOPMENT_GATES,
    FUTURE_EXTERNAL_GATES,
    ColorDataset,
    atomic_json,
    build_model,
    infer,
    metric_bundle,
    read_manifest,
    reject_bundle,
    seed_everything,
    sha256_file,
    softmax,
    source_metrics,
    temperature_from_calibration,
    transforms_for,
    utc_now,
)


def load_checkpoint(path: Path) -> dict:
    try:
        return torch.load(path, map_location="cpu", weights_only=True)
    except TypeError:
        return torch.load(path, map_location="cpu")


def source_gate(entries: dict) -> tuple[bool, bool, int, int]:
    supported = [entry["supported"] for entry in entries.values() if entry["supported"]]
    reject = [
        entry["reject"]
        for entry in entries.values()
        if entry["reject"]["rows"] >= DEVELOPMENT_GATES["support_min_per_class"]
    ]
    return (
        bool(supported) and all(bundle["gate_passed"] for bundle in supported),
        all(bundle["gate_passed"] for bundle in reject),
        len(supported),
        len(reject),
    )


def main() -> None:
    parser = argparse.ArgumentParser()
    parser.add_argument("--dataset-root", type=Path, required=True)
    parser.add_argument("--manifest", type=Path, required=True)
    parser.add_argument("--selection-dir", type=Path, required=True)
    parser.add_argument("--output-dir", type=Path, required=True)
    parser.add_argument("--batch-size", type=int, default=128)
    parser.add_argument("--workers", type=int, default=4)
    parser.add_argument("--allow-cpu-smoke", action="store_true")
    args = parser.parse_args()

    seed_everything(20260822)
    dataset_root = args.dataset_root.resolve()
    manifest = args.manifest.resolve()
    selection = args.selection_dir.resolve()
    output = args.output_dir.resolve()
    output.mkdir(parents=True, exist_ok=True)
    if any(output.iterdir()):
        raise FileExistsError(f"Output directory must be empty: {output}")
    if not torch.cuda.is_available() and not args.allow_cpu_smoke:
        raise RuntimeError("CUDA GPU required. Select a Colab GPU runtime; CPU qualification is intentionally refused.")
    device = torch.device("cuda" if torch.cuda.is_available() else "cpu")

    ledger_path = selection / "S7_COLOR_V8_SELECTION_LEDGER.json"
    ledger = json.loads(ledger_path.read_text(encoding="utf-8"))
    if ledger.get("stage") != "architecture_selected_validation_only":
        raise ValueError("Invalid selection ledger stage")
    if ledger.get("calibration_images_loaded") is not False or ledger.get("final_images_loaded") is not False:
        raise ValueError("Selection ledger reports forbidden split access")
    state_path = selection / ledger["selected"]["copied_state"]
    selected_report_path = selection / ledger["selected"]["copied_report"]
    if sha256_file(state_path) != ledger["selected"]["copied_state_sha256"]:
        raise ValueError("Selected state SHA-256 mismatch")
    if sha256_file(selected_report_path) != ledger["selected"]["copied_report_sha256"]:
        raise ValueError("Selected report SHA-256 mismatch")
    if sha256_file(manifest) != ledger["manifest_sha256"]:
        raise ValueError("Development manifest differs from candidate-selection manifest")

    checkpoint = load_checkpoint(state_path)
    if checkpoint.get("stage") != "candidate_selection" or tuple(checkpoint.get("classes", ())) != CLASSES:
        raise ValueError("Invalid selected candidate checkpoint")
    if checkpoint.get("temperature") is not None or checkpoint.get("confidence_threshold") is not None:
        raise ValueError("Candidate checkpoint was already calibrated")
    architecture = checkpoint["architecture"]
    image_size = int(checkpoint["image_size"])

    rows = read_manifest(manifest, dataset_root)
    by_split = {split: [row for row in rows if row["split"] == split] for split in ("train", "validation", "calibration")}
    validation_dataset = ColorDataset(by_split["validation"], transforms_for("validation", image_size))
    calibration_dataset = ColorDataset(by_split["calibration"], transforms_for("calibration", image_size))
    loader_options = {
        "num_workers": args.workers,
        "pin_memory": device.type == "cuda",
        "persistent_workers": args.workers > 0,
    }
    validation_loader = DataLoader(validation_dataset, batch_size=args.batch_size, shuffle=False, **loader_options)
    calibration_loader = DataLoader(calibration_dataset, batch_size=args.batch_size, shuffle=False, **loader_options)

    model = build_model(architecture, pretrained=False)
    model.load_state_dict(checkpoint["state_dict"], strict=True)
    model.to(device)
    validation_logits, validation_targets, validation_indices = infer(model, validation_loader, device)
    calibration_logits, calibration_targets, calibration_indices = infer(model, calibration_loader, device)
    validation_labels = np.asarray([CLASSES[index] for index in validation_targets], dtype=str)
    calibration_labels = np.asarray([CLASSES[index] for index in calibration_targets], dtype=str)

    temperature, temperature_audit = temperature_from_calibration(calibration_logits, calibration_labels)
    validation_probabilities = softmax(validation_logits, temperature)
    calibration_probabilities = softmax(calibration_logits, temperature)
    validation_metrics = metric_bundle(validation_labels, validation_probabilities)
    calibration_metrics = metric_bundle(calibration_labels, calibration_probabilities)
    validation_reject = reject_bundle(validation_labels, validation_probabilities)
    calibration_reject = reject_bundle(calibration_labels, calibration_probabilities)
    validation_sources = source_metrics(by_split["validation"], validation_indices, validation_labels, validation_probabilities)
    calibration_sources = source_metrics(by_split["calibration"], calibration_indices, calibration_labels, calibration_probabilities)
    validation_source_supported, validation_source_reject, validation_supported_bundles, validation_reject_bundles = source_gate(validation_sources)
    calibration_source_supported, calibration_source_reject, calibration_supported_bundles, calibration_reject_bundles = source_gate(calibration_sources)

    checks = {
        "validation_supported": validation_metrics["gate_passed"],
        "calibration_supported": calibration_metrics["gate_passed"],
        "validation_reject": validation_reject["gate_passed"],
        "calibration_reject": calibration_reject["gate_passed"],
        "validation_source_supported": validation_source_supported,
        "calibration_source_supported": calibration_source_supported,
        "validation_source_reject": validation_source_reject,
        "calibration_source_reject": calibration_source_reject,
    }
    development_gate_passed = all(checks.values())

    qualified_state_path = output / "S7_COLOR_V8_DEVELOPMENT_QUALIFIED_STATE.pt"
    torch.save(
        {
            "schema_version": "8.0.0",
            "stage": "development_qualified" if development_gate_passed else "development_gate_failed",
            "architecture": architecture,
            "pretrained_initialization": bool(checkpoint["pretrained_initialization"]),
            "classes": CLASSES,
            "image_size": image_size,
            "temperature": temperature,
            "confidence_threshold": 0.90,
            "development_manifest_sha256": sha256_file(manifest),
            "selection_ledger_sha256": sha256_file(ledger_path),
            "state_dict": checkpoint["state_dict"],
        },
        qualified_state_path,
    )
    outputs_path = output / "S7_COLOR_V8_DEVELOPMENT_QUALIFICATION_OUTPUTS.npz"
    np.savez_compressed(
        outputs_path,
        classes=np.asarray(CLASSES, dtype=str),
        validation_labels=validation_labels,
        validation_row_indices=validation_indices,
        validation_logits=validation_logits,
        validation_probabilities=validation_probabilities,
        calibration_labels=calibration_labels,
        calibration_row_indices=calibration_indices,
        calibration_logits=calibration_logits,
        calibration_probabilities=calibration_probabilities,
    )

    report_path = output / "S7_COLOR_V8_DEVELOPMENT_QUALIFICATION_REPORT.json"
    report = {
        "schema_version": "8.0.0",
        "created_at_utc": utc_now(),
        "stage": "development_qualification_after_validation_only_selection",
        "architecture": architecture,
        "device": str(device),
        "gpu_name": torch.cuda.get_device_name(0) if device.type == "cuda" else None,
        "ontology": list(CLASSES),
        "manifest": {"path": str(manifest), "sha256": sha256_file(manifest), "rows": len(rows)},
        "selection": {
            "ledger_sha256": sha256_file(ledger_path),
            "selected_candidate": ledger["selected"]["candidate"],
            "selected_state_sha256": ledger["selected"]["copied_state_sha256"],
        },
        "calibration": {
            "temperature": temperature,
            "confidence_threshold": 0.90,
            **temperature_audit,
        },
        "gates": {"development": DEVELOPMENT_GATES, "future_external": FUTURE_EXTERNAL_GATES},
        "metrics": {
            "validation": validation_metrics,
            "calibration": calibration_metrics,
            "validation_reject": validation_reject,
            "calibration_reject": calibration_reject,
            "validation_by_source": validation_sources,
            "calibration_by_source": calibration_sources,
        },
        "source_gate_audit": {
            "validation_supported_bundles": validation_supported_bundles,
            "validation_reject_bundles": validation_reject_bundles,
            "calibration_supported_bundles": calibration_supported_bundles,
            "calibration_reject_bundles": calibration_reject_bundles,
        },
        "gate_checks": checks,
        "decisions": {
            "development_gate_passed": development_gate_passed,
            "new_external_final_authorized": development_gate_passed,
            "new_external_final_executed": False,
            "saas_integration_authorized": False,
            "automatic_action_authorized": False,
            "human_validation_required": True,
        },
        "split_access": {
            "training": True,
            "validation": True,
            "calibration_first_access_after_candidate_selection": True,
            "external_final": False,
        },
        "artifacts": {},
    }
    atomic_json(report_path, report)
    report["artifacts"] = {
        path.name: {"sha256": sha256_file(path), "bytes": path.stat().st_size}
        for path in (qualified_state_path, outputs_path)
    }
    atomic_json(report_path, report)
    print(
        json.dumps(
            {
                "status": "DEVELOPMENT_GATE_PASS" if development_gate_passed else "DEVELOPMENT_GATE_FAIL",
                "report": str(report_path),
                "new_external_final_authorized": development_gate_passed,
                "saas_integration_authorized": False,
            },
            indent=2,
        )
    )


if __name__ == "__main__":
    main()
