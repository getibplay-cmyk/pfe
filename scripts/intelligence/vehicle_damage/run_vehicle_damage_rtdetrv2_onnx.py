#!/usr/bin/env python3
"""Run the closed RT-DETRv2-S damage-detection ONNX contract."""

from __future__ import annotations

import argparse
import hashlib
import json
import math
import sys
import uuid
from pathlib import Path
from typing import Sequence

import numpy as np
from PIL import Image


SCHEMA_VERSION = "1.0.0"
MODEL_NAME = "rentfleet_vehicle_damage_rtdetrv2_s"
MODEL_VERSION = "s7-damage-rtdetrv2-s-soup192429-v1.0"
MODEL_CARD_ID = "rentfleet-vehicle-damage-rtdetrv2-s-soup-19-24-29-v1"
MODEL_TASK = "consultative_vehicle_damage_detection"
MODEL_ARCHITECTURE = "rtdetrv2_r18vd"
SOURCE_CHECKPOINT = "selected_checkpoint_soup_19_24_29_inference_only.pth"
SOURCE_CHECKPOINT_SHA256 = (
    "3544b693d9014392b5a9a0d87e6951646455ed268ca1825ee5aa4fe07cd7b92e"
)
INPUT_IMAGES_NAME = "images"
INPUT_SIZES_NAME = "orig_target_sizes"
OUTPUT_NAMES = ("labels", "boxes", "scores")
INPUT_SIZE = 640
MIN_QUALITY_DIMENSION = 384
DECISION_THRESHOLD = 0.8236151338
NMS_IOU_THRESHOLD = 0.72
MAX_CANDIDATES = 12
MAX_INPUT_BYTES = 8_388_608
MAX_DIMENSION = 2_048
QUALITY_REASONS = (
    "TOO_SMALL",
    "TOO_DARK",
    "TOO_BRIGHT",
    "LOW_CONTRAST",
    "POSSIBLY_BLURRED",
)


class RuntimeContractError(RuntimeError):
    """Raised when an input, model or ONNX output violates the closed contract."""


def require(condition: bool, message: str) -> None:
    if not condition:
        raise RuntimeContractError(message)


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--run-id", required=True)
    parser.add_argument("--image", type=Path, required=True)
    parser.add_argument("--model", type=Path, required=True)
    parser.add_argument("--model-card", type=Path, required=True)
    parser.add_argument("--model-sha256", required=True)
    parser.add_argument("--model-card-sha256", required=True)
    parser.add_argument("--input-sha256", required=True)
    parser.add_argument("--input-bytes", type=int, required=True)
    parser.add_argument("--input-width", type=int, required=True)
    parser.add_argument("--input-height", type=int, required=True)
    parser.add_argument(
        "--provider",
        choices=("CPUExecutionProvider", "CUDAExecutionProvider"),
        default="CPUExecutionProvider",
    )
    # Retained for compatibility with the Laravel executor. RT-DETR always
    # evaluates one full-image tensor and therefore accepts only the value one.
    parser.add_argument("--max-patches", type=int, default=1)
    parser.add_argument("--stdout", action="store_true")
    return parser.parse_args()


def file_sha256(path: Path) -> str:
    digest = hashlib.sha256()
    with path.open("rb") as stream:
        for block in iter(lambda: stream.read(1024 * 1024), b""):
            digest.update(block)
    return digest.hexdigest()


def validate_sha256(value: str) -> str:
    normalized = value.lower()
    require(len(normalized) == 64, "SHA-256 length mismatch")
    require(all(character in "0123456789abcdef" for character in normalized), "SHA-256 invalid")
    return normalized


def numeric_equal(actual: object, expected: float, tolerance: float = 0.000001) -> bool:
    return (
        isinstance(actual, (int, float))
        and not isinstance(actual, bool)
        and math.isfinite(float(actual))
        and abs(float(actual) - expected) <= tolerance
    )


