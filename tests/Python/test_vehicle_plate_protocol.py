from __future__ import annotations

import tempfile
import unittest
from collections import Counter
from pathlib import Path

from scripts.intelligence.vehicle_plate.protocol import (
    ProtocolError,
    ReadingCandidate,
    character_error_rate,
    evaluate_release_gate,
    exact_match_accuracy,
    file_sha256,
    grouped_bootstrap_indices,
    normalize_ocr_text,
    parse_plate_text,
    select_consensus,
    validate_manifest,
    verify_manifest_files,
    write_test_lock,
)


SERIES_MAPPING = {"أ": "A", "ب": "B", "د": "D"}


class VehiclePlateProtocolTest(unittest.TestCase):
    def valid_rows(self):
        rows = []
        index = 1
        for split in ("train", "validation", "calibration", "test"):
            rows.append(
                {
                    "sample_id": f"sample-{index}",
                    "image_path": f"images/{split}-{index}.png",
                    "group_id": f"vehicle-{index}",
                    "task": "recognition",
                    "target": f"1234|أ|{index}",
                    "source_id": "synthetic_moroccan_plate_ofl_v2",
                    "source_url": "https://github.com/notofonts/arabic",
                    "license_id": "SYNTHETIC-OFL-1.1",
                    "license_status": "approved",
                    "license_proof": "licences/Noto-Arabic-OFL-1.1.txt",
                    "sha256": f"{index:064x}",
                    "split": split,
                    "holdout_role": "development",
                }
            )
            index += 1
        return rows

    def test_normalizes_arabic_digits_direction_marks_and_alef(self):
        self.assertEqual("12345 أ 7", normalize_ocr_text("١٢٣٤٥ \u200fا ٧"))
        self.assertEqual("123", normalize_ocr_text("۱۲۳"))

    def test_parses_legacy_arabic_plate(self):
        parsed = parse_plate_text("12345 | أ | 7")
        self.assertTrue(parsed.valid)
        self.assertEqual("12345|أ|7", parsed.canonical)
        self.assertEqual("legacy_arabic", parsed.format_version)

    def test_parses_reversed_visual_order(self):
        parsed = parse_plate_text("7 أ 12345")
        self.assertTrue(parsed.valid)
        self.assertEqual("12345|أ|7", parsed.canonical)

    def test_parses_and_verifies_2026_bilingual_plate(self):
        parsed = parse_plate_text(
            "MA 12345 أ A 7",
            bilingual_mapping=SERIES_MAPPING,
            require_verified_bilingual=True,
        )
        self.assertTrue(parsed.valid)
        self.assertEqual("unified_2026", parsed.format_version)
        self.assertEqual("verified", parsed.bilingual_consistency)
        self.assertEqual("12345|أ|7", parsed.canonical)

    def test_rejects_mismatched_2026_bilingual_plate(self):
        parsed = parse_plate_text(
            "MA 12345 أ B 7",
            bilingual_mapping=SERIES_MAPPING,
            require_verified_bilingual=True,
        )
        self.assertFalse(parsed.valid)
        self.assertIn("bilingual_series_mismatch", parsed.reasons)

    def test_rejects_unknown_bilingual_mapping_when_strict(self):
        parsed = parse_plate_text(
            "MA 12345 د D 7",
            bilingual_mapping={"أ": "A"},
            require_verified_bilingual=True,
        )
        self.assertFalse(parsed.valid)
        self.assertIn("bilingual_series_not_verified", parsed.reasons)

    def test_strict_mode_rejects_partial_new_plate_with_ma_marker(self):
        parsed = parse_plate_text(
            "MA 12345 A 7",
            bilingual_mapping=SERIES_MAPPING,
            require_verified_bilingual=True,
        )
        self.assertFalse(parsed.valid)
        self.assertIn("ma_marker_requires_verified_bilingual_series", parsed.reasons)

    def test_multi_view_consensus_accepts_same_plate(self):
        result = select_consensus(
            [
                ReadingCandidate("front", "12345 أ 7", 0.98, 0.97),
                ReadingCandidate("closeup", "١٢٣٤٥ ا ٧", 0.96, 0.95),
            ],
            bilingual_mapping=SERIES_MAPPING,
        )
        self.assertTrue(result.accepted)
        self.assertEqual("12345|أ|7", result.canonical)
        self.assertEqual("multi_view_consensus", result.reason)

    def test_consensus_abstains_on_conflicting_readings(self):
        result = select_consensus(
            [
                ReadingCandidate("front", "12345 أ 7", 0.95, 0.95),
                ReadingCandidate("closeup", "12346 أ 7", 0.95, 0.95),
            ],
            bilingual_mapping=SERIES_MAPPING,
        )
        self.assertFalse(result.accepted)
        self.assertEqual("ambiguous_consensus", result.reason)

    def test_consensus_rejects_low_quality_candidate(self):
        result = select_consensus(
            [ReadingCandidate("front", "12345 أ 7", 0.99, 0.99, quality_passed=False)],
            bilingual_mapping=SERIES_MAPPING,
        )
        self.assertFalse(result.accepted)
        self.assertEqual("no_eligible_reading", result.reason)

    def test_preprocessing_variants_do_not_manufacture_two_views(self):
        result = select_consensus(
            [
                ReadingCandidate(
                    "photo-1", "12345 أ 7", 0.94, 0.95, variant_id="padding-3"
                ),
                ReadingCandidate(
                    "photo-1", "12345 أ 7", 0.96, 0.95, variant_id="rectified"
                ),
            ],
            bilingual_mapping=SERIES_MAPPING,
            single_view_confidence=0.99,
        )
        self.assertFalse(result.accepted)
        self.assertEqual(("photo-1",), result.supporting_views)
        self.assertEqual("insufficient_view_support", result.reason)

    def test_ocr_metrics_are_sequence_level(self):
        self.assertEqual(0.5, exact_match_accuracy(["123|أ|7", "999|ب|8"], ["123|أ|7", "998|ب|8"]))
        self.assertAlmostEqual(1 / 14, character_error_rate(["123|أ|7", "999|ب|8"], ["123|أ|7", "998|ب|8"]))

    def test_release_gate_passes_at_preregistered_limits(self):
        gate = evaluate_release_gate(
            {
                "detection_map50": 0.95,
                "detection_recall": 0.95,
                "ocr_full_plate_exact": 0.90,
                "ocr_cer": 0.02,
                "selective_exact": 0.97,
                "selective_coverage": 0.70,
                "end_to_end_exact": 0.95,
            },
            independent_test=True,
            evaluation_count=1,
        )
        self.assertTrue(gate.passed)
        self.assertTrue(gate.stretch_95_passed)

    def test_release_gate_rejects_reused_or_development_test(self):
        metrics = {
            "detection_map50": 0.99,
            "detection_recall": 0.99,
            "ocr_full_plate_exact": 0.99,
            "ocr_cer": 0.0,
            "selective_exact": 0.99,
            "selective_coverage": 0.90,
            "end_to_end_exact": 0.99,
        }
        gate = evaluate_release_gate(metrics, independent_test=False, evaluation_count=2)
        self.assertFalse(gate.passed)
        self.assertEqual(2, len(gate.reasons))

    def test_release_gate_requires_end_to_end_floor(self):
        gate = evaluate_release_gate(
            {
                "detection_map50": 0.99,
                "detection_recall": 0.99,
                "ocr_full_plate_exact": 0.95,
                "ocr_cer": 0.01,
                "selective_exact": 0.99,
                "selective_coverage": 0.80,
                "end_to_end_exact": 0.89,
            },
            independent_test=True,
            evaluation_count=1,
        )
        self.assertFalse(gate.passed)
        self.assertIn("end_to_end_exact=0.890000 < 0.900000.", gate.reasons)

    def test_manifest_accepts_admitted_source_and_group_split(self):
        report = validate_manifest(self.valid_rows())
        self.assertEqual(4, report.rows)
        self.assertEqual(4, report.task_counts["recognition"])

    def test_manifest_rejects_um6p_without_exact_licence(self):
        rows = self.valid_rows()
        rows[0]["source_id"] = "um6p_moroccan_705"
        with self.assertRaisesRegex(ProtocolError, "source non admise"):
            validate_manifest(rows)

    def test_manifest_rejects_detection_source_as_ocr_truth(self):
        rows = self.valid_rows()
        rows[0].update(
            {
                "source_id": "moroccan_vehicle_registration_plates_cc0_v2",
                "license_id": "CC0-1.0",
            }
        )
        with self.assertRaisesRegex(ProtocolError, "n'est pas annotée"):
            validate_manifest(rows)

    def test_manifest_rejects_group_leakage(self):
        rows = self.valid_rows()
        rows[-1]["group_id"] = rows[0]["group_id"]
        with self.assertRaisesRegex(ProtocolError, "fuite de groupe"):
            validate_manifest(rows)

    def test_manifest_rejects_exact_duplicate(self):
        rows = self.valid_rows()
        rows[-1]["sha256"] = rows[0]["sha256"]
        with self.assertRaisesRegex(ProtocolError, "doublon exact"):
            validate_manifest(rows)

    def test_manifest_rejects_independent_source_used_for_development(self):
        rows = self.valid_rows()
        rows[-1]["holdout_role"] = "independent"
        with self.assertRaisesRegex(ProtocolError, "source indépendante"):
            validate_manifest(rows)

    def test_manifest_verifies_private_file_hashes_and_licence_proof(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            image = root / "data/images/sample.png"
            proof = root / "licences/Noto-Arabic-OFL-1.1.txt"
            image.parent.mkdir(parents=True)
            proof.parent.mkdir(parents=True)
            image.write_bytes(b"synthetic-image")
            proof.write_text("OFL-1.1\n", encoding="utf-8")
            row = {
                "image_path": "images/sample.png",
                "sha256": file_sha256(image),
                "license_proof": "Noto-Arabic-OFL-1.1.txt",
            }
            verify_manifest_files([row], root / "data", root / "licences")
            image.write_bytes(b"tampered")
            with self.assertRaisesRegex(ProtocolError, "Empreinte image différente"):
                verify_manifest_files([row], root / "data", root / "licences")

    def test_grouped_bootstrap_keeps_vehicle_views_together(self):
        groups = ["vehicle-a", "vehicle-a", "vehicle-b", "vehicle-c", "vehicle-c"]
        samples = list(grouped_bootstrap_indices(groups, iterations=10, seed=20260825))
        for sample in samples:
            counts = Counter(sample)
            self.assertEqual(counts[0], counts[1])
            self.assertEqual(counts[3], counts[4])

    def test_independent_test_lock_is_one_shot(self):
        with tempfile.TemporaryDirectory() as directory:
            lock = Path(directory) / "TEST_EVALUATION_LOCK.json"
            write_test_lock(lock, manifest_sha256="a" * 64)
            with self.assertRaisesRegex(ProtocolError, "déjà été évalué"):
                write_test_lock(lock, manifest_sha256="a" * 64)


if __name__ == "__main__":
    unittest.main()
