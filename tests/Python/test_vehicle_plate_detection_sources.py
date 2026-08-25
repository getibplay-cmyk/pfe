from __future__ import annotations

import csv
import json
import random
import tempfile
import unittest
from pathlib import Path

from scripts.intelligence.vehicle_plate.detection_sources import (
    OPEN_IMAGES_PLATE_MID,
    _near_duplicate_groups,
    deterministic_development_split,
    parse_ccpd_filename,
    prepare_ccpd_detection_bundle,
    prepare_open_images_candidate_manifest,
)
from scripts.intelligence.vehicle_plate.protocol import ProtocolError


def ccpd_name(index: int) -> str:
    return (
        f"025-95_113-10&10_50&30-50&30_10&30_10&10_50&10-"
        f"0_0_22_27_27_33_{index % 34}-37-{index}.jpg"
    )


class DetectionSourceContractTest(unittest.TestCase):
    def test_ccpd_parser_reads_geometry_and_ignores_sequence(self):
        parsed = parse_ccpd_filename(ccpd_name(16))
        self.assertEqual((10, 10, 50, 30), (
            parsed.box.x_min,
            parsed.box.y_min,
            parsed.box.x_max,
            parsed.box.y_max,
        ))
        self.assertEqual(40, parsed.box.width)
        self.assertEqual(20, parsed.box.height)
        self.assertEqual(4, len(parsed.vertices))
        self.assertTrue(parsed.ignored_sequence_field)

        with self.assertRaisesRegex(ProtocolError, "7"):
            parse_ccpd_filename("bad-name.jpg")

    def test_development_split_is_stable_and_has_no_test_value(self):
        first = deterministic_development_split("scene-1", seed=20260825)
        second = deterministic_development_split("scene-1", seed=20260825)
        self.assertEqual(first, second)
        self.assertIn(first, {"train", "validation", "calibration"})

    def test_perceptual_duplicates_share_one_group(self):
        fingerprints = [
            ("1" * 64, 0x0000000000000000),
            ("2" * 64, 0x0000000000000003),
            ("3" * 64, 0xFFFFFFFFFFFFFFFF),
            ("1" * 64, 0x1234567890ABCDEF),
        ]
        groups, pairs = _near_duplicate_groups(
            fingerprints, maximum_hamming_distance=4
        )
        self.assertEqual(groups[0], groups[1])
        self.assertEqual(groups[0], groups[3])
        self.assertNotEqual(groups[0], groups[2])
        self.assertIn((0, 1, 2), pairs)

    def test_open_images_manifest_stays_disabled_pending_external_review(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            boxes = root / "boxes.csv"
            metadata = root / "metadata.csv"
            output = root / "candidates.csv"
            with boxes.open("w", encoding="utf-8", newline="") as handle:
                writer = csv.DictWriter(
                    handle,
                    fieldnames=(
                        "ImageID",
                        "Source",
                        "LabelName",
                        "Confidence",
                        "XMin",
                        "XMax",
                        "YMin",
                        "YMax",
                        "IsGroupOf",
                        "IsDepiction",
                        "IsInside",
                    ),
                )
                writer.writeheader()
                writer.writerows(
                    [
                        {
                            "ImageID": "a1",
                            "Source": "xclick",
                            "LabelName": OPEN_IMAGES_PLATE_MID,
                            "Confidence": "1",
                            "XMin": "0.1",
                            "XMax": "0.4",
                            "YMin": "0.2",
                            "YMax": "0.3",
                            "IsGroupOf": "0",
                            "IsDepiction": "0",
                            "IsInside": "0",
                        },
                        {
                            "ImageID": "machine-box",
                            "Source": "activemil",
                            "LabelName": OPEN_IMAGES_PLATE_MID,
                            "Confidence": "1",
                            "XMin": "0.1",
                            "XMax": "0.4",
                            "YMin": "0.2",
                            "YMax": "0.3",
                            "IsGroupOf": "0",
                            "IsDepiction": "0",
                            "IsInside": "0",
                        },
                    ]
                )
            with metadata.open("w", encoding="utf-8", newline="") as handle:
                writer = csv.DictWriter(
                    handle,
                    fieldnames=(
                        "ImageID",
                        "OriginalURL",
                        "OriginalLandingURL",
                        "License",
                        "AuthorProfileURL",
                        "Author",
                        "Title",
                    ),
                )
                writer.writeheader()
                writer.writerow(
                    {
                        "ImageID": "a1",
                        "OriginalURL": "https://images.example/a1.jpg",
                        "OriginalLandingURL": "https://example/a1",
                        "License": "https://creativecommons.org/licenses/by/2.0/",
                        "AuthorProfileURL": "https://example/author",
                        "Author": "Author A",
                        "Title": "Vehicle",
                    }
                )
            result = prepare_open_images_candidate_manifest(
                boxes_csv=boxes,
                image_metadata_csv=metadata,
                output_path=output,
                maximum_images=10,
            )
            self.assertEqual(1, result["rows"])
            self.assertEqual(0, result["images_downloaded"])
            self.assertFalse(result["training_enabled"])
            with output.open("r", encoding="utf-8", newline="") as handle:
                rows = list(csv.DictReader(handle))
            self.assertEqual("pending", rows[0]["external_landing_page_review"])
            self.assertEqual("false", rows[0]["image_download_allowed"])
            self.assertEqual("false", rows[0]["ocr_truth_available"])

    def test_ccpd_bundle_is_detection_only_group_safe_and_sealed(self):
        try:
            from PIL import Image
        except ImportError:
            self.skipTest("Pillow absent")

        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            source = root / "CCPD2019" / "ccpd_base"
            source.mkdir(parents=True)
            randomizer = random.Random(20260825)
            for index in range(96):
                image = Image.new("L", (80, 40), color=0)
                pixels = image.load()
                for y in range(40):
                    for x in range(80):
                        pixels[x, y] = randomizer.randrange(256)
                image.convert("RGB").save(source / ccpd_name(index), quality=92)

            license_file = root / "LICENSE"
            license_file.write_text(
                "MIT License\nCopyright (c) 2017 CCPD\n"
                "Permission is hereby granted, free of charge\n",
                encoding="utf-8",
            )
            output = root / "bundle"
            result = prepare_ccpd_detection_bundle(
                input_root=root / "CCPD2019",
                output_dir=output,
                license_path=license_file,
                maximum_per_partition=96,
                maximum_hamming_distance=0,
            )
            self.assertEqual(96, result.images)
            self.assertTrue((output / "SHA256SUMS").is_file())
            self.assertFalse((output / "annotations/instances_test.json").exists())
            report = json.loads(
                (output / "generation_report.json").read_text(encoding="utf-8")
            )
            self.assertFalse(report["safeguards"]["ccpd_sequence_field_parsed"])
            self.assertFalse(report["safeguards"]["ccpd_sequence_field_used_as_ocr_truth"])
            self.assertFalse(report["safeguards"]["contains_test_split"])
            self.assertEqual(
                {"calibration", "train", "validation"},
                set(report["artifacts"]["split_counts"]),
            )
            with (output / "manifest.csv").open(
                "r", encoding="utf-8", newline=""
            ) as handle:
                rows = list(csv.DictReader(handle))
            self.assertEqual(96, len(rows))
            self.assertTrue(all(row["task"] == "detection" for row in rows))
            self.assertTrue(all(row["ocr_truth_used"] == "false" for row in rows))
            self.assertNotIn("target", rows[0])
            group_splits: dict[str, set[str]] = {}
            for row in rows:
                group_splits.setdefault(row["group_id"], set()).add(row["split"])
            self.assertTrue(all(len(splits) == 1 for splits in group_splits.values()))


if __name__ == "__main__":
    unittest.main()
