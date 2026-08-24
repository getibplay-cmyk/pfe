"""Tests for private return-image re-encoding."""

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
    ROOT / "scripts" / "intelligence" / "vehicle_damage" / "sanitize_return_image.py"
)


class VehicleDamageImageSanitizerTest(unittest.TestCase):
    def test_return_photo_is_oriented_reencoded_and_stripped(self) -> None:
        with tempfile.TemporaryDirectory(prefix="rentfleet-damage-sanitize-") as temporary:
            source = Path(temporary) / "phone-return.jpg"
            output = Path(temporary) / "sanitized.jpg"
            exif = Image.Exif()
            exif[ExifTags.Base.Orientation] = 6
            exif[ExifTags.Base.ImageDescription] = "private-return-location"
            Image.new("RGB", (640, 480), color=(220, 10, 10)).save(
                source,
                format="JPEG",
                exif=exif,
            )

            result = self.run_sanitizer(source, output, "image/jpeg")

            self.assertEqual(0, result.returncode, result.stderr)
            manifest = json.loads(result.stdout)
            self.assertEqual("image/jpeg", manifest["mime"])
            self.assertEqual("jpg", manifest["extension"])
            self.assertEqual((480, 640), (manifest["width"], manifest["height"]))
            self.assertTrue(manifest["metadata_removed"])
            self.assertEqual(0o600, output.stat().st_mode & 0o777)
            self.assertNotIn(b"private-return-location", output.read_bytes())
            with Image.open(output) as sanitized:
                self.assertEqual("JPEG", sanitized.format)
                self.assertEqual(0, len(sanitized.getexif()))

    def test_mime_mismatch_fails_without_echoing_private_paths(self) -> None:
        with tempfile.TemporaryDirectory(prefix="rentfleet-damage-sanitize-") as temporary:
            source = Path(temporary) / "private-return.png"
            output = Path(temporary) / "sanitized.jpg"
            Image.new("RGB", (640, 480), color=(0, 0, 255)).save(source, format="PNG")

            result = self.run_sanitizer(source, output, "image/jpeg")

            self.assertEqual(2, result.returncode)
            self.assertEqual("", result.stdout)
            self.assertEqual(
                {"error": "DAMAGE_IMAGE_SANITIZATION_FAILED"},
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
                "--output-max-dimension",
                "2048",
            ],
            cwd=Path(tempfile.gettempdir()),
            capture_output=True,
            text=True,
            timeout=10,
            check=False,
        )


if __name__ == "__main__":
    unittest.main()
