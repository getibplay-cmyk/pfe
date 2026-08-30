#!/usr/bin/env python3
"""Private, fail-closed plate-localisation worker for the SaaS pilot.

The worker accepts one already-sanitised vehicle image, verifies the private
checkpoint before loading it, selects at most one unambiguous plate, and writes
only a bounded JPEG crop plus a closed JSON result.  OCR is deliberately out of
scope: the full vehicle image must never be sent to the recognition runtime.
"""

from __future__ import annotations

import argparse
import hashlib
import hmac
import json
import math
import os
import platform
import re
import sys
import time
from collections.abc import Mapping, Sequence
from pathlib import Path
from typing import Any


SCHEMA_VERSION = "1.0.0"
MODEL_NAME = "fasterrcnn_resnet50_fpn_v2_e32_selected_private"
ARCHITECTURE = "fasterrcnn_resnet50_fpn_v2"
SCORE_FLOOR = 0.001
MAX_IMAGE_DIMENSION = 4096
MAX_IMAGE_PIXELS = MAX_IMAGE_DIMENSION * MAX_IMAGE_DIMENSION
MAX_CHECKPOINT_BYTES = 2_147_483_648
MAX_CROP_BYTES = 2_097_152
SHA256_RE = re.compile(r"^[a-f0-9]{64}$")
RUN_ID_RE = re.compile(
    r"^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$"
)


class DetectorWorkerError(ValueError):
    """Closed worker failure carrying only a non-sensitive error code."""

    def __init__(self, code: str) -> None:
        super().__init__(code)
        self.code = code


def file_sha256(path: str | Path) -> str:
    digest = hashlib.sha256()
    with Path(path).open("rb") as handle:
        for chunk in iter(lambda: handle.read(1024 * 1024), b""):
            digest.update(chunk)
    return digest.hexdigest()


def secure_child(root: str | Path, name: str, *, must_exist: bool) -> Path:
    """Resolve a basename below a bounded root without following a file link."""

    if (
        not name
        or name in {".", ".."}
        or "/" in name
        or "\\" in name
        or Path(name).is_absolute()
        or Path(name).name != name
    ):
        raise DetectorWorkerError("PATH_OUTSIDE_BOUNDARY")
    try:
        root_path = Path(root).resolve(strict=True)
    except OSError as exception:
        raise DetectorWorkerError("ROOT_INVALID") from exception
    if not root_path.is_dir():
        raise DetectorWorkerError("ROOT_INVALID")
    candidate = root_path / name
    if candidate.is_symlink():
        raise DetectorWorkerError("SYMLINK_FORBIDDEN")
    resolved = candidate.resolve(strict=must_exist)
    if resolved.parent != root_path:
        raise DetectorWorkerError("PATH_OUTSIDE_BOUNDARY")
    if must_exist and not resolved.is_file():
        raise DetectorWorkerError("FILE_INVALID")
    return resolved


def box_iou(left: Sequence[float], right: Sequence[float]) -> float:
    if len(left) != 4 or len(right) != 4:
        raise DetectorWorkerError("DETECTION_OUTPUT_INVALID")
    lx1, ly1, lx2, ly2 = (float(item) for item in left)
    rx1, ry1, rx2, ry2 = (float(item) for item in right)
    intersection_width = max(0.0, min(lx2, rx2) - max(lx1, rx1))
    intersection_height = max(0.0, min(ly2, ry2) - max(ly1, ry1))
    intersection = intersection_width * intersection_height
    left_area = max(0.0, lx2 - lx1) * max(0.0, ly2 - ly1)
    right_area = max(0.0, rx2 - rx1) * max(0.0, ry2 - ry1)
    union = left_area + right_area - intersection
    return intersection / union if union > 0.0 else 0.0


