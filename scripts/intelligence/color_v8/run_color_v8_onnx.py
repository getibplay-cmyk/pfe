#!/usr/bin/env python3
"""Run the frozen S7 colour-v8 ONNX model for one private vehicle image.

The adapter emits only the closed consultative JSON contract consumed by
Laravel. It never writes to RentFleet operational data or trains a model.
"""

from __future__ import annotations

import argparse
import hashlib
import json
import math
import os
import sys
from pathlib import Path
from typing import Any

os.environ.setdefault("ORT_DISABLE_TELEMETRY_EVENTS", "1")

import numpy as np
from PIL import Image, ImageOps


RESULT_SCHEMA_VERSION = "1.0.0"
MODEL_SCHEMA_VERSION = "8.0.0"
MODEL_NAME = "vehicle_color_mobilenet_v3_large"
MODEL_VERSION = "s7-color-v8.0.0"
MODEL_SHA256 = "5ec7757a7bafda0abd45685dd8e1178e5b6b79220ff61b6018398d00f2e86a76"
MODEL_BYTES = 16_848_914
METADATA_SHA256 = "661b0dcaa9b66fc69a2d8ba55eb21ec806e66c05d86c06ef4b2c5e7ff71901e6"
METADATA_BYTES = 1_987
ACCEPTED_THRESHOLD = 0.977
CLASSES = (
    "black",
    "blue",
    "gray",
    "green",
    "orange",
    "red",
    "white",
    "yellow",
    "__reject__",
)
SUPPORTED_CLASSES = CLASSES[:-1]
REJECT_CLASS = "__reject__"
INPUT_NAME = "normalized_rgb_image"
OUTPUT_NAMES = (
    "probabilities",
    "supported_class_index",
    "supported_confidence",
    "accepted",
)
MIME_BY_FORMAT = {"JPEG": "image/jpeg", "PNG": "image/png", "WEBP": "image/webp"}
MEAN = np.asarray((0.485, 0.456, 0.406), dtype=np.float32)
STD = np.asarray((0.229, 0.224, 0.225), dtype=np.float32)
Image.MAX_IMAGE_PIXELS = 64_000_000


class RuntimeContractError(RuntimeError):
    """Raised when an input, artifact, runtime, or result violates the contract."""


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--image", type=Path, required=True)
    parser.add_argument("--model", type=Path, required=True)
    parser.add_argument("--metadata", type=Path, required=True)
    parser.add_argument(
        "--provider",
        choices=("CPUExecutionProvider", "CUDAExecutionProvider"),
        default="CPUExecutionProvider",
    )
    output = parser.add_mutually_exclusive_group(required=True)
    output.add_argument("--output", type=Path)
    output.add_argument("--stdout", action="store_true")
    return parser.parse_args()


def require(condition: bool, message: str) -> None:
    if not condition:
        raise RuntimeContractError(message)


def file_sha256(path: Path) -> str:
    digest = hashlib.sha256()
    with path.open("rb") as stream:
        for block in iter(lambda: stream.read(1024 * 1024), b""):
            digest.update(block)
    return digest.hexdigest()


def verify_file(path: Path, expected_bytes: int, expected_sha256: str) -> None:
    require(path.is_file() and not path.is_symlink(), "Artifact is missing or unsafe.")
    require(path.stat().st_size == expected_bytes, "Artifact size mismatch.")
    require(file_sha256(path) == expected_sha256, "Artifact digest mismatch.")


def load_metadata(path: Path) -> dict[str, Any]:
    verify_file(path, METADATA_BYTES, METADATA_SHA256)
    payload = json.loads(path.read_text(encoding="utf-8"))
    require(isinstance(payload, dict), "Metadata root must be an object.")
    require(payload.get("schema_version") == MODEL_SCHEMA_VERSION, "Metadata schema mismatch.")
    require(payload.get("classes") == list(CLASSES), "Metadata classes mismatch.")
    require(
        payload.get("supported_classes") == list(SUPPORTED_CLASSES),
        "Metadata supported classes mismatch.",
    )
    require(payload.get("reject_class") == REJECT_CLASS, "Metadata reject class mismatch.")
    require(payload.get("onnx", {}).get("sha256") == MODEL_SHA256, "Metadata model digest mismatch.")
    require(payload.get("onnx", {}).get("bytes") == MODEL_BYTES, "Metadata model size mismatch.")
    require(
        payload.get("calibration", {}).get("accepted_threshold") == ACCEPTED_THRESHOLD,
        "Metadata threshold mismatch.",
    )
    integration = payload.get("integration", {})
    require(integration.get("mode") == "consultative_only", "Metadata mode mismatch.")
    require(integration.get("human_validation_required") is True, "Human review must be required.")
    require(
        integration.get("automatic_business_action_authorized") is False,
        "Automatic business actions must be disabled.",
    )
    return payload