def validate_model_card(path: Path, expected_sha256: str, model_sha256: str) -> dict[str, object]:
    require(path.is_file() and not path.is_symlink(), "Model card unavailable")
    require(file_sha256(path) == validate_sha256(expected_sha256), "Model card integrity mismatch")
    require(100 <= path.stat().st_size <= 65_536, "Model card size invalid")
    card = json.loads(path.read_text(encoding="utf-8"))
    require(isinstance(card, dict), "Model card invalid")
    require(card.get("model_id") == MODEL_CARD_ID, "Model card id mismatch")
    require(card.get("model_name") == MODEL_NAME, "Model name mismatch")
    require(card.get("model_version") == MODEL_VERSION, "Model version mismatch")
    require(card.get("task") == MODEL_TASK, "Model task mismatch")
    require(card.get("architecture") == MODEL_ARCHITECTURE, "Model architecture mismatch")
    require(card.get("classes") == {"0": "dommage_visible"}, "Model classes mismatch")
    require(card.get("onnx_sha256") == model_sha256, "ONNX identity mismatch")

    input_contract = card.get("input")
    require(isinstance(input_contract, dict), "Model input contract invalid")
    require(input_contract.get("images_name") == INPUT_IMAGES_NAME, "Image input name mismatch")
    require(input_contract.get("orig_target_sizes_name") == INPUT_SIZES_NAME, "Size input name mismatch")
    require(input_contract.get("color") == "RGB", "Model input color mismatch")
    require(input_contract.get("resize") == INPUT_SIZE, "Model resize mismatch")
    require(input_contract.get("normalization") == "zero_one", "Model normalization mismatch")
    require(card.get("outputs") == list(OUTPUT_NAMES), "Model outputs mismatch")
    require(numeric_equal(card.get("decision_threshold"), DECISION_THRESHOLD), "Model threshold mismatch")

    postprocess = card.get("postprocess")
    require(isinstance(postprocess, dict), "Postprocess contract invalid")
    require(postprocess.get("type") == "hard_nms", "Postprocess type mismatch")
    require(postprocess.get("class_agnostic") is True, "Postprocess classes mismatch")
    require(numeric_equal(postprocess.get("iou_threshold"), NMS_IOU_THRESHOLD), "NMS threshold mismatch")
    require(postprocess.get("max_candidates") == MAX_CANDIDATES, "Candidate limit mismatch")

    source = card.get("source_checkpoint")
    require(isinstance(source, dict), "Source checkpoint invalid")
    require(source.get("filename") == SOURCE_CHECKPOINT, "Source checkpoint name mismatch")
    require(source.get("sha256") == SOURCE_CHECKPOINT_SHA256, "Source checkpoint hash mismatch")
    require(source.get("epochs") == [19, 24, 29], "Source checkpoint epochs mismatch")
    weights = source.get("weights")
    require(
        isinstance(weights, list)
        and len(weights) == 3
        and all(numeric_equal(actual, expected) for actual, expected in zip(weights, (0.25, 0.5, 0.25))),
        "Source checkpoint weights mismatch",
    )

    validation = card.get("validation")
    require(isinstance(validation, dict), "Validation evidence invalid")
    require(numeric_equal(validation.get("AP"), 0.2967751101), "Validation AP mismatch")
    require(numeric_equal(validation.get("AP50"), 0.4775844593), "Validation AP50 mismatch")
    require(numeric_equal(validation.get("AP75"), 0.2862142242), "Validation AP75 mismatch")
    require(validation.get("operating_profile") == "precision_90", "Operating profile mismatch")
    require(numeric_equal(validation.get("precision_iou50"), 0.9009009009), "Validation precision mismatch")
    require(numeric_equal(validation.get("recall_iou50"), 0.2258610954), "Validation recall mismatch")
    require(validation.get("tuned_on_validation") is True, "Validation tuning disclosure missing")

    gate = card.get("scientific_gate")
    require(isinstance(gate, dict), "Scientific gate invalid")
    require(numeric_equal(gate.get("AP"), 0.40), "Scientific AP gate mismatch")
    require(numeric_equal(gate.get("AP50"), 0.65), "Scientific AP50 gate mismatch")
    require(gate.get("passed") is False, "Unexpected scientific gate state")

    safety = card.get("safety")
    require(isinstance(safety, dict), "Safety contract invalid")
    require(safety.get("human_review_required") is True, "Human review contract missing")
    require(safety.get("automatic_business_action_allowed") is False, "Automatic action forbidden")
    require(safety.get("final_test_sealed") is True, "Final test seal missing")
    require(safety.get("calibration_used") is False, "Calibration use forbidden")
    require(safety.get("test_used") is False, "Final-test use forbidden")
    require(safety.get("local_pilot_required") is True, "Local pilot contract missing")
    return card