def expand_box(
    box: Sequence[float], width: int, height: int, padding_ratio: float
) -> tuple[int, int, int, int]:
    if width < 1 or height < 1 or len(box) != 4:
        raise DetectorWorkerError("DETECTION_OUTPUT_INVALID")
    x1, y1, x2, y2 = (float(item) for item in box)
    if not all(math.isfinite(value) for value in (x1, y1, x2, y2)):
        raise DetectorWorkerError("DETECTION_OUTPUT_INVALID")
    x1 = min(max(x1, 0.0), float(width))
    y1 = min(max(y1, 0.0), float(height))
    x2 = min(max(x2, 0.0), float(width))
    y2 = min(max(y2, 0.0), float(height))
    if x2 <= x1 or y2 <= y1:
        raise DetectorWorkerError("DETECTION_OUTPUT_INVALID")
    pad_x = (x2 - x1) * padding_ratio
    pad_y = (y2 - y1) * padding_ratio
    expanded = (
        max(0, int(math.floor(x1 - pad_x))),
        max(0, int(math.floor(y1 - pad_y))),
        min(width, int(math.ceil(x2 + pad_x))),
        min(height, int(math.ceil(y2 + pad_y))),
    )
    if expanded[2] <= expanded[0] or expanded[3] <= expanded[1]:
        raise DetectorWorkerError("DETECTION_OUTPUT_INVALID")
    return expanded


def select_detection(
    boxes: Sequence[Sequence[float]],
    labels: Sequence[int],
    scores: Sequence[float],
    threshold: float,
) -> dict[str, Any]:
    """Choose one plate or abstain when distinct candidates are too close."""

    if len(boxes) != len(labels) or len(boxes) != len(scores):
        raise DetectorWorkerError("DETECTION_OUTPUT_INVALID")
    eligible: list[tuple[tuple[float, float, float, float], float]] = []
    for raw_box, raw_label, raw_score in zip(boxes, labels, scores, strict=True):
        if len(raw_box) != 4:
            raise DetectorWorkerError("DETECTION_OUTPUT_INVALID")
        box = tuple(float(value) for value in raw_box)
        score = float(raw_score)
        if not all(math.isfinite(value) for value in (*box, score)):
            raise DetectorWorkerError("DETECTION_OUTPUT_INVALID")
        if box[2] <= box[0] or box[3] <= box[1] or not 0.0 <= score <= 1.0:
            raise DetectorWorkerError("DETECTION_OUTPUT_INVALID")
        if int(raw_label) == 1 and score >= threshold:
            eligible.append((box, score))
    if not eligible:
        return {
            "status": "no_detection",
            "score": None,
            "bbox": None,
            "eligible_count": 0,
            "ambiguous": False,
        }

    eligible.sort(key=lambda item: item[1], reverse=True)
    top_box, top_score = eligible[0]
    ambiguous = any(
        second_score >= max(threshold, top_score - 0.05)
        and box_iou(top_box, second_box) < 0.30
        for second_box, second_score in eligible[1:]
    )
    return {
        "status": "ambiguous" if ambiguous else "detected",
        "score": top_score,
        "bbox": list(top_box),
        "eligible_count": len(eligible),
        "ambiguous": ambiguous,
    }


def verify_checkpoint(path: str | Path, expected_sha256: str) -> Path:
    checkpoint = Path(path)
    if not SHA256_RE.fullmatch(expected_sha256):
        raise DetectorWorkerError("CHECKPOINT_SHA256_INVALID")
    if not checkpoint.is_absolute() or not checkpoint.is_file() or checkpoint.is_symlink():
        raise DetectorWorkerError("CHECKPOINT_INVALID")
    size = checkpoint.stat().st_size
    if size < 1_000_000 or size > MAX_CHECKPOINT_BYTES:
        raise DetectorWorkerError("CHECKPOINT_INVALID")
    if not hmac.compare_digest(file_sha256(checkpoint), expected_sha256):
        raise DetectorWorkerError("CHECKPOINT_INTEGRITY_MISMATCH")
    return checkpoint.resolve(strict=True)


