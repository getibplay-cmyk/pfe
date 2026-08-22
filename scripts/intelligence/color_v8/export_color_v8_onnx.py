#!/usr/bin/env python3
"""Export S7 colour v8 to ONNX only after a passing one-shot final."""

from __future__ import annotations

import argparse
import json
import zipfile
from pathlib import Path

import numpy as np
import onnx
import onnxruntime as ort
import torch
from torch import nn

from train_color_v8 import CLASSES, atomic_json, build_model, sha256_file, utc_now


class CalibratedColorModel(nn.Module):
    def __init__(self, model: nn.Module, temperature: float, threshold: float) -> None:
        super().__init__()
        self.model = model
        self.temperature = float(temperature)
        self.threshold = float(threshold)

    def forward(self, image: torch.Tensor):
        probabilities = torch.softmax(self.model(image) / self.temperature, dim=1)
        supported_confidence, supported_index = probabilities[:, :8].max(dim=1)
        all_index = probabilities.argmax(dim=1)
        accepted = (all_index < 8) & (supported_confidence >= self.threshold)
        return probabilities, supported_index, supported_confidence, accepted


def load_checkpoint(path: Path) -> dict:
    try:
        return torch.load(path, map_location="cpu", weights_only=True)
    except TypeError:
        return torch.load(path, map_location="cpu")


