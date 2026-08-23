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
MAX_PIXELS = 64_000_000
FORMAT_BY_MIME = {
    "image/jpeg": ("JPEG", "jpg"),
    "image/png": ("PNG", "png"),
    "image/webp": ("WEBP", "webp"),
}
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


def save_reencoded(image: Image.Image, image_format: str, output: Path) -> None:
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
        os.chmod(temporary, 0o600)
        with os.fdopen(descriptor, "wb") as stream:
            if image_format == "JPEG":
                image.save(stream, format="JPEG", quality=95, optimize=True, progressive=False)
            elif image_format == "PNG":
                image.save(stream, format="PNG", optimize=True)
            elif image_format == "WEBP":
                image.save(stream, format="WEBP", quality=95, method=4)
            else:
                raise SanitizationContractError("Output format is unsupported.")
        os.replace(temporary, output)
        os.chmod(output, 0o600)
    finally:
        if temporary.exists():
            temporary.unlink()


def sanitize(
    source_path: Path,
    output_path: Path,
    expected_mime: str,
    max_bytes: int,
    max_dimension: int,
) -> dict[str, object]:
    require(1 <= max_bytes <= MAX_INPUT_BYTES, "Maximum byte size is invalid.")
    require(1 <= max_dimension <= MAX_DIMENSION, "Maximum dimension is invalid.")
    require(source_path.is_file() and not source_path.is_symlink(), "Input image is missing or unsafe.")
    require(1 <= source_path.stat().st_size <= max_bytes, "Input image size is invalid.")

    expected_format, extension = FORMAT_BY_MIME[expected_mime]
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
        sanitized = flatten_to_rgb(oriented)
        sanitized.info.clear()
        width, height = sanitized.size
        save_reencoded(sanitized, expected_format, output_path)

    output_bytes = output_path.stat().st_size
    require(1 <= output_bytes <= max_bytes, "Sanitized image size is invalid.")
    with Image.open(output_path) as probe:
        require(probe.format == expected_format, "Sanitized image format is invalid.")
        probe.verify()
    with Image.open(output_path) as verified:
        require(len(verified.getexif()) == 0, "Sanitized image still contains EXIF data.")
        require(
            PRIVATE_METADATA_KEYS.isdisjoint(verified.info),
            "Sanitized image still contains private metadata.",
        )

    return {
        "schema_version": SCHEMA_VERSION,
        "mime": expected_mime,
        "extension": extension,
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
