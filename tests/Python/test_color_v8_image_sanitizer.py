"""Contract tests for private vehicle-image re-encoding before SaaS storage."""

from __future__ import annotations

import json
import subprocess
import sys
import tempfile
import unittest
from pathlib import Path

from PIL import ExifTags, Image


ROOT = Path(__file__).resolve().parents[2]
SANITIZER_PATH = (
    ROOT / "scripts" / "intelligence" / "color_v8" / "sanitize_vehicle_image.py"
)


class ColorV8ImageSanitizerTest(unittest.TestCase):
    def test_jpeg_is_oriented_reencoded_and_stripped_of_exif_and_gps(self) -> None:
        with tempfile.TemporaryDirectory(prefix="rentfleet-color-v8-sanitize-") as temporary:
            source = Path(temporary) / "phone-photo.jpg"
            output = Path(temporary) / "sanitized.jpg"
            exif = Image.Exif()
            exif[ExifTags.Base.Orientation] = 6
            exif[ExifTags.Base.ImageDescription] = "private-customer-location"
            exif[ExifTags.IFD.GPSInfo] = {
                ExifTags.GPS.GPSLatitudeRef: "N",
                ExifTags.GPS.GPSLatitude: (33.0, 35.0, 0.0),
                ExifTags.GPS.GPSLongitudeRef: "W",
                ExifTags.GPS.GPSLongitude: (7.0, 36.0, 0.0),
            }
            Image.new("RGB", (40, 20), color=(220, 10, 10)).save(
                source,
                format="JPEG",
                exif=exif,
            )

            result = self.run_sanitizer(source, output, "image/jpeg")

            self.assertEqual(0, result.returncode, result.stderr)
            manifest = json.loads(result.stdout)
            self.assertEqual("image/jpeg", manifest["mime"])
            self.assertEqual("jpg", manifest["extension"])
            self.assertEqual((20, 40), (manifest["width"], manifest["height"]))
            self.assertTrue(manifest["metadata_removed"])
            self.assertEqual(output.stat().st_size, manifest["bytes"])
            self.assertEqual(64, len(manifest["sha256"]))
            self.assertEqual(0o600, output.stat().st_mode & 0o777)
            self.assertNotIn(b"private-customer-location", output.read_bytes())
            with Image.open(output) as sanitized:
                self.assertEqual("JPEG", sanitized.format)
                self.assertEqual((20, 40), sanitized.size)
                self.assertEqual(0, len(sanitized.getexif()))
                self.assertNotIn("exif", sanitized.info)
                self.assertNotIn("xmp", sanitized.info)
                self.assertNotIn("icc_profile", sanitized.info)

    def test_png_and_webp_are_reencoded_without_source_metadata(self) -> None:
        for image_format, mime, extension in (
            ("PNG", "image/png", "png"),
            ("WEBP", "image/webp", "webp"),
        ):
            with self.subTest(image_format=image_format), tempfile.TemporaryDirectory(
                prefix="rentfleet-color-v8-sanitize-"
            ) as temporary:
                source = Path(temporary) / f"source.{extension}"
                output = Path(temporary) / f"sanitized.{extension}"
                exif = Image.Exif()
                exif[ExifTags.Base.ImageDescription] = "private-source-metadata"
                Image.new("RGBA", (32, 16), color=(10, 40, 220, 128)).save(
                    source,
                    format=image_format,
                    exif=exif,
                )

                result = self.run_sanitizer(source, output, mime)

                self.assertEqual(0, result.returncode, result.stderr)
                manifest = json.loads(result.stdout)
                self.assertEqual(mime, manifest["mime"])
                self.assertEqual(extension, manifest["extension"])
                self.assertNotIn(b"private-source-metadata", output.read_bytes())
                with Image.open(output) as sanitized:
                    self.assertEqual(image_format, sanitized.format)
                    self.assertEqual("RGB", sanitized.mode)
                    self.assertEqual(0, len(sanitized.getexif()))

    def test_mime_mismatch_fails_without_echoing_private_paths(self) -> None:
        with tempfile.TemporaryDirectory(prefix="rentfleet-color-v8-sanitize-") as temporary:
            source = Path(temporary) / "private-customer-image.png"
            output = Path(temporary) / "sanitized.jpg"
            Image.new("RGB", (16, 16), color=(0, 0, 255)).save(source, format="PNG")

            result = self.run_sanitizer(source, output, "image/jpeg")

            self.assertEqual(2, result.returncode)
            self.assertEqual("", result.stdout)
            self.assertEqual(
                {"error": "COLOR_IMAGE_SANITIZATION_FAILED"},
                json.loads(result.stderr),
            )
            self.assertNotIn(str(source), result.stderr)
            self.assertNotIn(str(output), result.stderr)

    @staticmethod
    def run_sanitizer(source: Path, output: Path, mime: str) -> subprocess.CompletedProcess[str]:
        return subprocess.run(
            [
                sys.executable,
                str(SANITIZER_PATH),
                "--input",
                str(source),
                "--output",
                str(output),
                "--expected-mime",
                mime,
                "--max-bytes",
                "8388608",
                "--max-dimension",
                "8000",
            ],
            cwd=Path(tempfile.gettempdir()),
            capture_output=True,
            text=True,
            timeout=10,
            check=False,
        )


if __name__ == "__main__":
    unittest.main()
