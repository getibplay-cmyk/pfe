#!/usr/bin/env python3
"""Export the pinned RentFleet RT-DETRv2-S checkpoint with the official exporter."""

from __future__ import annotations

import argparse
import hashlib
import json
import os
import subprocess
import sys
import tempfile
from pathlib import Path


UPSTREAM_COMMIT = "068dfde65f2667ad6555883c69d73de886518cad"
CHECKPOINT_SHA256 = "3544b693d9014392b5a9a0d87e6951646455ed268ca1825ee5aa4fe07cd7b92e"
CHECKPOINT_BYTES = 80_772_267


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--upstream", required=True, type=Path, help="Checkout officiel RT-DETR épinglé.")
    parser.add_argument("--checkpoint", required=True, type=Path)
    parser.add_argument("--output", required=True, type=Path)
    return parser.parse_args()


def sha256(path: Path) -> str:
    digest = hashlib.sha256()
    with path.open("rb") as stream:
        for block in iter(lambda: stream.read(1024 * 1024), b""):
            digest.update(block)
    return digest.hexdigest()


def run(command: list[str], cwd: Path) -> str:
    result = subprocess.run(
        command,
        cwd=cwd,
        env={
            **os.environ,
            "PYTHONDONTWRITEBYTECODE": "1",
            "CUDA_VISIBLE_DEVICES": "",
        },
        capture_output=True,
        text=True,
        timeout=900,
        check=False,
    )
    if result.returncode != 0:
        detail = (result.stderr or result.stdout).strip().splitlines()
        suffix = f" Last message: {detail[-1][:240]}" if detail else ""
        raise RuntimeError(f"Pinned RT-DETR ONNX export failed.{suffix}")
    return result.stdout


def main() -> int:
    args = parse_args()
    upstream = args.upstream.resolve()
    checkpoint = args.checkpoint.resolve()
    output = args.output.resolve()
    pytorch_root = upstream / "rtdetrv2_pytorch"
    exporter = pytorch_root / "tools" / "export_onnx.py"
    base_config = pytorch_root / "configs" / "rtdetrv2" / "rtdetrv2_r18vd_120e_coco.yml"

    if not exporter.is_file() or not base_config.is_file() or not (upstream / ".git").exists():
        raise RuntimeError("The official RT-DETR checkout is incomplete.")
    commit = run(["git", "rev-parse", "HEAD"], upstream).strip()
    if commit != UPSTREAM_COMMIT:
        raise RuntimeError("The official RT-DETR checkout is not at the approved commit.")
    if run(["git", "status", "--porcelain", "--untracked-files=no"], upstream).strip():
        raise RuntimeError("The approved RT-DETR checkout contains tracked modifications.")
    if not checkpoint.is_file() or checkpoint.is_symlink():
        raise RuntimeError("The selected checkpoint is unavailable.")
    if checkpoint.stat().st_size != CHECKPOINT_BYTES or sha256(checkpoint) != CHECKPOINT_SHA256:
        raise RuntimeError("The selected checkpoint identity does not match the approved soup.")
    if output.exists() or output.is_symlink():
        raise RuntimeError("Refusing to overwrite an existing ONNX artifact.")
    output.parent.mkdir(mode=0o700, parents=True, exist_ok=True)

    with tempfile.TemporaryDirectory(prefix="rentfleet-rtdetr-export-") as directory:
        export_config = Path(directory) / "rentfleet_rtdetrv2_s_export.yml"
        export_config.write_text(
            "\n".join(
                [
                    f"__include__: ['{base_config.as_posix()}']",
                    "num_classes: 1",
                    "remap_mscoco_category: False",
                    "sync_bn: False",
                    "PResNet:",
                    "  depth: 18",
                    "  pretrained: False",
                    "",
                ]
            ),
            encoding="utf-8",
        )
        try:
            run(
                [
                    sys.executable,
                    str(exporter),
                    "--config",
                    str(export_config),
                    "--resume",
                    str(checkpoint),
                    "--output_file",
                    str(output),
                    "--input_size",
                    "640",
                    "--check",
                ],
                pytorch_root,
            )
        except Exception:
            output.unlink(missing_ok=True)
            raise

    if not output.is_file() or not 1_000_000 <= output.stat().st_size <= 536_870_912:
        output.unlink(missing_ok=True)
        raise RuntimeError("The exported ONNX artifact has an invalid size.")
    os.chmod(output, 0o600)
    print(
        json.dumps(
            {
                "checkpoint_sha256": CHECKPOINT_SHA256,
                "input_size": 640,
                "onnx_bytes": output.stat().st_size,
                "onnx_sha256": sha256(output),
                "outputs": ["labels", "boxes", "scores"],
                "upstream_commit": UPSTREAM_COMMIT,
            },
            sort_keys=True,
        )
    )
    return 0


if __name__ == "__main__":
    try:
        raise SystemExit(main())
    except (OSError, RuntimeError, subprocess.SubprocessError) as exception:
        sys.stderr.write(f"export failed: {exception}\n")
        raise SystemExit(2)