def main() -> None:
    parser = argparse.ArgumentParser()
    parser.add_argument("--qualification-dir", type=Path, required=True)
    parser.add_argument("--external-final-report", type=Path, required=True)
    parser.add_argument("--output-dir", type=Path, required=True)
    args = parser.parse_args()

    qualification = args.qualification_dir.resolve()
    final_report_path = args.external_final_report.resolve()
    output = args.output_dir.resolve()
    output.mkdir(parents=True, exist_ok=True)
    if any(output.iterdir()):
        raise FileExistsError(f"Output directory must be empty: {output}")

    final_report = json.loads(final_report_path.read_text(encoding="utf-8"))
    decisions = final_report.get("decisions", {})
    if final_report.get("stage") != "external_final_executed_once" or decisions.get("deployment_export_authorized") is not True:
        raise PermissionError("ONNX export requires a passing one-shot external final")
    if decisions.get("external_final_executed_exactly_once") is not True:
        raise PermissionError("External final one-shot proof is missing")

    state_path = qualification / "S7_COLOR_V8_DEVELOPMENT_QUALIFIED_STATE.pt"
    state_sha256 = sha256_file(state_path)
    if state_sha256 != final_report.get("qualified_state_sha256"):
        raise ValueError("Final report and qualified checkpoint do not match")
    checkpoint = load_checkpoint(state_path)
    if checkpoint.get("stage") != "development_qualified" or tuple(checkpoint.get("classes", ())) != CLASSES:
        raise ValueError("Invalid qualified checkpoint")

    base_model = build_model(checkpoint["architecture"], pretrained=False)
    base_model.load_state_dict(checkpoint["state_dict"], strict=True)
    wrapper = CalibratedColorModel(
        base_model,
        temperature=float(checkpoint["temperature"]),
        threshold=float(checkpoint["confidence_threshold"]),
    ).eval()
    image_size = int(checkpoint["image_size"])
    dummy = torch.linspace(-1.0, 1.0, 3 * image_size * image_size, dtype=torch.float32).reshape(1, 3, image_size, image_size)

    onnx_path = output / "S7_COLOR_V8_FINAL.onnx"
    torch.onnx.export(
        wrapper,
        dummy,
        onnx_path,
        input_names=["normalized_rgb_image"],
        output_names=["probabilities", "supported_class_index", "supported_confidence", "accepted"],
        dynamic_axes={
            "normalized_rgb_image": {0: "batch"},
            "probabilities": {0: "batch"},
            "supported_class_index": {0: "batch"},
            "supported_confidence": {0: "batch"},
            "accepted": {0: "batch"},
        },
        opset_version=18,
        do_constant_folding=True,
        dynamo=False,
    )
    onnx_model = onnx.load(onnx_path)
    onnx.checker.check_model(onnx_model)
    session = ort.InferenceSession(str(onnx_path), providers=["CPUExecutionProvider"])
    with torch.inference_mode():
        torch_outputs = wrapper(dummy)
    ort_outputs = session.run(None, {"normalized_rgb_image": dummy.numpy()})
    probability_error = float(np.max(np.abs(torch_outputs[0].numpy() - ort_outputs[0])))
    confidence_error = float(np.max(np.abs(torch_outputs[2].numpy() - ort_outputs[2])))
    class_match = bool(np.array_equal(torch_outputs[1].numpy(), ort_outputs[1]))
    accepted_match = bool(np.array_equal(torch_outputs[3].numpy(), ort_outputs[3]))
    parity_passed = probability_error <= 1e-4 and confidence_error <= 1e-4 and class_match and accepted_match
    if not parity_passed:
        raise RuntimeError("ONNX Runtime parity gate failed")

    metadata_path = output / "S7_COLOR_V8_FINAL_METADATA.json"
    metadata = {
        "schema_version": "8.0.0",
        "created_at_utc": utc_now(),
        "stage": "deployment_export_after_passing_external_final",
        "architecture": checkpoint["architecture"],
        "classes": list(CLASSES),
        "supported_classes": list(CLASSES[:8]),
        "reject_class": CLASSES[8],
        "input": {
            "name": "normalized_rgb_image",
            "layout": "NCHW",
            "dtype": "float32",
            "size": [image_size, image_size],
            "resize_short_square": max(image_size + 32, round(image_size * 256 / 224)),
            "center_crop": image_size,
            "rgb_scale": "0..1",
            "mean": [0.485, 0.456, 0.406],
            "std": [0.229, 0.224, 0.225],
        },
        "calibration": {
            "temperature": float(checkpoint["temperature"]),
            "accepted_threshold": float(checkpoint["confidence_threshold"]),
            "reject_rule": "accepted=false when reject is top-1 or supported confidence < threshold",
        },
        "provenance": {
            "qualified_state_sha256": state_sha256,
            "external_final_report_sha256": sha256_file(final_report_path),
            "external_final_manifest_sha256": final_report["final_manifest"]["sha256"],
        },
        "onnx": {
            "opset": 18,
            "sha256": sha256_file(onnx_path),
            "bytes": onnx_path.stat().st_size,
            "checker_passed": True,
            "onnxruntime_parity": {
                "passed": True,
                "maximum_probability_absolute_error": probability_error,
                "maximum_confidence_absolute_error": confidence_error,
                "class_index_exact_match": class_match,
                "accepted_exact_match": accepted_match,
            },
        },
        "integration": {
            "feature_flag": "RENTFLEET_COLOR_V8_ENABLED",
            "default": False,
            "mode": "consultative_only",
            "human_validation_required": True,
            "automatic_business_action_authorized": False,
        },
    }
    atomic_json(metadata_path, metadata)

    bundle_path = output / "S7_COLOR_V8_FINAL_DEPLOYMENT_BUNDLE.zip"
    zip_timestamp = (2026, 8, 22, 0, 0, 0)
    with zipfile.ZipFile(bundle_path, "w", compression=zipfile.ZIP_STORED, allowZip64=True) as archive:
        for path in (onnx_path, metadata_path):
            info = zipfile.ZipInfo(path.name, date_time=zip_timestamp)
            info.compress_type = zipfile.ZIP_STORED
            info.external_attr = 0o100644 << 16
            archive.writestr(info, path.read_bytes())
    print(
        json.dumps(
            {
                "status": "ONNX_EXPORT_PASS",
                "onnx_sha256": metadata["onnx"]["sha256"],
                "bundle": str(bundle_path),
                "saas_feature_flag_default": False,
            },
            indent=2,
        )
    )


if __name__ == "__main__":
    main()