def load_image(path: Path) -> tuple[np.ndarray, dict[str, Any]]:
    require(path.is_file() and not path.is_symlink(), "Input image is missing or unsafe.")
    input_bytes = path.stat().st_size
    require(1 <= input_bytes <= 8_388_608, "Input image size is outside the contract.")
    input_sha256 = file_sha256(path)

    with Image.open(path) as probe:
        image_format = probe.format
        probe.verify()
    require(image_format in MIME_BY_FORMAT, "Input image format is unsupported.")

    with Image.open(path) as source:
        source.load()
        rgb = ImageOps.exif_transpose(source).convert("RGB")
        require(rgb.width <= 8_000 and rgb.height <= 8_000, "Input dimensions are too large.")
        resized = rgb.resize((256, 256), resample=Image.Resampling.BICUBIC)
        cropped = resized.crop((16, 16, 240, 240))
        array = np.asarray(cropped, dtype=np.float32) / np.float32(255.0)

    require(array.shape == (224, 224, 3), "Preprocessed input shape mismatch.")
    normalized = (array - MEAN) / STD
    tensor = np.transpose(normalized, (2, 0, 1))[None, ...].astype(np.float32, copy=False)
    require(tensor.shape == (1, 3, 224, 224), "ONNX input shape mismatch.")
    require(bool(np.isfinite(tensor).all()), "ONNX input contains non-finite values.")
    return tensor, {
        "sha256": input_sha256,
        "bytes": input_bytes,
        "mime": MIME_BY_FORMAT[image_format],
    }


def create_session(model: Path, provider: str) -> Any:
    import onnxruntime as ort

    ort.disable_telemetry_events()
    verify_file(model, MODEL_BYTES, MODEL_SHA256)
    require(sys.version_info[:2] == (3, 12), "Python 3.12 is required.")
    require(np.__version__ == "2.3.5", "numpy 2.3.5 is required.")
    require(ort.__version__ == "1.29.0", "onnxruntime 1.29.0 is required.")
    available = ort.get_available_providers()
    require(provider in available, "Requested ONNX execution provider is unavailable.")
    providers = [provider]
    if provider == "CUDAExecutionProvider":
        providers.append("CPUExecutionProvider")
    options = ort.SessionOptions()
    options.enable_mem_pattern = True
    options.log_severity_level = 3
    return ort.InferenceSession(str(model), sess_options=options, providers=providers)