def load_detector(checkpoint_path: Path, device: str) -> tuple[Any, dict[str, Any]]:
    import torch
    from torchvision.models.detection import fasterrcnn_resnet50_fpn_v2
    from torchvision.models.detection.faster_rcnn import FastRCNNPredictor

    torch_device = "cpu" if device == "cpu" else "cuda:0"
    if device == "gpu:0" and not torch.cuda.is_available():
        raise DetectorWorkerError("CUDA_UNAVAILABLE")
    checkpoint = torch.load(checkpoint_path, map_location="cpu", weights_only=True)
    if not isinstance(checkpoint, Mapping):
        raise DetectorWorkerError("CHECKPOINT_CONTRACT_INVALID")
    if str(checkpoint.get("architecture", ARCHITECTURE)) != ARCHITECTURE:
        raise DetectorWorkerError("CHECKPOINT_CONTRACT_INVALID")
    try:
        min_size = int(checkpoint.get("min_size", 768))
        max_size = int(checkpoint.get("max_size", 1280))
    except (TypeError, ValueError) as error:
        raise DetectorWorkerError("CHECKPOINT_CONTRACT_INVALID") from error
    if not 256 <= min_size <= max_size <= MAX_IMAGE_DIMENSION:
        raise DetectorWorkerError("CHECKPOINT_CONTRACT_INVALID")
    state = checkpoint.get("model_state_dict")
    if not isinstance(state, Mapping):
        raise DetectorWorkerError("CHECKPOINT_CONTRACT_INVALID")

    model = fasterrcnn_resnet50_fpn_v2(
        weights=None,
        weights_backbone=None,
        trainable_backbone_layers=3,
        min_size=min_size,
        max_size=max_size,
        box_score_thresh=SCORE_FLOOR,
        box_nms_thresh=0.50,
        box_detections_per_img=100,
    )
    in_features = model.roi_heads.box_predictor.cls_score.in_features
    model.roi_heads.box_predictor = FastRCNNPredictor(in_features, 2)
    model.load_state_dict(state, strict=True)
    model.to(torch_device).eval()
    return model, {
        "torch_device": torch_device,
        "min_size": min_size,
        "max_size": max_size,
    }


def run_inference(model: Any, image: Any, threshold: float, device: str) -> dict[str, Any]:
    import torch
    from torchvision.transforms.functional import pil_to_tensor

    torch_device = "cpu" if device == "cpu" else "cuda:0"
    tensor = pil_to_tensor(image).float().div_(255.0).to(torch_device)
    with torch.inference_mode():
        output = model([tensor])[0]
    return select_detection(
        output["boxes"].detach().cpu().tolist(),
        output["labels"].detach().cpu().tolist(),
        output["scores"].detach().cpu().tolist(),
        threshold,
    )


def atomic_json(path: Path, payload: Mapping[str, Any]) -> None:
    temporary = path.with_name(f".{path.name}.tmp")
    encoded = json.dumps(
        dict(payload), ensure_ascii=False, sort_keys=True, separators=(",", ":")
    )
    temporary.write_text(encoded, encoding="utf-8")
    os.chmod(temporary, 0o600)
    temporary.replace(path)


