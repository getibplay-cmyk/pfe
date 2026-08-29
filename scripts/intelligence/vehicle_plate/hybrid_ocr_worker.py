#!/usr/bin/env python3
"""Run the consultative full-crop then segmented PP-OCRv5 fallback.

The worker reuses ``arabic_PP-OCRv5_mobile_rec`` for every observation.  It
first reads two full-crop variants.  Only crops rejected by the Moroccan
grammar are split into bounded serial, series and region zones.  The output is
private review evidence and must never be treated as an automatic update.
"""

from __future__ import annotations

import argparse
import json
import math
import platform
import sys
import time
from collections import Counter, defaultdict
from dataclasses import dataclass
from pathlib import Path
from typing import Any, Mapping, Sequence


REPOSITORY_ROOT = Path(__file__).resolve().parents[3]
if str(REPOSITORY_ROOT) not in sys.path:
    sys.path.insert(0, str(REPOSITORY_ROOT))

from scripts.intelligence.vehicle_plate.colab_smoke import (
    OCR_MODEL_NAME,
    extract_ocr_result,
)
from scripts.intelligence.vehicle_plate.hybrid_fallback import (
    HYBRID_FALLBACK_VERSION,
    HybridSuggestion,
    OcrObservation,
    build_hybrid_suggestion,
)
from scripts.intelligence.vehicle_plate.paddle_ocr_worker import _read_manifest
from scripts.intelligence.vehicle_plate.protocol import ProtocolError


OUTPUT_SCHEMA_VERSION = "1.0.0"
ALLOWED_DEVICES = frozenset({"cpu", "gpu:0"})
VARIANT_IDS = ("original", "clahe")
ROLE_ORDER = ("serial", "series", "region")


@dataclass(frozen=True)
class PixelZone:
    role: str
    box: tuple[int, int, int, int]


@dataclass(frozen=True)
class ZoneLayout:
    layout_id: str
    zones: tuple[PixelZone, ...]


@dataclass(frozen=True)
class RecognitionEntry:
    crop_id: str
    layout_id: str
    role: str
    variant_id: str
    image: Any


def _box(
    width: int,
    height: int,
    left: float,
    right: float,
) -> tuple[int, int, int, int]:
    x1 = max(0, min(width - 1, int(round(left * width))))
    x2 = max(x1 + 1, min(width, int(round(right * width))))
    return (x1, 0, x2, height)


def fixed_zone_layouts(width: int, height: int) -> tuple[ZoneLayout, ...]:
    """Return bounded layout hypotheses without reading outside the plate crop."""

    if width < 60 or height < 20:
        raise ProtocolError("Crop plaque trop petit pour le fallback segmenté.")
    normalized = (
        ("legacy-wide", ((0.01, 0.58), (0.50, 0.82), (0.74, 0.99))),
        ("legacy-balanced", ((0.01, 0.51), (0.43, 0.77), (0.69, 0.99))),
        ("unified-2026", ((0.13, 0.61), (0.53, 0.85), (0.77, 0.99))),
    )
    layouts: list[ZoneLayout] = []
    for layout_id, ranges in normalized:
        layouts.append(
            ZoneLayout(
                layout_id,
                tuple(
                    PixelZone(role, _box(width, height, left, right))
                    for role, (left, right) in zip(ROLE_ORDER, ranges, strict=True)
                ),
            )
        )
    return tuple(layouts)