def run_inference(session: Any, tensor: np.ndarray) -> tuple[np.ndarray, int, float, bool]:
    inputs = session.get_inputs()
    outputs = session.get_outputs()
    require(len(inputs) == 1, "Unexpected ONNX input count.")
    require(inputs[0].name == INPUT_NAME, "Unexpected ONNX input name.")
    require(inputs[0].type == "tensor(float)", "Unexpected ONNX input type.")
    require([output.name for output in outputs] == list(OUTPUT_NAMES), "Unexpected ONNX outputs.")
    require(
        [output.type for output in outputs]
        == ["tensor(float)", "tensor(int64)", "tensor(float)", "tensor(bool)"],
        "Unexpected ONNX output types.",
    )
    values = session.run(list(OUTPUT_NAMES), {INPUT_NAME: tensor})
    require(len(values) == 4, "Unexpected ONNX result count.")

    probabilities = np.asarray(values[0], dtype=np.float64)
    supported_index = np.asarray(values[1]).reshape(-1)
    confidence = np.asarray(values[2], dtype=np.float64).reshape(-1)
    accepted = np.asarray(values[3]).reshape(-1)
    require(probabilities.shape == (1, len(CLASSES)), "Unexpected probability shape.")
    require(supported_index.shape == (1,), "Unexpected class-index shape.")
    require(confidence.shape == (1,), "Unexpected confidence shape.")
    require(accepted.shape == (1,), "Unexpected accepted shape.")
    require(np.issubdtype(supported_index.dtype, np.integer), "Unexpected class-index type.")
    require(np.issubdtype(accepted.dtype, np.bool_), "Unexpected accepted type.")
    require(bool(np.isfinite(probabilities).all()), "Probabilities contain non-finite values.")
    require(bool((probabilities >= 0).all() and (probabilities <= 1).all()), "Probabilities are invalid.")
    require(abs(float(probabilities.sum()) - 1.0) <= 0.001, "Probabilities do not sum to one.")

    class_index = int(supported_index[0])
    supported_confidence = float(confidence[0])
    model_accepted = bool(accepted[0])
    require(0 <= class_index < len(SUPPORTED_CLASSES), "Supported class index is invalid.")
    expected_index = int(np.argmax(probabilities[0, : len(SUPPORTED_CLASSES)]))
    top_index = int(np.argmax(probabilities[0]))
    expected_confidence = float(probabilities[0, expected_index])
    expected_accepted = top_index != len(CLASSES) - 1 and expected_confidence >= ACCEPTED_THRESHOLD
    require(class_index == expected_index, "Supported class policy mismatch.")
    require(abs(supported_confidence - expected_confidence) <= 0.00001, "Confidence policy mismatch.")
    require(model_accepted is expected_accepted, "Acceptance policy mismatch.")
    return probabilities[0], class_index, supported_confidence, model_accepted


def make_payload(
    probabilities: np.ndarray,
    supported_index: int,
    confidence: float,
    accepted: bool,
    input_identity: dict[str, Any],
) -> dict[str, Any]:
    top_index = int(np.argmax(probabilities))
    ordered_probabilities = {
        class_name: float(probabilities[index]) for index, class_name in enumerate(CLASSES)
    }
    require(all(math.isfinite(value) for value in ordered_probabilities.values()), "Invalid probability.")
    return {
        "schema_version": RESULT_SCHEMA_VERSION,
        "model": {
            "name": MODEL_NAME,
            "version": MODEL_VERSION,
            "artifact_sha256": MODEL_SHA256,
            "metadata_sha256": METADATA_SHA256,
        },
        "input": input_identity,
        "result": {
            "suggested_color": SUPPORTED_CLASSES[supported_index],
            "confidence": confidence,
            "accepted": accepted,
            "top_class_index": top_index,
            "top_class": CLASSES[top_index],
            "probabilities": ordered_probabilities,
        },
        "safety": {
            "mode": "consultative_only",
            "human_validation_required": True,
            "automatic_business_action_allowed": False,
            "operational_effect": "NO_OPERATIONAL_ACTION",
        },
    }


def write_payload(payload: dict[str, Any], output: Path | None) -> None:
    encoded = json.dumps(payload, ensure_ascii=False, separators=(",", ":")) + "\n"
    if output is None:
        sys.stdout.write(encoded)
        return
    output.parent.mkdir(mode=0o700, parents=True, exist_ok=True)
    temporary = output.with_name(f".{output.name}.{os.getpid()}.tmp")
    temporary.write_text(encoded, encoding="utf-8")
    os.chmod(temporary, 0o600)
    temporary.replace(output)


def main() -> int:
    args = parse_args()
    load_metadata(args.metadata)
    tensor, input_identity = load_image(args.image)
    session = create_session(args.model, args.provider)
    probabilities, supported_index, confidence, accepted = run_inference(session, tensor)
    payload = make_payload(probabilities, supported_index, confidence, accepted, input_identity)
    write_payload(payload, None if args.stdout else args.output)
    return 0


if __name__ == "__main__":
    try:
        raise SystemExit(main())
    except (RuntimeContractError, json.JSONDecodeError, OSError, ValueError):
        sys.stderr.write('{"error":"COLOR_RUNTIME_CONTRACT_FAILED"}\n')
        raise SystemExit(2)
    except Exception:
        sys.stderr.write('{"error":"COLOR_RUNTIME_FAILED"}\n')
        raise SystemExit(3)
