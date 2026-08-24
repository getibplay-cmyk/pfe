#!/usr/bin/env python3
"""Run the qualified damage-presence ONNX on overlapping private image patches."""

from __future__ import annotations

import argparse
import hashlib
import json
import sys
import uuid
from pathlib import Path
from typing import Sequence

import numpy as np
from PIL import Image


SCHEMA_VERSION = "1.0.0"
MODEL_NAME = "rentfleet_vehicle_damage_efficientnetv2s"
MODEL_VERSION = "s7-damage-efficientnetv2s-v1.1"
MODEL_CARD_ID = "rentfleet-vehicle-damage-efficientnetv2s-v1"
MODEL_TASK = "binary_consultative_vehicle_damage_presence"
MODEL_ARCHITECTURE = "torchvision.efficientnet_v2_s"
INPUT_NAME = "image"
OUTPUT_NAME = "probability_damage"
INPUT_SIZE = 384
DECISION_THRESHOLD = 0.495
OVERLAP_RATIO = 0.35
MAX_CANDIDATES = 12
MAX_INPUT_BYTES = 8_388_608
MAX_DIMENSION = 2_048
IMAGENET_MEAN = np.asarray((0.485, 0.456, 0.406), dtype=np.float32)
IMAGENET_STD = np.asarray((0.229, 0.224, 0.225), dtype=np.float32)
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
    parser.add_argument("--max-patches", type=int, default=36)
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


def validate_model_card(path: Path, expected_sha256: str) -> dict[str, object]:
    require(path.is_file() and not path.is_symlink(), "Model card unavailable")
    require(file_sha256(path) == validate_sha256(expected_sha256), "Model card integrity mismatch")
    require(100 <= path.stat().st_size <= 65_536, "Model card size invalid")
    card = json.loads(path.read_text(encoding="utf-8"))
    require(isinstance(card, dict), "Model card invalid")
    require(card.get("model_id") == MODEL_CARD_ID, "Model card id mismatch")
    require(card.get("task") == MODEL_TASK, "Model task mismatch")
    require(card.get("architecture") == MODEL_ARCHITECTURE, "Model architecture mismatch")
    require(
        card.get("classes") == {"0": "aucun_dommage_visible", "1": "dommage_visible"},
        "Model classes mismatch",
    )
    input_contract = card.get("input")
    require(isinstance(input_contract, dict), "Model input contract invalid")
    require(input_contract.get("color") == "RGB", "Model input color mismatch")
    require(input_contract.get("resize") == INPUT_SIZE, "Model resize mismatch")
    require(input_contract.get("crop") == INPUT_SIZE, "Model crop mismatch")
    require(
        np.allclose(input_contract.get("mean"), IMAGENET_MEAN, atol=1e-7),
        "Model mean mismatch",
    )
    require(
        np.allclose(input_contract.get("std"), IMAGENET_STD, atol=1e-7),
        "Model std mismatch",
    )
    threshold = card.get("decision_threshold")
    require(isinstance(threshold, (int, float)), "Model threshold invalid")
    require(abs(float(threshold) - DECISION_THRESHOLD) <= 0.000001, "Model threshold mismatch")
    gate = card.get("release_gate")
    require(isinstance(gate, dict) and gate.get("passed") is True, "Model release gate failed")
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
    if min(image.size) < INPUT_SIZE:
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


def axis_positions(length: int, window: int) -> list[int]:
    require(length >= window >= INPUT_SIZE, "Window geometry invalid")
    step = max(1, int(round(window * (1.0 - OVERLAP_RATIO))))
    positions = list(range(0, length - window + 1, step))
    final = length - window
    if positions[-1] != final:
        positions.append(final)
    return positions


def build_windows(width: int, height: int, max_patches: int) -> list[tuple[int, int, int, int]]:
    require(1 <= max_patches <= 64, "Patch limit invalid")
    minimum = min(width, height)
    require(minimum >= INPUT_SIZE, "Image is too small")
    sizes = sorted(
        {
            INPUT_SIZE,
            min(minimum, max(INPUT_SIZE, int(round(minimum * 0.55)))),
            min(minimum, max(INPUT_SIZE, int(round(minimum * 0.80)))),
        }
    )
    windows = [
        (x, y, size, size)
        for size in sizes
        for y in axis_positions(height, size)
        for x in axis_positions(width, size)
    ]
    if len(windows) <= max_patches:
        return windows
    indices = np.linspace(0, len(windows) - 1, num=max_patches, dtype=np.int64)
    return [windows[int(index)] for index in indices]


def preprocess_patch(image: Image.Image, window: tuple[int, int, int, int]) -> np.ndarray:
    x, y, width, height = window
    patch = image.crop((x, y, x + width, y + height)).resize(
        (INPUT_SIZE, INPUT_SIZE),
        resample=Image.Resampling.BILINEAR,
    )
    array = np.asarray(patch, dtype=np.float32) / np.float32(255.0)
    normalized = (array - IMAGENET_MEAN) / IMAGENET_STD
    return np.transpose(normalized, (2, 0, 1)).astype(np.float32, copy=False)


