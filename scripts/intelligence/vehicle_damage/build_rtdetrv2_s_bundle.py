#!/usr/bin/env python3
"""Verify evidence and atomically build the private RT-DETRv2-S SaaS bundle."""

from __future__ import annotations

import argparse
import hashlib
import json
import os
import shutil
import tempfile
from pathlib import Path


CHECKPOINT_FILENAME = "selected_checkpoint_soup_19_24_29_inference_only.pth"
CHECKPOINT_SHA256 = "3544b693d9014392b5a9a0d87e6951646455ed268ca1825ee5aa4fe07cd7b92e"
CHECKPOINT_BYTES = 80_772_267
DECISION_THRESHOLD = 0.8236151337623596
UPSTREAM_COMMIT = "068dfde65f2667ad6555883c69d73de886518cad"


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--checkpoint", required=True, type=Path)
    parser.add_argument("--policy", required=True, type=Path)
    parser.add_argument("--onnx", required=True, type=Path)
    parser.add_argument("--output", required=True, type=Path)
    return parser.parse_args()


def sha256(path: Path) -> str:
    digest = hashlib.sha256()
    with path.open("rb") as stream:
        for block in iter(lambda: stream.read(1024 * 1024), b""):
            digest.update(block)
    return digest.hexdigest()


def require(condition: bool, message: str) -> None:
    if not condition:
        raise RuntimeError(message)


def close_enough(actual: object, expected: float) -> bool:
    return (
        isinstance(actual, (int, float))
        and not isinstance(actual, bool)
        and abs(float(actual) - expected) <= 0.000001
    )


def validate_policy(path: Path) -> dict[str, object]:
    require(path.is_file() and not path.is_symlink(), "Inference policy unavailable.")
    require(100 <= path.stat().st_size <= 65_536, "Inference policy size invalid.")
    policy = json.loads(path.read_text(encoding="utf-8"))
    require(isinstance(policy, dict), "Inference policy invalid.")
    require(policy.get("schema_version") == "2.0.0", "Inference policy version mismatch.")
    require(policy.get("model") == "RT-DETRv2-S RentFleet", "Inference policy model mismatch.")
    require(policy.get("selected_checkpoint") == CHECKPOINT_FILENAME, "Checkpoint name mismatch.")
    require(policy.get("selected_checkpoint_bytes") == CHECKPOINT_BYTES, "Checkpoint size mismatch.")
    require(policy.get("selected_checkpoint_sha256") == CHECKPOINT_SHA256, "Checkpoint hash mismatch.")
    require(policy.get("selected_variant") == "soup_19_24_29_centered_nms_0.72", "Variant mismatch.")
    require(policy.get("fixed_input_size") == 640, "Input-size policy mismatch.")
    require(policy.get("default_operating_profile") == "precision_90", "Operating profile mismatch.")
    require(policy.get("calibration_used") is False, "Calibration data use is forbidden.")
    require(policy.get("test_used") is False, "Final-test data use is forbidden.")
    require(policy.get("final_test_sealed") is True, "Final-test seal is missing.")
    require(policy.get("deployment_requires_human_review") is True, "Human-review policy is missing.")

    postprocess = policy.get("postprocess")
    require(isinstance(postprocess, dict), "Postprocess policy missing.")
    require(postprocess.get("type") == "hard_nms", "Postprocess type mismatch.")
    require(postprocess.get("class_agnostic") is True, "Postprocess class policy mismatch.")
    require(close_enough(postprocess.get("iou_threshold"), 0.72), "NMS threshold mismatch.")

    operating_points = policy.get("operating_points_validation_iou50")
    require(isinstance(operating_points, dict), "Operating points missing.")
    precision_90 = operating_points.get("precision_90")
    require(isinstance(precision_90, dict), "Precision-90 profile missing.")
    require(close_enough(precision_90.get("score_threshold"), DECISION_THRESHOLD), "Score threshold mismatch.")
    require(close_enough(precision_90.get("precision_iou50"), 0.9009009009009009), "Precision evidence mismatch.")
    require(close_enough(precision_90.get("recall_iou50"), 0.22586109542631283), "Recall evidence mismatch.")

    metrics = policy.get("validation_metrics")
    require(isinstance(metrics, dict), "Validation metrics missing.")
    require(close_enough(metrics.get("AP"), 0.2967751100548477), "Validation AP mismatch.")
    require(close_enough(metrics.get("AP50"), 0.4775844593080958), "Validation AP50 mismatch.")
    require(close_enough(metrics.get("AP75"), 0.28621422418385917), "Validation AP75 mismatch.")
    require(isinstance(policy.get("validation_tuned_warning"), str), "Validation disclosure missing.")

    gate = policy.get("threshold_gate")
    require(isinstance(gate, dict), "Scientific gate missing.")
    require(close_enough(gate.get("AP"), 0.40), "Scientific AP gate mismatch.")
    require(close_enough(gate.get("AP50"), 0.65), "Scientific AP50 gate mismatch.")
    require(gate.get("passed") is False, "Unexpected scientific gate state.")

    weight_average = policy.get("weight_average")
    require(isinstance(weight_average, dict), "Weight-average evidence missing.")
    require(weight_average.get("epochs") == [19, 24, 29], "Weight-average epochs mismatch.")
    require(weight_average.get("weights") == [0.25, 0.5, 0.25], "Weight-average values mismatch.")
    return policy


