#!/usr/bin/env python3
"""Run one finite-shape ONNX inference without interpreting it as qualification."""

from __future__ import annotations

import argparse
import json
from pathlib import Path

import numpy as np
import onnx
import onnxruntime as ort
from PIL import Image


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--model", type=Path, required=True)
    parser.add_argument("--image", type=Path, required=True)
    parser.add_argument("--report", type=Path, required=True)
    parser.add_argument("--provider", default="CUDAExecutionProvider")
    args = parser.parse_args()

    onnx.checker.check_model(onnx.load(args.model))
    available = ort.get_available_providers()
    provider = args.provider if args.provider in available else "CPUExecutionProvider"
    session = ort.InferenceSession(str(args.model), providers=[provider])
    with Image.open(args.image) as image:
        rgb = image.convert("RGB")
        original_width, original_height = rgb.size
        resized = rgb.resize((640, 640), Image.Resampling.BILINEAR)
        array = np.asarray(resized, dtype=np.float32) / 255.0
    tensor = np.transpose(array, (2, 0, 1))[None, ...]
    sizes = np.asarray([[original_width, original_height]], dtype=np.int64)
    outputs = session.run(
        None,
        {"images": tensor, "orig_target_sizes": sizes},
    )
    if len(outputs) != 3 or not all(np.isfinite(output).all() for output in outputs):
        raise ValueError("Sorties ONNX absentes ou non finies.")
    labels, boxes, scores = outputs
    if labels.shape[0] != 1 or boxes.shape[0] != 1 or scores.shape[0] != 1:
        raise ValueError("Batch ONNX inattendu.")

    report = {
        "kind": "pipeline_smoke_only",
        "provider": provider,
        "available_providers": available,
        "input_size": [original_width, original_height],
        "output_shapes": [list(output.shape) for output in outputs],
        "finite_outputs": True,
        "qualification": False,
        "test_split_read": False,
    }
    args.report.parent.mkdir(parents=True, exist_ok=True)
    args.report.write_text(
        json.dumps(report, ensure_ascii=False, indent=2, sort_keys=True) + "\n",
        encoding="utf-8",
    )
    print(json.dumps(report, ensure_ascii=False, sort_keys=True))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