def validate_session(session: object) -> None:
    inputs = session.get_inputs()
    outputs = session.get_outputs()
    require(len(inputs) == 1 and inputs[0].name == INPUT_NAME, "ONNX input mismatch")
    require(getattr(inputs[0], "type", None) == "tensor(float)", "ONNX input type mismatch")
    require(len(outputs) == 1 and outputs[0].name == OUTPUT_NAME, "ONNX output mismatch")
    require(getattr(outputs[0], "type", None) == "tensor(float)", "ONNX output type mismatch")


def infer_probabilities(
    session: object,
    image: Image.Image,
    windows: Sequence[tuple[int, int, int, int]],
    batch_size: int = 8,
) -> np.ndarray:
    validate_session(session)
    parts: list[np.ndarray] = []
    for start in range(0, len(windows), batch_size):
        batch_windows = windows[start : start + batch_size]
        batch = np.stack([preprocess_patch(image, window) for window in batch_windows])
        values = session.run([OUTPUT_NAME], {INPUT_NAME: batch})
        require(isinstance(values, list) and len(values) == 1, "ONNX result count mismatch")
        probabilities = np.asarray(values[0], dtype=np.float64)
        if probabilities.ndim == 2 and probabilities.shape[1] == 1:
            probabilities = probabilities[:, 0]
        require(probabilities.shape == (len(batch_windows),), "ONNX probability shape mismatch")
        require(np.isfinite(probabilities).all(), "ONNX probability is not finite")
        require(((probabilities >= 0.0) & (probabilities <= 1.0)).all(), "ONNX probability range mismatch")
        parts.append(probabilities)
    return np.concatenate(parts) if parts else np.empty((0,), dtype=np.float64)


def intersection_over_union(
    first: tuple[int, int, int, int], second: tuple[int, int, int, int]
) -> float:
    ax, ay, aw, ah = first
    bx, by, bw, bh = second
    left = max(ax, bx)
    top = max(ay, by)
    right = min(ax + aw, bx + bw)
    bottom = min(ay + ah, by + bh)
    intersection = max(0, right - left) * max(0, bottom - top)
    union = aw * ah + bw * bh - intersection
    return float(intersection / union) if union > 0 else 0.0


def select_candidates(
    windows: Sequence[tuple[int, int, int, int]], probabilities: Sequence[float]
) -> list[dict[str, int | float]]:
    ranked = sorted(
        (
            (float(probability), window)
            for window, probability in zip(windows, probabilities)
            if float(probability) >= DECISION_THRESHOLD
        ),
        key=lambda item: (-item[0], item[1][1], item[1][0], item[1][2]),
    )
    selected: list[tuple[float, tuple[int, int, int, int]]] = []
    for probability, window in ranked:
        if any(intersection_over_union(window, existing) >= 0.50 for _, existing in selected):
            continue
        selected.append((probability, window))
        if len(selected) == MAX_CANDIDATES:
            break
    return [
        {
            "x": x,
            "y": y,
            "width": width,
            "height": height,
            "probability": round(probability, 7),
        }
        for probability, (x, y, width, height) in selected
    ]


def make_payload(
    run_id: str,
    input_identity: dict[str, int | str],
    model_sha256: str,
    model_card_sha256: str,
    quality_metrics: dict[str, float],
    quality_reasons: list[str],
    windows: Sequence[tuple[int, int, int, int]],
    probabilities: np.ndarray,
) -> dict[str, object]:
    abstained = bool(quality_reasons)
    max_probability = None if abstained else float(np.max(probabilities))
    suggested_damage = None if abstained else max_probability >= DECISION_THRESHOLD
    candidates = [] if abstained else select_candidates(windows, probabilities)
    if suggested_damage:
        require(candidates, "Candidate policy mismatch")
        require(abs(float(candidates[0]["probability"]) - max_probability) <= 0.000001, "Maximum policy mismatch")
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
            "mode": "coarse_overlapping_patches",
            "evaluated_patches": 0 if abstained else len(windows),
            "overlap_ratio": OVERLAP_RATIO,
            "candidate_limit": MAX_CANDIDATES,
        },
        "result": {
            "suggested_damage": suggested_damage,
            "max_probability_damage": None if max_probability is None else round(max_probability, 7),
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
    require(1 <= args.max_patches <= 64, "Patch limit invalid")
    model_sha256 = validate_sha256(args.model_sha256)
    model_card_sha256 = validate_sha256(args.model_card_sha256)
    validate_model_card(args.model_card, model_card_sha256)
    image = load_private_image(
        args.image,
        args.input_sha256,
        args.input_bytes,
        args.input_width,
        args.input_height,
    )
    metrics, reasons = quality_assessment(image)
    require(all(reason in QUALITY_REASONS for reason in reasons), "Quality reason invalid")
    windows: list[tuple[int, int, int, int]] = []
    probabilities = np.empty((0,), dtype=np.float64)
    if not reasons:
        windows = build_windows(image.width, image.height, args.max_patches)
        session = create_session(args.model, model_sha256, args.provider)
        probabilities = infer_probabilities(session, image, windows)
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
        windows,
        probabilities,
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