def validate_onnx(path: Path) -> str:
    require(path.is_file() and not path.is_symlink(), "ONNX artifact unavailable.")
    require(1_000_000 <= path.stat().st_size <= 536_870_912, "ONNX artifact size invalid.")
    try:
        import onnx

        model = onnx.load(str(path), load_external_data=False)
        onnx.checker.check_model(model)
    except Exception as exception:
        raise RuntimeError("ONNX structural validation failed.") from exception
    require([item.name for item in model.graph.input] == ["images", "orig_target_sizes"], "ONNX inputs mismatch.")
    require([item.name for item in model.graph.output] == ["labels", "boxes", "scores"], "ONNX outputs mismatch.")
    require(not any(initializer.external_data for initializer in model.graph.initializer), "External ONNX data forbidden.")
    return sha256(path)


def main() -> int:
    args = parse_args()
    checkpoint = args.checkpoint.resolve()
    policy_path = args.policy.resolve()
    onnx_path = args.onnx.resolve()
    output = args.output.resolve()
    require(checkpoint.is_file() and not checkpoint.is_symlink(), "Checkpoint unavailable.")
    require(checkpoint.name == CHECKPOINT_FILENAME, "Checkpoint filename mismatch.")
    require(checkpoint.stat().st_size == CHECKPOINT_BYTES, "Checkpoint byte count mismatch.")
    require(sha256(checkpoint) == CHECKPOINT_SHA256, "Checkpoint SHA-256 mismatch.")
    policy = validate_policy(policy_path)
    onnx_sha256 = validate_onnx(onnx_path)
    require(not output.exists() and not output.is_symlink(), "Refusing to overwrite a bundle.")
    output.parent.mkdir(mode=0o700, parents=True, exist_ok=True)

    card = {
        "model_id": "rentfleet-vehicle-damage-rtdetrv2-s-soup-19-24-29-v1",
        "model_name": "rentfleet_vehicle_damage_rtdetrv2_s",
        "model_version": "s7-damage-rtdetrv2-s-soup192429-v1.0",
        "task": "consultative_vehicle_damage_detection",
        "architecture": "rtdetrv2_r18vd",
        "classes": {"0": "dommage_visible"},
        "onnx_sha256": onnx_sha256,
        "decision_threshold": DECISION_THRESHOLD,
        "input": {
            "images_name": "images",
            "orig_target_sizes_name": "orig_target_sizes",
            "color": "RGB",
            "resize": 640,
            "normalization": "zero_one",
        },
        "outputs": ["labels", "boxes", "scores"],
        "postprocess": {
            "type": "hard_nms",
            "class_agnostic": True,
            "iou_threshold": 0.72,
            "max_candidates": 12,
        },
        "source_checkpoint": {
            "filename": CHECKPOINT_FILENAME,
            "sha256": CHECKPOINT_SHA256,
            "epochs": [19, 24, 29],
            "weights": [0.25, 0.5, 0.25],
        },
        "validation": {
            "AP": policy["validation_metrics"]["AP"],
            "AP50": policy["validation_metrics"]["AP50"],
            "AP75": policy["validation_metrics"]["AP75"],
            "operating_profile": "precision_90",
            "precision_iou50": policy["operating_points_validation_iou50"]["precision_90"]["precision_iou50"],
            "recall_iou50": policy["operating_points_validation_iou50"]["precision_90"]["recall_iou50"],
            "tuned_on_validation": True,
        },
        "scientific_gate": {"AP": 0.40, "AP50": 0.65, "passed": False},
        "safety": {
            "human_review_required": True,
            "automatic_business_action_allowed": False,
            "final_test_sealed": True,
            "calibration_used": False,
            "test_used": False,
            "local_pilot_required": True,
        },
        "provenance": {
            "official_upstream_commit": UPSTREAM_COMMIT,
            "policy_sha256": sha256(policy_path),
        },
    }

    temporary = Path(tempfile.mkdtemp(prefix=".rentfleet-rtdetr-bundle-", dir=output.parent))
    try:
        shutil.copyfile(onnx_path, temporary / "model.onnx")
        shutil.copyfile(policy_path, temporary / "selected_inference_policy.json")
        card_path = temporary / "model_card.json"
        card_path.write_text(
            json.dumps(card, ensure_ascii=False, indent=2, sort_keys=True) + "\n",
            encoding="utf-8",
        )
        checksums = {
            name: sha256(temporary / name)
            for name in ["model.onnx", "model_card.json", "selected_inference_policy.json"]
        }
        (temporary / "SHA256SUMS").write_text(
            "".join(f"{digest}  {name}\n" for name, digest in checksums.items()),
            encoding="ascii",
        )
        for path in temporary.iterdir():
            os.chmod(path, 0o600)
        os.replace(temporary, output)
    finally:
        if temporary.exists():
            shutil.rmtree(temporary)

    print(json.dumps({"bundle": output.name, "files": checksums}, sort_keys=True))
    return 0


if __name__ == "__main__":
    try:
        raise SystemExit(main())
    except (OSError, RuntimeError, ValueError, json.JSONDecodeError) as exception:
        raise SystemExit(f"bundle build failed: {exception}")