def load_private_image(
    path: Path,
    expected_sha256: str,
    expected_bytes: int,
    expected_width: int,
    expected_height: int,
) -> Image.Image:
    require(path.is_file() and not path.is_symlink(), "Input image unavailable")
    require(1 <= expected_bytes <= MAX_INPUT_BYTES, "Input byte contract invalid")
    require(path.stat().st_size == expected_bytes, "Input byte mismatch")
    require(file_sha256(path) == validate_sha256(expected_sha256), "Input integrity mismatch")
    with Image.open(path) as probe:
        require(probe.format == "JPEG", "Input format mismatch")
        probe.verify()
    with Image.open(path) as source:
        source.load()
        require(source.mode == "RGB", "Input color mode mismatch")
        require(source.size == (expected_width, expected_height), "Input dimensions mismatch")
        require(1 <= source.width <= MAX_DIMENSION, "Input width invalid")
        require(1 <= source.height <= MAX_DIMENSION, "Input height invalid")
        require(len(source.getexif()) == 0, "Input metadata contract invalid")
        return source.copy()


def quality_assessment(image: Image.Image) -> tuple[dict[str, float], list[str]]:
    array = np.asarray(image, dtype=np.float32) / np.float32(255.0)
    gray = (
        array[:, :, 0] * np.float32(0.2126)
        + array[:, :, 1] * np.float32(0.7152)
        + array[:, :, 2] * np.float32(0.0722)
    )
    brightness = float(np.mean(gray))
    contrast = float(np.std(gray))
    if gray.shape[0] >= 3 and gray.shape[1] >= 3:
        center = gray[1:-1, 1:-1]
        laplacian = np.abs(
            np.float32(4.0) * center
            - gray[:-2, 1:-1]
            - gray[2:, 1:-1]
            - gray[1:-1, :-2]
            - gray[1:-1, 2:]
        )
        sharpness = float(np.clip(np.mean(laplacian) / np.float32(0.12), 0.0, 1.0))
    else:
        sharpness = 0.0

    reasons: list[str] = []
    if min(image.size) < MIN_QUALITY_DIMENSION:
        reasons.append("TOO_SMALL")
    if brightness < 0.08:
        reasons.append("TOO_DARK")
    if brightness > 0.95:
        reasons.append("TOO_BRIGHT")
    if contrast < 0.045:
        reasons.append("LOW_CONTRAST")
    if sharpness < 0.035:
        reasons.append("POSSIBLY_BLURRED")
    return (
        {
            "brightness": round(brightness, 7),
            "contrast": round(contrast, 7),
            "sharpness": round(sharpness, 7),
        },
        reasons,
    )


def preprocess_image(image: Image.Image) -> np.ndarray:
    resized = image.resize((INPUT_SIZE, INPUT_SIZE), resample=Image.Resampling.BILINEAR)
    array = np.asarray(resized, dtype=np.float32) / np.float32(255.0)
    tensor = np.transpose(array, (2, 0, 1)).astype(np.float32, copy=False)
    return np.expand_dims(tensor, axis=0)


def validate_session(session: object) -> None:
    inputs = session.get_inputs()
    outputs = session.get_outputs()
    require(len(inputs) == 2, "ONNX input count mismatch")
    require([item.name for item in inputs] == [INPUT_IMAGES_NAME, INPUT_SIZES_NAME], "ONNX inputs mismatch")
    require(getattr(inputs[0], "type", None) == "tensor(float)", "ONNX image input type mismatch")
    require(getattr(inputs[1], "type", None) == "tensor(int64)", "ONNX size input type mismatch")
    require(len(outputs) == 3, "ONNX output count mismatch")
    require([item.name for item in outputs] == list(OUTPUT_NAMES), "ONNX outputs mismatch")
    require(getattr(outputs[0], "type", None) == "tensor(int64)", "ONNX label output type mismatch")
    require(getattr(outputs[1], "type", None) == "tensor(float)", "ONNX box output type mismatch")
    require(getattr(outputs[2], "type", None) == "tensor(float)", "ONNX score output type mismatch")


