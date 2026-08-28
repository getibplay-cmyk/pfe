from __future__ import annotations

import csv
import json
import tempfile
import unittest
from pathlib import Path

from scripts.intelligence.vehicle_plate.build_hybrid_review_csv import (
    build_review_rows,
    write_review_csv,
)
from scripts.intelligence.vehicle_plate.hybrid_fallback import (
    HYBRID_FALLBACK_VERSION,
    OCR_MODEL_NAME,
)
from scripts.intelligence.vehicle_plate.hybrid_ocr_worker import (
    OUTPUT_SCHEMA_VERSION,
)
from scripts.intelligence.vehicle_plate.protocol import ProtocolError


def suggestion(
    status: str,
    canonical: str | None,
    display_text: str,
    confidence: float,
) -> dict:
    return {
        "schema_version": HYBRID_FALLBACK_VERSION,
        "status": status,
        "canonical": canonical,
        "display_text": display_text,
        "confidence": confidence,
        "confidence_semantics": "uncalibrated_evidence_score",
        "source": "segmented_ppocrv5_fusion",
        "model_name": OCR_MODEL_NAME,
        "components": [],
        "reasons": [],
        "human_review_required": True,
        "operational_effect": "NO_OPERATIONAL_ACTION",
    }


def payload() -> dict:
    return {
        "schema_version": OUTPUT_SCHEMA_VERSION,
        "fallback_version": HYBRID_FALLBACK_VERSION,
        "model_name": OCR_MODEL_NAME,
        "count": 3,
        "results": [
            {
                "crop_id": "1.png",
                "fallback_executed": True,
                "suggestion": suggestion(
                    "complete_segmented_suggestion", "12345|أ|7", "12345 | أ | 7", 0.9
                ),
                "observations": [],
            },
            {
                "crop_id": "2",
                "fallback_executed": True,
                "suggestion": suggestion(
                    "partial_segmented_suggestion", None, "987 | ? | 12", 0.7
                ),
                "observations": [],
            },
            {
                "crop_id": "3.png",
                "fallback_executed": False,
                "suggestion": suggestion(
                    "complete_primary_suggestion", "555|ب|8", "555 | ب | 8", 0.98
                ),
                "observations": [],
            },
        ],
        "safeguards": {
            "human_review_required": True,
            "automatic_vehicle_update_allowed": False,
            "operational_effect": "NO_OPERATIONAL_ACTION",
            "second_ocr_model_used": False,
        },
    }


class VehiclePlateHybridReviewCsvTest(unittest.TestCase):
    def test_preserves_existing_correction_prefills_complete_and_keeps_partial_empty(self):
        rows, counts = build_review_rows(
            [
                {"image": "images/1.png", "prediction": "", "correction": ""},
                {"image": "2.png", "prediction": "", "correction": ""},
                {"image": "3.png", "prediction": "", "correction": "555|ب|9"},
            ],
            payload(),
        )

        self.assertEqual("12345|أ|7", rows[0]["tool_suggestion"])
        self.assertEqual("12345|أ|7", rows[0]["correction"])
        self.assertEqual("pending", rows[0]["review_status"])
        self.assertEqual("987 | ? | 12", rows[1]["tool_suggestion"])
        self.assertEqual("", rows[1]["correction"])
        self.assertEqual("555|ب|9", rows[2]["correction"])
        self.assertEqual("reviewed_existing", rows[2]["review_status"])
        self.assertEqual(1, counts["preserved_human_correction"])
        self.assertEqual(1, counts["prefilled_pending"])
        self.assertEqual(1, counts["pending_without_complete_suggestion"])

    def test_can_keep_all_new_corrections_empty(self):
        rows, _ = build_review_rows(
            [
                {"image": "1.png", "prediction": "", "correction": ""},
                {"image": "2.png", "prediction": "", "correction": ""},
                {"image": "3.png", "prediction": "", "correction": ""},
            ],
            payload(),
            prefill_complete_corrections=False,
        )
        self.assertTrue(all(row["correction"] == "" for row in rows))
        self.assertTrue(all(row["review_status"] == "pending" for row in rows))

    def test_refuses_missing_or_extra_hybrid_rows(self):
        with self.assertRaisesRegex(ProtocolError, "Aucun résultat hybride"):
            build_review_rows(
                [{"image": "missing.png", "prediction": "", "correction": ""}],
                payload(),
            )

    def test_writes_a_new_file_and_never_overwrites_it(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            labels = root / "labels.csv"
            hybrid = root / "hybrid.json"
            output = root / "labels_hybrid_review.csv"
            with labels.open("w", encoding="utf-8", newline="") as handle:
                writer = csv.DictWriter(
                    handle, fieldnames=["image", "prediction", "correction"]
                )
                writer.writeheader()
                writer.writerows(
                    [
                        {"image": "1.png", "prediction": "", "correction": ""},
                        {"image": "2.png", "prediction": "", "correction": ""},
                        {"image": "3.png", "prediction": "", "correction": ""},
                    ]
                )
            hybrid.write_text(
                json.dumps(payload(), ensure_ascii=False), encoding="utf-8"
            )

            write_review_csv(labels, hybrid, output)
            self.assertTrue(output.is_file())
            with output.open("r", encoding="utf-8", newline="") as handle:
                rows = list(csv.DictReader(handle))
            self.assertEqual(3, len(rows))
            self.assertIn("tool_suggestion", rows[0])
            self.assertIn("review_status", rows[0])
            with self.assertRaises(FileExistsError):
                write_review_csv(labels, hybrid, output)


if __name__ == "__main__":
    unittest.main()