def detect_separator_layout(image: Any) -> ZoneLayout | None:
    """Find two tall divider candidates using deterministic OpenCV morphology."""

    import cv2

    if image is None or getattr(image, "ndim", 0) not in {2, 3}:
        raise ProtocolError("Image plaque invalide pour la détection de séparateurs.")
    height, width = image.shape[:2]
    if width < 60 or height < 20:
        return None
    gray = image if image.ndim == 2 else cv2.cvtColor(image, cv2.COLOR_BGR2GRAY)
    blurred = cv2.GaussianBlur(gray, (3, 3), 0)
    _, ink = cv2.threshold(
        blurred, 0, 255, cv2.THRESH_BINARY_INV + cv2.THRESH_OTSU
    )
    kernel = cv2.getStructuringElement(cv2.MORPH_RECT, (1, max(3, height // 2)))
    vertical = cv2.morphologyEx(ink, cv2.MORPH_OPEN, kernel)
    contours, _ = cv2.findContours(
        vertical, cv2.RETR_EXTERNAL, cv2.CHAIN_APPROX_SIMPLE
    )
    centers: list[float] = []
    for contour in contours:
        x, _y, box_width, box_height = cv2.boundingRect(contour)
        center = x + box_width / 2.0
        if (
            box_height >= 0.45 * height
            and box_width <= max(4, 0.05 * width)
            and 0.20 * width <= center <= 0.93 * width
        ):
            centers.append(center)
    centers = sorted(set(round(value, 2) for value in centers))
    pairs = [
        (left, right)
        for left in centers
        for right in centers
        if left < right and 0.08 * width <= right - left <= 0.38 * width
    ]
    if not pairs:
        return None
    left, right = min(
        pairs,
        key=lambda pair: (
            abs(pair[0] / width - 0.60) + abs(pair[1] / width - 0.80),
            pair,
        ),
    )
    padding = max(1, int(round(0.008 * width)))
    x1 = max(1, min(width - 3, int(round(left))))
    x2 = max(x1 + 2, min(width - 1, int(round(right))))
    zones = (
        PixelZone("serial", (0, 0, max(1, x1 - padding), height)),
        PixelZone("series", (min(width - 1, x1 + padding), 0, max(x1 + padding + 1, x2 - padding), height)),
        PixelZone("region", (min(width - 1, x2 + padding), 0, width, height)),
    )
    if any(zone.box[2] <= zone.box[0] for zone in zones):
        return None
    return ZoneLayout("detected-separators", zones)


def image_variants(image: Any) -> Mapping[str, Any]:
    """Build two conservative variants; neither creates synthetic glyphs."""

    import cv2

    if image is None or getattr(image, "ndim", 0) not in {2, 3}:
        raise ProtocolError("Crop plaque illisible.")
    color = image
    if image.ndim == 2:
        color = cv2.cvtColor(image, cv2.COLOR_GRAY2BGR)
    lab = cv2.cvtColor(color, cv2.COLOR_BGR2LAB)
    lightness, channel_a, channel_b = cv2.split(lab)
    enhanced = cv2.createCLAHE(clipLimit=2.0, tileGridSize=(8, 8)).apply(lightness)
    clahe = cv2.cvtColor(
        cv2.merge((enhanced, channel_a, channel_b)), cv2.COLOR_LAB2BGR
    )
    return {"original": color, "clahe": clahe}


def _crop_zone(image: Any, zone: PixelZone) -> Any:
    x1, y1, x2, y2 = zone.box
    crop = image[y1:y2, x1:x2]
    if getattr(crop, "size", 0) == 0:
        raise ProtocolError(f"Zone OCR vide pour {zone.role}.")
    return crop


def _recognize(
    recognizer: Any,
    entries: Sequence[RecognitionEntry],
    *,
    batch_size: int,
) -> list[tuple[str, OcrObservation]]:
    rows: list[tuple[str, OcrObservation]] = []
    for offset in range(0, len(entries), batch_size):
        batch = entries[offset : offset + batch_size]
        predictions = list(
            recognizer.predict(
                input=[entry.image for entry in batch], batch_size=len(batch)
            )
        )
        if len(predictions) != len(batch):
            raise ProtocolError("PP-OCRv5 n'a pas retourné un résultat par zone.")
        for entry, prediction in zip(batch, predictions, strict=True):
            raw_text, score = extract_ocr_result(prediction)
            score = float(score)
            if not math.isfinite(score) or not 0.0 <= score <= 1.0:
                raise ProtocolError("Score PP-OCRv5 non fini ou hors limites.")
            rows.append(
                (
                    entry.crop_id,
                    OcrObservation(
                        entry.layout_id,
                        entry.role,
                        entry.variant_id,
                        raw_text,
                        score,
                    ),
                )
            )
    return rows


def _full_entries(crop_id: str, variants: Mapping[str, Any]) -> list[RecognitionEntry]:
    return [
        RecognitionEntry(crop_id, "full", "full", variant_id, variants[variant_id])
        for variant_id in VARIANT_IDS
    ]


def _segmented_entries(
    crop_id: str,
    variants: Mapping[str, Any],
    layouts: Sequence[ZoneLayout],
) -> list[RecognitionEntry]:
    entries: list[RecognitionEntry] = []
    for layout in layouts:
        for variant_id in VARIANT_IDS:
            variant = variants[variant_id]
            for zone in layout.zones:
                entries.append(
                    RecognitionEntry(
                        crop_id,
                        layout.layout_id,
                        zone.role,
                        variant_id,
                        _crop_zone(variant, zone),
                    )
                )
    return entries


def _requires_segmented_fallback(suggestion: HybridSuggestion) -> bool:
    return suggestion.status != "complete_primary_suggestion"


def _supports_segmented_fallback(width: int, height: int) -> bool:
    """Return whether a crop is large enough for three bounded OCR zones.

    Tiny crops can still be sent through the full-crop recognizer.  They must
    not abort the complete review batch merely because a segmented fallback
    would create unusable zones.
    """

    return width >= 60 and height >= 20


def _environment(paddle: Any, paddleocr: Any, device: str) -> dict[str, Any]:
    return {
        "python": platform.python_version(),
        "paddle": paddle.__version__,
        "paddleocr": paddleocr.__version__,
        "paddle_cuda_compiled": bool(paddle.is_compiled_with_cuda()),
        "paddle_gpu_count": int(paddle.device.cuda.device_count()),
        "device": device,
        "isolated_process": True,
    }


def validate_hybrid_worker_payload(
    payload: Mapping[str, Any], expected_crop_ids: Sequence[str]
) -> Mapping[str, Mapping[str, Any]]:
    if payload.get("schema_version") != OUTPUT_SCHEMA_VERSION:
        raise ProtocolError("Version de sortie du worker hybride inattendue.")
    if payload.get("fallback_version") != HYBRID_FALLBACK_VERSION:
        raise ProtocolError("Version du fallback hybride inattendue.")
    if payload.get("model_name") != OCR_MODEL_NAME:
        raise ProtocolError("Modèle inattendu dans la sortie hybride.")
    rows = payload.get("results")
    if (
        payload.get("count") != len(expected_crop_ids)
        or not isinstance(rows, list)
        or len(rows) != len(expected_crop_ids)
    ):
        raise ProtocolError("Le worker hybride doit retourner exactement un résultat par crop.")
    safeguards = payload.get("safeguards")
    if (
        not isinstance(safeguards, Mapping)
        or safeguards.get("human_review_required") is not True
        or safeguards.get("automatic_vehicle_update_allowed") is not False
        or safeguards.get("operational_effect") != "NO_OPERATIONAL_ACTION"
        or safeguards.get("second_ocr_model_used") is not False
    ):
        raise ProtocolError("Les garde-fous du worker hybride sont invalides.")
    indexed: dict[str, Mapping[str, Any]] = {}
    for row in rows:
        if not isinstance(row, Mapping):
            raise ProtocolError("Résultat hybride invalide.")
        crop_id = str(row.get("crop_id", ""))
        if not crop_id or crop_id in indexed:
            raise ProtocolError("crop_id hybride absent ou dupliqué.")
        suggestion = row.get("suggestion")
        if not isinstance(suggestion, Mapping):
            raise ProtocolError("Suggestion hybride absente.")
        if suggestion.get("human_review_required") is not True:
            raise ProtocolError("La validation humaine doit rester obligatoire.")
        if suggestion.get("operational_effect") != "NO_OPERATIONAL_ACTION":
            raise ProtocolError("Un résultat ANPR ne peut pas produire d'action automatique.")
        indexed[crop_id] = row
    if set(indexed) != set(expected_crop_ids):
        raise ProtocolError("Les crop_id hybrides ne correspondent pas au manifeste.")
    return indexed


def run_worker(args: argparse.Namespace) -> dict[str, Any]:
    import cv2
    import paddle
    import paddleocr
    from paddleocr import TextRecognition

    if args.device not in ALLOWED_DEVICES:
        raise ProtocolError("Le device doit être 'cpu' ou 'gpu:0'.")
    if args.device == "gpu:0" and (
        not paddle.is_compiled_with_cuda() or paddle.device.cuda.device_count() < 1
    ):
        raise RuntimeError("GPU Paddle absent dans l'environnement OCR isolé.")

    crops, batch_size = _read_manifest(args.manifest, args.crop_root)
    output_path = Path(args.output).resolve()
    if output_path.exists():
        raise ProtocolError("Le fichier de sortie OCR hybride existe déjà.")

    started = time.perf_counter()
    recognizer = TextRecognition(model_name=OCR_MODEL_NAME, device=args.device)
    load_seconds = time.perf_counter() - started
    inference_seconds = 0.0
    results: list[dict[str, Any]] = []

    for offset in range(0, len(crops), batch_size):
        crop_batch = crops[offset : offset + batch_size]
        images: dict[str, Any] = {}
        variants_by_crop: dict[str, Mapping[str, Any]] = {}
        primary_entries: list[RecognitionEntry] = []
        for crop in crop_batch:
            crop_id = crop["crop_id"]
            image = cv2.imread(crop["image_path"], cv2.IMREAD_COLOR)
            if image is None:
                raise ProtocolError(f"Crop OCR illisible: {crop_id}.")
            images[crop_id] = image
            variants = image_variants(image)
            variants_by_crop[crop_id] = variants
            primary_entries.extend(_full_entries(crop_id, variants))

        tick = time.perf_counter()
        primary_rows = _recognize(
            recognizer, primary_entries, batch_size=batch_size
        )
        inference_seconds += time.perf_counter() - tick
        observations: dict[str, list[OcrObservation]] = defaultdict(list)
        for crop_id, item in primary_rows:
            observations[crop_id].append(item)

        fallback_entries: list[RecognitionEntry] = []
        fallback_crop_ids: set[str] = set()
        for crop in crop_batch:
            crop_id = crop["crop_id"]
            primary = build_hybrid_suggestion(observations[crop_id])
            if not _requires_segmented_fallback(primary):
                continue
            image = images[crop_id]
            height, width = image.shape[:2]
            if not _supports_segmented_fallback(width, height):
                continue
            layouts = list(fixed_zone_layouts(width, height))
            detected = detect_separator_layout(image)
            if detected is not None:
                layouts.insert(0, detected)
            fallback_entries.extend(
                _segmented_entries(crop_id, variants_by_crop[crop_id], layouts)
            )
            fallback_crop_ids.add(crop_id)

        if fallback_entries:
            tick = time.perf_counter()
            segmented_rows = _recognize(
                recognizer, fallback_entries, batch_size=batch_size
            )
            inference_seconds += time.perf_counter() - tick
            for crop_id, item in segmented_rows:
                observations[crop_id].append(item)

        for crop in crop_batch:
            crop_id = crop["crop_id"]
            suggestion = build_hybrid_suggestion(observations[crop_id])
            results.append(
                {
                    "crop_id": crop_id,
                    "fallback_executed": crop_id in fallback_crop_ids,
                    "suggestion": suggestion.as_dict(),
                    # Raw readings are retained only in this private output for
                    # audit and later error analysis; the CLI never prints them.
                    "observations": [
                        {
                            "layout_id": item.layout_id,
                            "role": item.role,
                            "variant_id": item.variant_id,
                            "raw_text": item.raw_text,
                            "score": item.score,
                        }
                        for item in observations[crop_id]
                    ],
                }
            )

    statuses = Counter(row["suggestion"]["status"] for row in results)
    payload = {
        "schema_version": OUTPUT_SCHEMA_VERSION,
        "fallback_version": HYBRID_FALLBACK_VERSION,
        "model_name": OCR_MODEL_NAME,
        "count": len(results),
        "results": results,
        "status_counts": dict(sorted(statuses.items())),
        "timings_seconds": {
            "ocr_load": load_seconds,
            "ocr_inference_total": inference_seconds,
        },
        "environment": _environment(paddle, paddleocr, args.device),
        "safeguards": {
            "human_review_required": True,
            "automatic_vehicle_update_allowed": False,
            "operational_effect": "NO_OPERATIONAL_ACTION",
            "second_ocr_model_used": False,
        },
    }
    validate_hybrid_worker_payload(payload, [crop["crop_id"] for crop in crops])
    output_path.write_text(
        json.dumps(payload, ensure_ascii=False, indent=2, sort_keys=True) + "\n",
        encoding="utf-8",
    )
    return payload


def build_parser() -> argparse.ArgumentParser:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--manifest", required=True)
    parser.add_argument("--crop-root", required=True)
    parser.add_argument("--output", required=True)
    parser.add_argument("--device", choices=sorted(ALLOWED_DEVICES), default="cpu")
    return parser


def main() -> int:
    payload = run_worker(build_parser().parse_args())
    # Registration text and component readings are deliberately excluded.
    print(
        json.dumps(
            {
                "status": "hybrid_ocr_complete",
                "count": payload["count"],
                "status_counts": payload["status_counts"],
                "environment": payload["environment"],
                "timings_seconds": payload["timings_seconds"],
            },
            ensure_ascii=False,
            sort_keys=True,
        )
    )
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