def infer_detections(session: object, image: Image.Image) -> tuple[np.ndarray, np.ndarray, np.ndarray]:
    validate_session(session)
    values = session.run(
        list(OUTPUT_NAMES),
        {
            INPUT_IMAGES_NAME: preprocess_image(image),
            # The official postprocessor multiplies xyxy by [width, height, width, height].
            INPUT_SIZES_NAME: np.asarray([[image.width, image.height]], dtype=np.int64),
        },
    )
    require(isinstance(values, list) and len(values) == 3, "ONNX result count mismatch")
    labels = np.asarray(values[0])
    boxes = np.asarray(values[1], dtype=np.float64)
    scores = np.asarray(values[2], dtype=np.float64)
    if labels.ndim == 2 and labels.shape[0] == 1:
        labels = labels[0]
    if boxes.ndim == 3 and boxes.shape[0] == 1:
        boxes = boxes[0]
    if scores.ndim == 2 and scores.shape[0] == 1:
        scores = scores[0]
    require(labels.ndim == 1, "ONNX label shape mismatch")
    require(boxes.shape == (labels.shape[0], 4), "ONNX box shape mismatch")
    require(scores.shape == labels.shape, "ONNX score shape mismatch")
    require(np.issubdtype(labels.dtype, np.integer), "ONNX labels are not integers")
    require(np.isfinite(boxes).all(), "ONNX box is not finite")
    require(np.isfinite(scores).all(), "ONNX score is not finite")
    require(((scores >= 0.0) & (scores <= 1.0)).all(), "ONNX score range mismatch")
    require(((labels >= 0) & (labels <= 0)).all(), "ONNX class mismatch")
    return labels.astype(np.int64, copy=False), boxes, scores


def box_iou(first: Sequence[float], second: Sequence[float]) -> float:
    left = max(float(first[0]), float(second[0]))
    top = max(float(first[1]), float(second[1]))
    right = min(float(first[2]), float(second[2]))
    bottom = min(float(first[3]), float(second[3]))
    intersection = max(0.0, right - left) * max(0.0, bottom - top)
    first_area = max(0.0, float(first[2]) - float(first[0])) * max(
        0.0, float(first[3]) - float(first[1])
    )
    second_area = max(0.0, float(second[2]) - float(second[0])) * max(
        0.0, float(second[3]) - float(second[1])
    )
    union = first_area + second_area - intersection
    return intersection / union if union > 0.0 else 0.0


def valid_ranked_detections(
    boxes: np.ndarray,
    scores: np.ndarray,
    width: int,
    height: int,
) -> list[tuple[float, tuple[float, float, float, float]]]:
    detections: list[tuple[float, tuple[float, float, float, float]]] = []
    for box, score in zip(boxes, scores):
        x1 = min(max(float(box[0]), 0.0), float(width))
        y1 = min(max(float(box[1]), 0.0), float(height))
        x2 = min(max(float(box[2]), 0.0), float(width))
        y2 = min(max(float(box[3]), 0.0), float(height))
        if x2 - x1 < 1.0 or y2 - y1 < 1.0:
            continue
        detections.append((float(score), (x1, y1, x2, y2)))
    return sorted(
        detections,
        key=lambda item: (-item[0], item[1][1], item[1][0], item[1][3], item[1][2]),
    )


def select_candidates(
    detections: Sequence[tuple[float, tuple[float, float, float, float]]],
    width: int,
    height: int,
) -> list[dict[str, int | float]]:
    selected: list[tuple[float, tuple[float, float, float, float]]] = []
    for score, box in detections:
        if score < DECISION_THRESHOLD:
            break
        if any(box_iou(box, existing) >= NMS_IOU_THRESHOLD for _, existing in selected):
            continue
        selected.append((score, box))
        if len(selected) == MAX_CANDIDATES:
            break

    regions: list[dict[str, int | float]] = []
    for score, (x1, y1, x2, y2) in selected:
        left = min(max(int(math.floor(x1)), 0), width - 1)
        top = min(max(int(math.floor(y1)), 0), height - 1)
        right = min(max(int(math.ceil(x2)), left + 1), width)
        bottom = min(max(int(math.ceil(y2)), top + 1), height)
        regions.append(
            {
                "x": left,
                "y": top,
                "width": right - left,
                "height": bottom - top,
                "probability": round(score, 10),
            }
        )
    return regions