def execute(args: argparse.Namespace) -> dict[str, Any]:
    if not RUN_ID_RE.fullmatch(args.run_id):
        raise DetectorWorkerError("RUN_ID_INVALID")
    if args.device not in {"cpu", "gpu:0"}:
        raise DetectorWorkerError("DEVICE_INVALID")
    threshold = float(args.threshold)
    padding_ratio = float(args.padding_ratio)
    if not SCORE_FLOOR <= threshold < 1.0:
        raise DetectorWorkerError("THRESHOLD_INVALID")
    if not 0.0 <= padding_ratio <= 0.25:
        raise DetectorWorkerError("PADDING_INVALID")

    image_path = secure_child(args.image_root, args.image_name, must_exist=True)
    crop_path = secure_child(args.output_root, args.crop_name, must_exist=False)
    result_path = secure_child(args.output_root, args.result_name, must_exist=False)
    checkpoint_path = verify_checkpoint(args.checkpoint, args.expected_sha256)

    from PIL import Image
    import torch
    import torchvision

    Image.MAX_IMAGE_PIXELS = MAX_IMAGE_PIXELS
    with Image.open(image_path) as opened:
        if opened.format != "JPEG":
            raise DetectorWorkerError("INPUT_FORMAT_INVALID")
        opened.verify()
    with Image.open(image_path) as opened:
        image = opened.convert("RGB")
    width, height = image.size
    if not 1 <= width <= MAX_IMAGE_DIMENSION or not 1 <= height <= MAX_IMAGE_DIMENSION:
        raise DetectorWorkerError("INPUT_DIMENSIONS_INVALID")

    load_started = time.perf_counter()
    model, model_metadata = load_detector(checkpoint_path, args.device)
    model_load = time.perf_counter() - load_started
    inference_started = time.perf_counter()
    selected = run_inference(model, image, threshold, args.device)
    inference = time.perf_counter() - inference_started

    crop_metadata: dict[str, Any] | None = None
    if selected["status"] == "detected":
        crop_box = expand_box(selected["bbox"], width, height, padding_ratio)
        crop = image.crop(crop_box)
        temporary_crop = crop_path.with_name(f".{crop_path.name}.tmp")
        crop.save(temporary_crop, format="JPEG", quality=95, optimize=True)
        os.chmod(temporary_crop, 0o600)
        if temporary_crop.stat().st_size < 1 or temporary_crop.stat().st_size > MAX_CROP_BYTES:
            temporary_crop.unlink(missing_ok=True)
            raise DetectorWorkerError("CROP_SIZE_INVALID")
        temporary_crop.replace(crop_path)
        crop_metadata = {
            "mime": "image/jpeg",
            "bytes": crop_path.stat().st_size,
            "sha256": file_sha256(crop_path),
            "width": crop.width,
            "height": crop.height,
            "padding_ratio": padding_ratio,
            "bbox": list(crop_box),
        }

    payload = {
        "schema_version": SCHEMA_VERSION,
        "model_name": MODEL_NAME,
        "architecture": ARCHITECTURE,
        "run_id": args.run_id,
        "status": selected["status"],
        "checkpoint_sha256": args.expected_sha256,
        "threshold": threshold,
        "score": selected["score"],
        "bbox": selected["bbox"],
        "image": {
            "width": width,
            "height": height,
            "sha256": file_sha256(image_path),
        },
        "detection": {
            "eligible_count": selected["eligible_count"],
            "ambiguous": selected["ambiguous"],
        },
        "crop": crop_metadata,
        "timings_seconds": {
            "model_load": model_load,
            "inference": inference,
        },
        "environment": {
            "python": platform.python_version(),
            "torch": str(torch.__version__),
            "torchvision": str(torchvision.__version__),
            "device": args.device,
            "isolated_process": True,
            "min_size": model_metadata["min_size"],
            "max_size": model_metadata["max_size"],
        },
        "safeguards": {
            "development_only": True,
            "human_review_required": True,
            "automatic_vehicle_update_allowed": False,
            "full_frame_ocr_allowed": False,
        },
    }
    atomic_json(result_path, payload)
    return payload


def build_parser() -> argparse.ArgumentParser:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--run-id", required=True)
    parser.add_argument("--image-root", required=True)
    parser.add_argument("--image-name", required=True)
    parser.add_argument("--output-root", required=True)
    parser.add_argument("--crop-name", default="crop.jpg")
    parser.add_argument("--result-name", default="result.json")
    parser.add_argument("--checkpoint", required=True)
    parser.add_argument("--expected-sha256", required=True)
    parser.add_argument("--threshold", required=True, type=float)
    parser.add_argument("--padding-ratio", default=0.04, type=float)
    parser.add_argument("--device", choices=("cpu", "gpu:0"), default="cpu")
    return parser


def main() -> int:
    try:
        execute(build_parser().parse_args())
        print(json.dumps({"event": "plate_detector_complete"}), flush=True)
        return 0
    except DetectorWorkerError as error:
        print(
            json.dumps({"event": "plate_detector_failed", "code": error.code}),
            file=sys.stderr,
            flush=True,
        )
        return 2
    except Exception:
        print(
            json.dumps({"event": "plate_detector_failed", "code": "INTERNAL_FAILURE"}),
            file=sys.stderr,
            flush=True,
        )
        return 3


if __name__ == "__main__":
    raise SystemExit(main())
