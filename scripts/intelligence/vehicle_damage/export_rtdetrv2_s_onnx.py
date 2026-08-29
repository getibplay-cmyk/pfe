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
CHECKPOINT_FILENAME = "selected_checkpoint_soup_19_24_29_inference_only.pth"
ATTESTATION_SCHEMA_VERSION = "1.0.0"
EXPORTER_ID = "rentfleet_rtdetrv2_s_onnx_export"
UPSTREAM_REPOSITORY = "https://github.com/lyuwenyu/RT-DETR"


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--upstream", required=True, type=Path, help="Checkout officiel RT-DETR épinglé.")
    parser.add_argument("--checkpoint", required=True, type=Path)
    parser.add_argument("--output", required=True, type=Path)
    parser.add_argument(
        "--attestation",
        type=Path,
        help="Reçu JSON privé; par défaut export_attestation.json à côté de l'ONNX.",
    )
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
            # PyTorch 2.6+ defaults torch.load() to weights_only=True. The
            # selected checkpoint is trusted only after the byte count and
            # SHA-256 checks below, so the pinned exporter may use its legacy
            # checkpoint loader without weakening the artifact boundary.
            "TORCH_FORCE_NO_WEIGHTS_ONLY_LOAD": "1",
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


def materialize_single_file_onnx(raw_output: Path, output: Path) -> None:
    """Load any exporter sidecar data and atomically write one closed ONNX file."""
    try:
        import onnx

        model = onnx.load(str(raw_output), load_external_data=True)
        onnx.checker.check_model(model)
    except Exception as exception:
        raise RuntimeError("The raw ONNX export is invalid.") from exception

    descriptor, temporary_name = tempfile.mkstemp(
        prefix=f".{output.stem}-",
        suffix=".onnx",
        dir=output.parent,
    )
    os.close(descriptor)
    temporary = Path(temporary_name)
    try:
        onnx.save_model(model, str(temporary), save_as_external_data=False)
        closed_model = onnx.load(str(temporary), load_external_data=False)
        onnx.checker.check_model(closed_model)
        if any(initializer.external_data for initializer in closed_model.graph.initializer):
            raise RuntimeError("The consolidated ONNX artifact still uses external data.")
        os.chmod(temporary, 0o600)
        os.replace(temporary, output)
    except Exception as exception:
        temporary.unlink(missing_ok=True)
        if isinstance(exception, RuntimeError):
            raise
        raise RuntimeError("Single-file ONNX consolidation failed.") from exception


def export_attestation(onnx_path: Path) -> dict[str, object]:
    """Return a path-free receipt that binds this ONNX to the approved export inputs."""
    return {
        "schema_version": ATTESTATION_SCHEMA_VERSION,
        "exporter": EXPORTER_ID,
        "exporter_source_sha256": sha256(Path(__file__).resolve()),
        "official_upstream": {
            "repository": UPSTREAM_REPOSITORY,
            "commit": UPSTREAM_COMMIT,
        },
        "checkpoint": {
            "filename": CHECKPOINT_FILENAME,
            "bytes": CHECKPOINT_BYTES,
            "sha256": CHECKPOINT_SHA256,
        },
        "onnx": {
            "filename": onnx_path.name,
            "bytes": onnx_path.stat().st_size,
            "sha256": sha256(onnx_path),
        },
        "export_contract": {
            "input_size": 640,
            "outputs": ["labels", "boxes", "scores"],
            "external_data": False,
        },
    }


def write_json_atomically(path: Path, value: dict[str, object]) -> None:
    descriptor, temporary_name = tempfile.mkstemp(
        prefix=f".{path.stem}-",
        suffix=".json",
        dir=path.parent,
    )
    temporary = Path(temporary_name)
    try:
        with os.fdopen(descriptor, "w", encoding="utf-8") as stream:
            json.dump(value, stream, ensure_ascii=False, indent=2, sort_keys=True)
            stream.write("\n")
            stream.flush()
            os.fsync(stream.fileno())
        os.chmod(temporary, 0o600)
        os.replace(temporary, path)
    except Exception:
        temporary.unlink(missing_ok=True)
        raise


def main() -> int:
    args = parse_args()
    upstream = args.upstream.resolve()
    checkpoint = args.checkpoint.resolve()
    output = args.output.resolve()
    attestation_path = (
        args.attestation.resolve()
        if args.attestation is not None
        else output.with_name("export_attestation.json")
    )
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
    if output == attestation_path:
        raise RuntimeError("The ONNX and attestation paths must differ.")
    if (
        output.exists()
        or output.is_symlink()
        or attestation_path.exists()
        or attestation_path.is_symlink()
    ):
        raise RuntimeError("Refusing to overwrite an existing export artifact.")
    output.parent.mkdir(mode=0o700, parents=True, exist_ok=True)
    attestation_path.parent.mkdir(mode=0o700, parents=True, exist_ok=True)

    with tempfile.TemporaryDirectory(prefix="rentfleet-rtdetr-export-") as directory:
        export_config = Path(directory) / "rentfleet_rtdetrv2_s_export.yml"
        raw_output = Path(directory) / "raw_model.onnx"
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
                    str(raw_output),
                    "--input_size",
                    "640",
                    "--check",
                ],
                pytorch_root,
            )
            materialize_single_file_onnx(raw_output, output)
        except Exception:
            output.unlink(missing_ok=True)
            attestation_path.unlink(missing_ok=True)
            raise

    try:
        if not output.is_file() or not 1_000_000 <= output.stat().st_size <= 536_870_912:
            raise RuntimeError("The exported ONNX artifact has an invalid size.")
        os.chmod(output, 0o600)
        receipt = export_attestation(output)
        write_json_atomically(attestation_path, receipt)
    except Exception:
        output.unlink(missing_ok=True)
        attestation_path.unlink(missing_ok=True)
        raise

    print(
        json.dumps(
            {
                "attestation_sha256": sha256(attestation_path),
                "checkpoint_sha256": CHECKPOINT_SHA256,
                "input_size": 640,
                "onnx_bytes": output.stat().st_size,
                "onnx_sha256": receipt["onnx"]["sha256"],
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
