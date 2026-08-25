from __future__ import annotations

import csv
import json
import tempfile
import unittest
from collections import defaultdict
from pathlib import Path

from scripts.intelligence.vehicle_plate.generate_synthetic_dataset import (
    DEFAULT_SERIES,
    FontProvenance,
    LatinFontProvenance,
    OFFICIAL_SERIES_MAPPING,
    _materialize_dataset,
    build_sample_plan,
    validate_ofl_license,
)
from scripts.intelligence.vehicle_plate.protocol import ProtocolError, validate_manifest


COUNTS = {"train": 2, "validation": 2, "calibration": 2}


class VehiclePlateSyntheticTest(unittest.TestCase):
    def test_plan_is_deterministic_unique_and_group_safe(self):
        first = build_sample_plan(
            seed=20260825,
            group_counts=COUNTS,
            variants_per_group=3,
            series=DEFAULT_SERIES,
        )
        second = build_sample_plan(
            seed=20260825,
            group_counts=COUNTS,
            variants_per_group=3,
            series=DEFAULT_SERIES,
        )
        self.assertEqual(first, second)
        self.assertEqual(18, len(first))
        self.assertNotIn("test", {sample.split for sample in first})

        grouped = defaultdict(list)
        for sample in first:
            grouped[sample.group_id].append(sample)
        self.assertEqual(6, len(grouped))
        self.assertEqual(6, len({samples[0].target for samples in grouped.values()}))
        self.assertEqual(
            {"legacy_arabic", "unified_2026_arabic_latin"},
            {sample.format_profile for sample in first},
        )
        for split in COUNTS:
            self.assertEqual(
                {"legacy_arabic", "unified_2026_arabic_latin"},
                {sample.format_profile for sample in first if sample.split == split},
            )
        for samples in grouped.values():
            self.assertEqual(3, len(samples))
            self.assertEqual(1, len({sample.split for sample in samples}))
            self.assertEqual(1, len({sample.target for sample in samples}))
            self.assertEqual(1, len({sample.recognition_text for sample in samples}))
            self.assertEqual(
                {"variant-00", "variant-01", "variant-02"},
                {sample.variant_id for sample in samples},
            )
            first_sample = samples[0]
            if first_sample.format_profile == "unified_2026_arabic_latin":
                arabic = first_sample.target.split("|")[1]
                self.assertEqual(
                    OFFICIAL_SERIES_MAPPING[arabic], first_sample.series_latin
                )
                self.assertTrue(first_sample.recognition_text.startswith("MA"))
            else:
                self.assertEqual("", first_sample.series_latin)
                self.assertFalse(first_sample.recognition_text.startswith("MA"))

    def test_seed_changes_targets_and_images(self):
        first = build_sample_plan(
            seed=1, group_counts=COUNTS, variants_per_group=1, series=DEFAULT_SERIES
        )
        second = build_sample_plan(
            seed=2, group_counts=COUNTS, variants_per_group=1, series=DEFAULT_SERIES
        )
        self.assertNotEqual(first, second)

    def test_plan_rejects_test_split_zero_count_and_non_arabic_series(self):
        with self.assertRaisesRegex(ProtocolError, "uniquement train"):
            build_sample_plan(
                seed=1,
                group_counts={**COUNTS, "test": 1},
                variants_per_group=1,
            )
        with self.assertRaisesRegex(ProtocolError, "au moins un groupe"):
            build_sample_plan(
                seed=1,
                group_counts={**COUNTS, "calibration": 0},
                variants_per_group=1,
            )
        with self.assertRaisesRegex(ProtocolError, "caractère arabe"):
            build_sample_plan(
                seed=1,
                group_counts=COUNTS,
                variants_per_group=1,
                series="A",
            )
        with self.assertRaisesRegex(ProtocolError, "au moins deux groupes"):
            build_sample_plan(
                seed=1,
                group_counts={"train": 1, "validation": 1, "calibration": 1},
                variants_per_group=1,
                unified_fraction=0.5,
            )

    def test_ofl_proof_is_explicit_and_utf8(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            valid = root / "OFL.txt"
            valid.write_text(
                "SIL OPEN FONT LICENSE Version 1.1 - 26 February 2007\n",
                encoding="utf-8",
            )
            self.assertTrue(validate_ofl_license(valid))

            invalid = root / "LICENSE.txt"
            invalid.write_text("License unknown\n", encoding="utf-8")
            with self.assertRaisesRegex(ProtocolError, "SIL OFL"):
                validate_ofl_license(invalid)

    def test_materialized_bundle_is_reproducible_and_paddle_compatible(self):
        samples = build_sample_plan(
            seed=20260825,
            group_counts={"train": 2, "validation": 2, "calibration": 2},
            variants_per_group=2,
            series=DEFAULT_SERIES,
        )
        provenance = FontProvenance(
            family="Noto Sans Arabic",
            style="Regular",
            font_sha256="1" * 64,
            license_sha256="2" * 64,
            source_url=(
                "https://github.com/notofonts/arabic/releases/tag/"
                "NotoSansArabic-v2.013"
            ),
            archive_url=(
                "https://github.com/notofonts/arabic/releases/download/"
                "NotoSansArabic-v2.013/NotoSansArabic-v2.013.zip"
            ),
            archive_sha256="3" * 64,
            pillow_version="test",
            freetype_version="test",
            raqm_available=True,
        )

        latin_provenance = LatinFontProvenance(
            "Noto Sans",
            "Regular",
            "4" * 64,
            "5" * 64,
            "https://raw.githubusercontent.com/google/fonts/commit/font.ttf",
            "https://raw.githubusercontent.com/google/fonts/commit/OFL.txt",
            "a" * 40,
        )

        def renderer(sample, destination, _font_path, _latin_font_path):
            destination.parent.mkdir(parents=True, exist_ok=True)
            destination.write_bytes(b"synthetic-png-fixture\0" + sample.sample_id.encode())

        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            font = root / "NotoSansArabic-Regular.ttf"
            latin_font = root / "NotoSans-Regular.ttf"
            license_file = root / "OFL.txt"
            latin_license_file = root / "OFL-NotoSans.txt"
            font.write_bytes(b"test-font")
            latin_font.write_bytes(b"test-latin-font")
            license_file.write_text("SIL OPEN FONT LICENSE Version 1.1\n", encoding="utf-8")
            latin_license_file.write_text(
                "SIL OPEN FONT LICENSE Version 1.1\n", encoding="utf-8"
            )
            results = []
            for name in ("run-a", "run-b"):
                results.append(
                    _materialize_dataset(
                        output_dir=root / name,
                        samples=samples,
                        series=DEFAULT_SERIES,
                        seed=20260825,
                        group_counts={"train": 2, "validation": 2, "calibration": 2},
                        variants_per_group=2,
                        font_path=font,
                        license_path=license_file,
                        provenance=provenance,
                        latin_font_path=latin_font,
                        latin_license_path=latin_license_file,
                        latin_provenance=latin_provenance,
                        renderer=renderer,
                    )
                )

            self.assertEqual(results[0].manifest_sha256, results[1].manifest_sha256)
            self.assertEqual(
                (root / "run-a/SHA256SUMS").read_text(encoding="utf-8"),
                (root / "run-b/SHA256SUMS").read_text(encoding="utf-8"),
            )
            with (root / "run-a/manifest.csv").open(
                "r", encoding="utf-8", newline=""
            ) as handle:
                rows = list(csv.DictReader(handle))
            report = validate_manifest(rows)
            self.assertEqual(12, report.rows)
            self.assertEqual(0, report.independent_test_rows)
            self.assertEqual(0, report.split_counts["test"])

            for split in ("train", "validation", "calibration"):
                labels = (root / f"run-a/labels/rec_gt_{split}.txt").read_text(
                    encoding="utf-8"
                ).splitlines()
                self.assertEqual(2, len(labels))
                for label in labels:
                    image_reference, recognition_text = label.split("\t")
                    paths = json.loads(image_reference)
                    self.assertEqual(2, len(paths))
                    self.assertTrue(
                        all(path.startswith(f"images/{split}/") for path in paths)
                    )
                    self.assertNotIn("|", recognition_text)

            generation_report = json.loads(
                (root / "run-a/generation_report.json").read_text(encoding="utf-8")
            )
            self.assertFalse(generation_report["qualification_claim"])
            self.assertFalse(generation_report["final_test_opened"])
            self.assertFalse(generation_report["contains_real_vehicle_data"])

    def test_existing_output_is_never_overwritten(self):
        samples = build_sample_plan(
            seed=1,
            group_counts={"train": 1, "validation": 1, "calibration": 1},
            variants_per_group=1,
            unified_fraction=0.0,
        )
        provenance = FontProvenance(
            "Noto Sans Arabic",
            "Regular",
            "1" * 64,
            "2" * 64,
            "https://github.com/notofonts/arabic/releases/tag/NotoSansArabic-v2.013",
            "https://github.com/notofonts/arabic/releases/download/NotoSansArabic-v2.013/NotoSansArabic-v2.013.zip",
            "3" * 64,
            "test",
            "test",
            True,
        )
        latin_provenance = LatinFontProvenance(
            "Noto Sans",
            "Regular",
            "4" * 64,
            "5" * 64,
            "https://raw.githubusercontent.com/google/fonts/commit/font.ttf",
            "https://raw.githubusercontent.com/google/fonts/commit/OFL.txt",
            "a" * 40,
        )
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            output = root / "existing"
            output.mkdir()
            font = root / "font.ttf"
            latin_font = root / "latin-font.ttf"
            license_file = root / "OFL.txt"
            latin_license_file = root / "OFL-Latin.txt"
            font.write_bytes(b"font")
            latin_font.write_bytes(b"latin-font")
            license_file.write_text("SIL OPEN FONT LICENSE Version 1.1\n", encoding="utf-8")
            latin_license_file.write_text(
                "SIL OPEN FONT LICENSE Version 1.1\n", encoding="utf-8"
            )
            with self.assertRaises(FileExistsError):
                _materialize_dataset(
                    output_dir=output,
                    samples=samples,
                    series=DEFAULT_SERIES,
                    seed=1,
                    group_counts={"train": 1, "validation": 1, "calibration": 1},
                    variants_per_group=1,
                    font_path=font,
                    license_path=license_file,
                    provenance=provenance,
                    latin_font_path=latin_font,
                    latin_license_path=latin_license_file,
                    latin_provenance=latin_provenance,
                    renderer=lambda *_: None,
                )


if __name__ == "__main__":
    unittest.main()
