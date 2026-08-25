from __future__ import annotations

import json
import tempfile
import unittest
from pathlib import Path

from scripts.intelligence.vehicle_plate.character_detector import (
    CHARACTER_ALPHABET,
    CLASS_TO_ID,
    COUNTRY_MARKER_TOKEN,
    MODEL_NUM_CLASSES,
    CharacterDetection,
    load_source_registry,
    match_character_detections,
    reconstruct_moroccan_plate,
    require_admitted_source,
)
from scripts.intelligence.vehicle_plate.e2_character_detector import (
    DEFAULT_SOURCE_REGISTRY,
    audit_dataset_bundles,
)
from scripts.intelligence.vehicle_plate.generate_character_detection_dataset import (
    CharacterBox,
    _materialize_character_dataset,
)
from scripts.intelligence.vehicle_plate.generate_synthetic_dataset import (
    DEFAULT_SERIES,
    FontProvenance,
    LatinFontProvenance,
    build_sample_plan,
)
from scripts.intelligence.vehicle_plate.protocol import ProtocolError


def detection(label: str, x: float, y: float = 55, score: float = 0.9):
    return CharacterDetection(label, (x - 6, y - 15, x + 6, y + 15), score)


class CharacterDetectorContractTest(unittest.TestCase):
    def test_alphabet_has_digits_all_official_pairs_and_background(self):
        self.assertEqual(41, len(CHARACTER_ALPHABET))
        self.assertEqual(41, len(CLASS_TO_ID))
        self.assertEqual(42, MODEL_NUM_CLASSES)
        self.assertEqual(tuple(range(1, 42)), tuple(CLASS_TO_ID.values()))
        self.assertTrue(set("0123456789أبدهوطيكلمنصفرسABDHETYKLMNCFRS").issubset(CLASS_TO_ID))
        self.assertIn(COUNTRY_MARKER_TOKEN, CLASS_TO_ID)

    def test_reconstructs_unified_plate_and_checks_bilingual_pair(self):
        predictions = [detection(COUNTRY_MARKER_TOKEN, 30)]
        predictions.extend(detection(label, x) for label, x in zip("12345", range(105, 206, 25)))
        predictions.extend([detection("أ", 285, 35), detection("A", 285, 78)])
        predictions.extend([detection("1", 445), detection("2", 465)])
        reading = reconstruct_moroccan_plate(
            predictions, image_width=520, image_height=110
        )
        self.assertTrue(reading.accepted)
        self.assertEqual("12345|أ|12", reading.canonical)
        self.assertEqual("MA12345أA12", reading.recognition_text)
        self.assertEqual("unified_2026_arabic_latin", reading.format_profile)

        split_marker = [item for item in predictions if item.label != COUNTRY_MARKER_TOKEN]
        split_marker.extend([detection("M", 20), detection("A", 40)])
        rejected_marker = reconstruct_moroccan_plate(
            split_marker, image_width=520, image_height=110
        )
        self.assertFalse(rejected_marker.accepted)
        self.assertEqual(
            ("unified_ma_marker_missing_or_ambiguous",), rejected_marker.reasons
        )

        wrong = [item for item in predictions if not (item.label == "A" and item.center_x == 285)]
        wrong.append(detection("B", 285, 78))
        rejected = reconstruct_moroccan_plate(wrong, image_width=520, image_height=110)
        self.assertFalse(rejected.accepted)
        self.assertEqual(("arabic_latin_series_mismatch",), rejected.reasons)

    def test_reconstructs_legacy_plate_without_reading_unrelated_text(self):
        predictions = [
            detection("9", 90),
            detection("8", 115),
            detection("7", 140),
            detection("ب", 300),
            detection("7", 455),
        ]
        reading = reconstruct_moroccan_plate(
            predictions, image_width=520, image_height=110
        )
        self.assertTrue(reading.accepted)
        self.assertEqual("987|ب|7", reading.canonical)
        self.assertEqual("legacy_arabic", reading.format_profile)

        predictions.insert(3, detection("4", 225))
        rejected = reconstruct_moroccan_plate(
            predictions, image_width=520, image_height=110
        )
        self.assertFalse(rejected.accepted)
        self.assertEqual(("expected_exactly_two_digit_clusters",), rejected.reasons)

    def test_invalid_class_or_out_of_crop_box_is_refused(self):
        with self.assertRaisesRegex(ProtocolError, "inconnue"):
            reconstruct_moroccan_plate(
                [CharacterDetection("Z", (1, 1, 10, 10), 0.9)],
                image_width=100,
                image_height=30,
            )
        with self.assertRaisesRegex(ProtocolError, "hors du crop"):
            reconstruct_moroccan_plate(
                [CharacterDetection("1", (-1, 1, 10, 10), 0.9)],
                image_width=100,
                image_height=30,
            )

    def test_class_aware_iou_matching(self):
        targets = [
            CharacterDetection("1", (0, 0, 10, 20), 1.0),
            CharacterDetection("أ", (20, 0, 35, 20), 1.0),
        ]
        predictions = [
            CharacterDetection("1", (1, 0, 11, 20), 0.9),
            CharacterDetection("ب", (20, 0, 35, 20), 0.8),
            CharacterDetection("2", (50, 0, 60, 20), 0.7),
        ]
        counts = match_character_detections(predictions, targets)
        self.assertEqual(1, counts.true_positives)
        self.assertEqual(2, counts.false_positives)
        self.assertEqual(1, counts.false_negatives)
        self.assertAlmostEqual(1 / 3, counts.precision)
        self.assertAlmostEqual(1 / 2, counts.recall)

    def test_registry_admits_only_task_specific_audited_sources(self):
        registry = load_source_registry(DEFAULT_SOURCE_REGISTRY)
        admitted = require_admitted_source(
            registry,
            source_id="synthetic_moroccan_plate_ofl_v2",
            task="character_detection",
        )
        self.assertEqual("used_by_e22_synthetic", admitted["public_status"])
        with self.assertRaisesRegex(ProtocolError, "non activée"):
            require_admitted_source(
                registry,
                source_id="roboflow_smarttechinnov_read_plate_v2",
                task="character_detection",
            )
        with self.assertRaisesRegex(ProtocolError, "non activée"):
            require_admitted_source(
                registry,
                source_id="ccpd_official_mit",
                task="character_detection",
            )

    def test_synthetic_coco_bundle_is_auditable_and_has_no_test(self):
        samples = build_sample_plan(
            seed=7,
            group_counts={"train": 1, "validation": 1, "calibration": 1},
            variants_per_group=1,
            series=DEFAULT_SERIES,
            unified_fraction=0.0,
        )
        font_provenance = FontProvenance(
            "Noto Sans Arabic",
            "Regular",
            "1" * 64,
            "2" * 64,
            "https://github.com/notofonts/arabic/releases/tag/NotoSansArabic-v2.013",
            "https://github.com/notofonts/arabic/releases/download/a.zip",
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

        def renderer(sample, destination, _font, _latin_font):
            destination.parent.mkdir(parents=True, exist_ok=True)
            destination.write_bytes(b"png-fixture\0" + sample.sample_id.encode("ascii"))

        def annotator(sample, _font, _latin_font):
            series = sample.target.split("|")[1]
            return (
                CharacterBox("1", (10, 10, 20, 40), "serial", 0),
                CharacterBox(series, (30, 10, 45, 40), "series_arabic", 1),
                CharacterBox("2", (60, 10, 70, 40), "region", 2),
            )

        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            font = root / "arabic.ttf"
            latin_font = root / "latin.ttf"
            license_file = root / "OFL.txt"
            latin_license = root / "OFL-Latin.txt"
            font.write_bytes(b"font")
            latin_font.write_bytes(b"latin")
            license_file.write_text("SIL OPEN FONT LICENSE Version 1.1\n", encoding="utf-8")
            latin_license.write_text("SIL OPEN FONT LICENSE Version 1.1\n", encoding="utf-8")
            output = root / "bundle"
            result = _materialize_character_dataset(
                output_dir=output,
                samples=samples,
                series=DEFAULT_SERIES,
                seed=7,
                group_counts={"train": 1, "validation": 1, "calibration": 1},
                variants_per_group=1,
                font_path=font,
                license_path=license_file,
                provenance=font_provenance,
                latin_font_path=latin_font,
                latin_license_path=latin_license,
                latin_provenance=latin_provenance,
                renderer=renderer,
                annotator=annotator,
            )
            self.assertEqual(3, result.images)
            self.assertEqual(9, result.characters)
            self.assertFalse((output / "annotations/instances_test.json").exists())
            document = json.loads(
                (output / "annotations/instances_train.json").read_text(encoding="utf-8")
            )
            self.assertEqual(41, len(document["categories"]))
            self.assertEqual(3, len(document["annotations"]))
            audit = audit_dataset_bundles(
                [output], source_registry_path=DEFAULT_SOURCE_REGISTRY
            )
            self.assertEqual(3, audit["rows"])
            self.assertEqual(0, int(audit["final_test_opened"]))


if __name__ == "__main__":
    unittest.main()