def make_payload(
    run_id: str,
    input_identity: dict[str, int | str],
    model_sha256: str,
    model_card_sha256: str,
    quality_metrics: dict[str, float],
    quality_reasons: list[str],
    detections: Sequence[tuple[float, tuple[float, float, float, float]]],
) -> dict[str, object]:
    abstained = bool(quality_reasons)
    max_probability = None if abstained else (detections[0][0] if detections else 0.0)
    suggested_damage = None if abstained else max_probability >= DECISION_THRESHOLD
    candidates = (
        []
        if abstained
        else select_candidates(
            detections,
            int(input_identity["width"]),
            int(input_identity["height"]),
        )
    )
    if suggested_damage:
        require(candidates, "Candidate policy mismatch")
        require(
            abs(float(candidates[0]["probability"]) - float(max_probability)) <= 0.000001,
            "Maximum policy mismatch",
        )
    else:
        require(not candidates, "Negative policy mismatch")

    return {
        "schema_version": SCHEMA_VERSION,
        "model": {
            "name": MODEL_NAME,
            "version": MODEL_VERSION,
            "artifact_sha256": model_sha256,
            "model_card_sha256": model_card_sha256,
            "decision_threshold": DECISION_THRESHOLD,
        },
        "input": {
            "run_id": run_id,
            "sha256": input_identity["sha256"],
            "bytes": input_identity["bytes"],
            "mime": "image/jpeg",
            "width": input_identity["width"],
            "height": input_identity["height"],
        },
        "quality": {
            "status": "abstained" if abstained else "usable",
            "reasons": quality_reasons,
            **quality_metrics,
        },
        "scan": {
            "mode": "full_image_rtdetrv2_s",
            "evaluated_patches": 0 if abstained else 1,
            "overlap_ratio": 0.0,
            "candidate_limit": MAX_CANDIDATES,
        },
        "result": {
            "suggested_damage": suggested_damage,
            "max_probability_damage": (
                None if max_probability is None else round(float(max_probability), 10)
            ),
            "candidate_count": len(candidates),
            "candidate_regions": candidates,
        },
        "safety": {
            "mode": "consultative_only",
            "human_validation_required": True,
            "automatic_business_action_allowed": False,
            "operational_effect": "NO_OPERATIONAL_ACTION",
            "local_pilot_required": True,
            "domain_validation_status": "NOT_VALIDATED_ON_RENTFLEET_PHOTOS",
            "pixel_precise_localization": False,
        },
    }


def create_session(model: Path, expected_sha256: str, provider: str) -> object:
    require(model.is_file() and not model.is_symlink(), "Model unavailable")
    require(1_000_000 <= model.stat().st_size <= 536_870_912, "Model size invalid")
    require(file_sha256(model) == validate_sha256(expected_sha256), "Model integrity mismatch")
    try:
        import onnxruntime as ort

        require(provider in ort.get_available_providers(), "Execution provider unavailable")
        options = ort.SessionOptions()
        options.log_severity_level = 3
        return ort.InferenceSession(str(model), sess_options=options, providers=[provider])
    except RuntimeContractError:
        raise
    except Exception as exception:
        raise RuntimeContractError("ONNX Runtime unavailable") from exception


def main() -> int:
    args = parse_args()
    run_id = str(uuid.UUID(args.run_id))
    require(run_id == args.run_id.lower(), "Run id invalid")
    require(args.max_patches == 1, "RT-DETR full-image contract invalid")
    model_sha256 = validate_sha256(args.model_sha256)
    model_card_sha256 = validate_sha256(args.model_card_sha256)
    validate_model_card(args.model_card, model_card_sha256, model_sha256)
    image = load_private_image(
        args.image,
        args.input_sha256,
        args.input_bytes,
        args.input_width,
        args.input_height,
    )
    metrics, reasons = quality_assessment(image)
    require(all(reason in QUALITY_REASONS for reason in reasons), "Quality reason invalid")
    detections: list[tuple[float, tuple[float, float, float, float]]] = []
    if not reasons:
        session = create_session(args.model, model_sha256, args.provider)
        _, boxes, scores = infer_detections(session, image)
        detections = valid_ranked_detections(boxes, scores, image.width, image.height)
    payload = make_payload(
        run_id,
        {
            "sha256": validate_sha256(args.input_sha256),
            "bytes": args.input_bytes,
            "width": args.input_width,
            "height": args.input_height,
        },
        model_sha256,
        model_card_sha256,
        metrics,
        reasons,
        detections,
    )
    serialized = json.dumps(payload, allow_nan=False, separators=(",", ":"), sort_keys=True)
    require(len(serialized) <= 131_072, "Output size invalid")
    if args.stdout:
        sys.stdout.write(serialized + "\n")
    return 0


if __name__ == "__main__":
    try:
        raise SystemExit(main())
    except (RuntimeContractError, OSError, ValueError, json.JSONDecodeError):
        sys.stderr.write('{"error":"DAMAGE_RUNTIME_CONTRACT_FAILED"}\n')
        raise SystemExit(2)
    except Exception:
        sys.stderr.write('{"error":"DAMAGE_RUNTIME_UNAVAILABLE"}\n')
        raise SystemExit(3)
