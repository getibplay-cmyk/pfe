#!/usr/bin/env python3
"""Re-encode one private vehicle image without carrying source metadata."""

from __future__ import annotations

import argparse
import hashlib
import json
import os
import sys
import tempfile
import warnings
from pathlib import Path

from PIL import Image, ImageOps


SCHEMA_VERSION = "1.0.0"
MAX_INPUT_BYTES = 8_388_608
MAX_DIMENSION = 8_000
DEFAULT_OUTPUT_MAX_DIMENSION = 2_048
MAX_PIXELS = 64_000_000
FORMAT_BY_MIME = {
    "image/jpeg": ("JPEG", "jpg"),
    "image/png": ("PNG", "png"),
    "image/webp": ("WEBP", "webp"),
}
OUTPUT_FORMAT = "JPEG"
OUTPUT_MIME = "image/jpeg"
OUTPUT_EXTENSION = "jpg"
OUTPUT_QUALITIES = (92, 85, 75, 65, 50)
PRIVATE_METADATA_KEYS = {
    "comment",
    "exif",
    "icc_profile",
    "photoshop",
    "xmp",
}

Image.MAX_IMAGE_PIXELS = MAX_PIXELS
warnings.simplefilter("error", Image.DecompressionBombWarning)


class SanitizationContractError(RuntimeError):
    """Raised when an input or sanitized output violates the closed contract."""


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--input", type=Path, required=True)
    parser.add_argument("--output", type=Path, required=True)
    parser.add_argument("--expected-mime", choices=tuple(FORMAT_BY_MIME), required=True)
    parser.add_argument("--max-bytes", type=int, default=MAX_INPUT_BYTES)
    parser.add_argument("--max-dimension", type=int, default=MAX_DIMENSION)
    parser.add_argument(
        "--output-max-dimension",
        type=int,
        default=DEFAULT_OUTPUT_MAX_DIMENSION,
    )
    return parser.parse_args()


def require(condition: bool, message: str) -> None:
    if not condition:
        raise SanitizationContractError(message)


def file_sha256(path: Path) -> str:
    digest = hashlib.sha256()
    with path.open("rb") as stream:
        for block in iter(lambda: stream.read(1024 * 1024), b""):
            digest.update(block)
    return digest.hexdigest()


def flatten_to_rgb(image: Image.Image) -> Image.Image:
    if image.mode in {"RGBA", "LA"} or "transparency" in image.info:
        rgba = image.convert("RGBA")
        background = Image.new("RGB", rgba.size, color=(255, 255, 255))
        background.paste(rgba, mask=rgba.getchannel("A"))
        return background
    return image.convert("RGB")


def fit_for_private_storage(image: Image.Image, max_dimension: int) -> Image.Image:
    if max(image.size) <= max_dimension:
        return image
    fitted = image.copy()
    fitted.thumbnail(
        (max_dimension, max_dimension),
        resample=Image.Resampling.LANCZOS,
        reducing_gap=3.0,
    )
    return fitted


def save_reencoded(image: Image.Image, output: Path, max_bytes: int) -> tuple[int, int]:
    output_parent = output.parent
    require(output_parent.is_dir() and not output_parent.is_symlink(), "Output directory is unsafe.")
    require(not output.is_symlink(), "Output target is unsafe.")

    descriptor, temporary_name = tempfile.mkstemp(
        dir=output_parent,
        prefix=f".{output.name}.",
        suffix=".tmp",
    )
    temporary = Path(temporary_name)
    try:
        os.close(descriptor)
        os.chmod(temporary, 0o600)
        candidate = image
        while True:
            for quality in OUTPUT_QUALITIES:
                with temporary.open("wb") as stream:
                    candidate.save(
                        stream,
                        format=OUTPUT_FORMAT,
                        quality=quality,
                        optimize=True,
                        progressive=False,
                    )
                if 1 <= temporary.stat().st_size <= max_bytes:
                    os.replace(temporary, output)
                    os.chmod(output, 0o600)
                    return candidate.size
            longest_side = max(candidate.size)
            require(longest_side > 256, "Sanitized image cannot fit the private storage contract.")
            candidate = fit_for_private_storage(candidate, max(256, int(longest_side * 0.75)))
    finally:
        if temporary.exists():
            temporary.unlink()


def sanitize(
    source_path: Path,
    output_path: Path,
    expected_mime: str,
    max_bytes: int,
    max_dimension: int,
    output_max_dimension: int,
) -> dict[str, object]:
    require(1 <= max_bytes <= MAX_INPUT_BYTES, "Maximum byte size is invalid.")
    require(1 <= max_dimension <= MAX_DIMENSION, "Maximum dimension is invalid.")
    require(
        256 <= output_max_dimension <= 4_096,
        "Maximum sanitized dimension is invalid.",
    )
    require(source_path.is_file() and not source_path.is_symlink(), "Input image is missing or unsafe.")
    require(1 <= source_path.stat().st_size <= max_bytes, "Input image size is invalid.")

    expected_format, _ = FORMAT_BY_MIME[expected_mime]
    with Image.open(source_path) as probe:
        image_format = probe.format
        probe.verify()
    require(image_format == expected_format, "Detected image format does not match its MIME type.")

    with Image.open(source_path) as source:
        source.load()
        oriented = ImageOps.exif_transpose(source)
        require(
            1 <= oriented.width <= max_dimension and 1 <= oriented.height <= max_dimension,
            "Input image dimensions are invalid.",
        )
        require(oriented.width * oriented.height <= MAX_PIXELS, "Input image area is invalid.")
        sanitized = fit_for_private_storage(flatten_to_rgb(oriented), output_max_dimension)
        sanitized.info.clear()
        width, height = save_reencoded(sanitized, output_path, max_bytes)

    output_bytes = output_path.stat().st_size
    require(1 <= output_bytes <= max_bytes, "Sanitized image size is invalid.")
    with Image.open(output_path) as probe:
        require(probe.format == OUTPUT_FORMAT, "Sanitized image format is invalid.")
        probe.verify()
    with Image.open(output_path) as verified:
        require(len(verified.getexif()) == 0, "Sanitized image still contains EXIF data.")
        require(
            PRIVATE_METADATA_KEYS.isdisjoint(verified.info),
            "Sanitized image still contains private metadata.",
        )

    return {
        "schema_version": SCHEMA_VERSION,
        "source_mime": expected_mime,
        "mime": OUTPUT_MIME,
        "extension": OUTPUT_EXTENSION,
        "bytes": output_bytes,
        "sha256": file_sha256(output_path),
        "width": width,
        "height": height,
        "metadata_removed": True,
    }


def main() -> int:
    args = parse_args()
    manifest = sanitize(
        args.input,
        args.output,
        args.expected_mime,
        args.max_bytes,
        args.max_dimension,
        args.output_max_dimension,
    )
    sys.stdout.write(json.dumps(manifest, separators=(",", ":")) + "\n")
    return 0


if __name__ == "__main__":
    try:
        raise SystemExit(main())
    except (
        SanitizationContractError,
        OSError,
        ValueError,
        Image.DecompressionBombError,
        Image.DecompressionBombWarning,
    ):
        sys.stderr.write('{"error":"COLOR_IMAGE_SANITIZATION_FAILED"}\n')
        raise SystemExit(2)
    except Exception:
        sys.stderr.write('{"error":"COLOR_IMAGE_SANITIZER_UNAVAILABLE"}\n')
        raise SystemExit(3)
