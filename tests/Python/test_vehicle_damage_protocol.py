from __future__ import annotations

import unittest

from scripts.intelligence.vehicle_damage.protocol import (
    ProtocolError,
    evaluate_release_gate,
    validate_manifest,
)


class VehicleDamageProtocolTest(unittest.TestCase):
    def valid_rows(self):
        rows = []
        index = 1
        for split in ("train", "validation", "calibration", "test"):
            for label in ("0", "1"):
                rows.append(
                    {
                        "image_path": f"images/{split}-{label}.jpg",
                        "label": label,
                        "group_id": f"vehicle-{index}",
                        "source_id": "hitl_car_parts_damage",
                        "source_url": "https://humansintheloop.org/resources/datasets/car-parts-and-car-damages-dataset/",
                        "license_id": "CC0-1.0",
                        "license_status": "approved",
                        "license_proof": "licences/hitl-access-receipt.pdf",
                        "sha256": f"{index:064x}",
                        "split": split,
                    }
                )
                index += 1
        return rows

    def test_accepts_official_licensed_group_disjoint_manifest(self):
        report = validate_manifest(self.valid_rows())
        self.assertEqual(8, report.rows)
        self.assertEqual({"0": 4, "1": 4}, dict(report.label_counts))
        self.assertEqual(2, report.split_counts["test"])

    def test_rejects_unapproved_licence(self):
        rows = self.valid_rows()
        rows[0]["license_status"] = "pending"
        with self.assertRaisesRegex(ProtocolError, "licence non approuvée"):
            validate_manifest(rows)

    def test_rejects_unofficial_mirror(self):
        rows = self.valid_rows()
        rows[0]["source_url"] = "https://example.test/hitl-mirror.zip"
        with self.assertRaisesRegex(ProtocolError, "URL non officielle"):
            validate_manifest(rows)

    def test_rejects_exact_duplicate(self):
        rows = self.valid_rows()
        rows[1]["sha256"] = rows[0]["sha256"]
        with self.assertRaisesRegex(ProtocolError, "doublon exact"):
            validate_manifest(rows)

    def test_rejects_group_leakage(self):
        rows = self.valid_rows()
        rows[-1]["group_id"] = rows[0]["group_id"]
        with self.assertRaisesRegex(ProtocolError, "fuite de groupe"):
            validate_manifest(rows)

    def test_rejects_one_class_split(self):
        rows = [row for row in self.valid_rows() if not (row["split"] == "test" and row["label"] == "0")]
        with self.assertRaisesRegex(ProtocolError, "Splits sans les deux labels"):
            validate_manifest(rows)

    def test_rejects_source_that_would_be_identifiable_from_the_label(self):
        rows = self.valid_rows()
        rows[0]["source_id"] = "tqvcd"
        rows[0]["source_url"] = "https://github.com/dxlabskku/TQVCD"
        rows[0]["license_id"] = "TQVCD-author-consent"
        with self.assertRaisesRegex(ProtocolError, "Chaque source doit contenir les deux labels"):
            validate_manifest(rows)

    def test_release_gate_is_inclusive_at_user_floor(self):
        result = evaluate_release_gate(
            {
                "balanced_accuracy": 0.75,
                "macro_f1": 0.75,
                "damage_recall": 0.75,
                "ece": 0.08,
            }
        )
        self.assertTrue(result.passed)
        self.assertEqual((), result.reasons)

    def test_release_gate_fails_closed(self):
        metrics = {
            "balanced_accuracy": 0.90,
            "macro_f1": 0.74,
            "damage_recall": 0.91,
            "ece": 0.09,
        }
        result = evaluate_release_gate(metrics)
        self.assertFalse(result.passed)
        self.assertEqual(2, len(result.reasons))


if __name__ == "__main__":
    unittest.main()
