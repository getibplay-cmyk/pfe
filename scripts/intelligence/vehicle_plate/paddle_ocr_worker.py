#!/usr/bin/env python3
"""Run PaddleOCR recognition in an isolated Python process.

The Colab system interpreter is reserved for the preinstalled PyTorch detector.
PaddlePaddle is installed in a dedicated virtual environment because both
frameworks pin different CUDA support packages on current Colab runtimes.
This worker exchanges only local, short-lived crop files and a JSON result.
"""

from __future__ import annotations

import argparse
import json
import math
import platform
import sys
import time
from pathlib import Path, PurePosixPath
from typing import Any, Mapping


REPOSITORY_ROOT = Path(__file__).resolve().parents[3]
if str(REPOSITORY_ROOT) not in sys.path:
    sys.path.insert(0, str(REPOSITORY_ROOT))

from scripts.intelligence.vehicle_plate.colab_smoke import (
    OCR_MODEL_NAME,
    extract_ocr_result,
)
from scripts.intelligence.vehicle_plate.protocol import ProtocolError


WORKER_SCHEMA_VERSION = "1.0.0"


def _read_manifest(path: str | Path, crop_root: str | Path) -> tuple[list[dict[str, str]], int]:
    payload = json.loads(Path(path).read_text(encoding="utf-8"))
    if not isinstance(payload, Mapping):
        raise ProtocolError("Manifeste OCR sans objet racine.")
    if payload.get("schema_version") != WORKER_SCHEMA_VERSION:
        raise ProtocolError("Version du manifeste OCR inattendue.")
    if payload.get("model_name") != OCR_MODEL_NAME:
        raise ProtocolError("Modèle OCR inattendu dans le manifeste.")
    try:
        batch_size = int(payload["batch_size"])
    except (KeyError, TypeError, ValueError) as error:
        raise ProtocolError("Taille de lot OCR absente ou invalide.") from error
    if not 1 <= batch_size <= 16:
        raise ProtocolError("Taille de lot OCR hors limites [1,16].")

    raw_crops = payload.get("crops")
    if not isinstance(raw_crops, list):
        raise ProtocolError("Le manifeste OCR doit contenir une liste de crops.")
    root = Path(crop_root).resolve()
    crops: list[dict[str, str]] = []
    identifiers: set[str] = set()
    for index, item in enumerate(raw_crops):
        if not isinstance(item, Mapping):
            raise ProtocolError(f"Crop OCR {index}: objet attendu.")
        crop_id = str(item.get("crop_id", ""))
        relative_text = str(item.get("image_path", ""))
        relative = PurePosixPath(relative_text)
        if not crop_id or crop_id in identifiers:
            raise ProtocolError(f"Crop OCR {index}: identifiant absent ou dupliqué.")
        if not relative_text or relative.is_absolute() or ".." in relative.parts:
            raise ProtocolError(f"Crop OCR {index}: chemin relatif invalide.")
        image_path = (root / Path(*relative.parts)).resolve()
        if root not in image_path.parents or not image_path.is_file():
            raise ProtocolError(f"Crop OCR {index}: fichier absent ou hors racine.")
        identifiers.add(crop_id)
        crops.append({"crop_id": crop_id, "image_path": str(image_path)})
    return crops, batch_size


def _environment(paddle: Any, paddleocr: Any) -> dict[str, Any]:
    return {
        "python": platform.python_version(),
        "paddle": paddle.__version__,
        "paddleocr": paddleocr.__version__,
        "paddle_cuda_compiled": bool(paddle.is_compiled_with_cuda()),
        "paddle_gpu_count": int(paddle.device.cuda.device_count()),
        "device": "gpu:0",
        "isolated_process": True,
    }


def run_worker(args: argparse.Namespace) -> dict[str, Any]:
    import cv2
    import paddle
    import paddleocr
    from paddleocr import TextRecognition

    if not paddle.is_compiled_with_cuda() or paddle.device.cuda.device_count() < 1:
        raise RuntimeError("GPU Paddle absent dans l'environnement OCR isolé.")
    crops, batch_size = _read_manifest(args.manifest, args.crop_root)
    output_path = Path(args.output).resolve()
    if output_path.exists():
        raise ProtocolError("Le fichier de sortie OCR existe déjà.")

    started = time.perf_counter()
    recognizer = TextRecognition(model_name=OCR_MODEL_NAME, device="gpu:0")
    load_seconds = time.perf_counter() - started
    inference_seconds = 0.0
    rows: list[dict[str, Any]] = []
    for offset in range(0, len(crops), batch_size):
        batch = crops[offset : offset + batch_size]
        images = []
        for crop in batch:
            image = cv2.imread(crop["image_path"], cv2.IMREAD_COLOR)
            if image is None:
                raise ProtocolError(f"Crop OCR illisible: {crop['crop_id']}.")
            images.append(image)
        tick = time.perf_counter()
        predictions = list(recognizer.predict(input=images, batch_size=len(images)))
        inference_seconds += time.perf_counter() - tick
        if len(predictions) != len(batch):
            raise ProtocolError("PaddleOCR n'a pas retourné un résultat par crop.")
        for crop, prediction in zip(batch, predictions, strict=True):
            raw_text, score = extract_ocr_result(prediction)
            if not math.isfinite(score):
                raise ProtocolError("Score OCR non fini.")
            rows.append(
                {"crop_id": crop["crop_id"], "raw_text": raw_text, "score": score}
            )

    payload = {
        "schema_version": WORKER_SCHEMA_VERSION,
        "model_name": OCR_MODEL_NAME,
        "count": len(rows),
        "results": rows,
        "timings_seconds": {
            "ocr_load": load_seconds,
            "ocr_inference_total": inference_seconds,
        },
        "environment": _environment(paddle, paddleocr),
    }
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
    return parser


def main() -> int:
    payload = run_worker(build_parser().parse_args())
    # Never print registration numbers or per-crop predictions to the console.
    print(
        json.dumps(
            {
                "status": "ocr_complete",
                "count": payload["count"],
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
