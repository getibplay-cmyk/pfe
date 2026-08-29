from __future__ import annotations

import csv
import json
import tempfile
import unittest
from pathlib import Path

from scripts.intelligence.vehicle_plate.summarize_hybrid_review import (
    ReviewEvidenceError,
    build_summary,
    summarize_csv,
)


def row(
    image: str,
    suggestion: str,
    correction: str,
    tool_status: str,
    review_status: str,
    fallback: str,
) -> dict[str, str]:
    return {
        "image": image,
        "prediction": "",
        "correction": correction,
        "tool_suggestion": suggestion,
        "tool_status": tool_status,
        "tool_confidence": "0.900000",
        "review_status": review_status,
        "model_version": "arabic_PP-OCRv5_mobile_rec+hybrid-1.0.0",
        "fallback_executed": fallback,
    }


class VehiclePlateHybridReviewSummaryTest(unittest.TestCase):
    def test_builds_aggregate_counts_without_plate_text_or_identifiers(self):
        rows = [
            row(
                "private/alpha.png",
                "12345|أ|7",
                "12345|أ|7",
                "complete_primary_suggestion",
                "confirmed",
                "false",
            ),
            row(
                "private/beta.png",
                "888|ب|9",
                "888|ب|10",
                "complete_segmented_suggestion",
                "corrected",
                "true",
            ),
            row(
                "private/gamma.png",
                "? | د | 4",
                "",
                "partial_segmented_suggestion",
                "pending",
                "true",
            ),
        ]

        summary = build_summary(rows)

        self.assertEqual(3, summary["row_count"])
        self.assertEqual(2, summary["tool"]["complete_suggestion_count"])
        self.assertEqual(2, summary["tool"]["fallback_count"])
        self.assertEqual(2, summary["human_review"]["verified_canonical_count"])
        self.assertEqual(1, summary["human_review"]["tool_exact_match_count"])
        self.assertEqual(0.5, summary["human_review"]["tool_exact_match_rate_on_verified"])
        self.assertFalse(summary["scientific_interpretation"]["accuracy_qualified"])
        serialized = json.dumps(summary, ensure_ascii=False, sort_keys=True)
        for private_value in (
            "alpha.png",
            "beta.png",
            "gamma.png",
            "12345|أ|7",
            "888|ب|10",
        ):
            self.assertNotIn(private_value, serialized)

    def test_refuses_unknown_state_and_duplicate_image(self):
        valid = row(
            "1.png",
            "123|أ|7",
            "",
            "complete_primary_suggestion",
            "pending",
            "false",
        )
        invalid = dict(valid, image="2.png", review_status="auto_accepted")
        with self.assertRaisesRegex(ReviewEvidenceError, "statut de revue"):
            build_summary([valid, invalid])
        with self.assertRaisesRegex(ReviewEvidenceError, "dupliquée"):
            build_summary([valid, valid])

    def test_writes_new_aggregate_file_and_never_overwrites(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            source = root / "private-review.csv"
            output = root / "aggregate.json"
            rows = [
                row(
                    "1.png",
                    "123|أ|7",
                    "",
                    "complete_primary_suggestion",
                    "pending",
                    "false",
                )
            ]
            with source.open("w", encoding="utf-8", newline="") as handle:
                writer = csv.DictWriter(handle, fieldnames=list(rows[0]))
                writer.writeheader()
                writer.writerows(rows)

            summarize_csv(source, output)
            payload = json.loads(output.read_text(encoding="utf-8"))
            self.assertEqual(1, payload["row_count"])
            with self.assertRaises(FileExistsError):
                summarize_csv(source, output)


if __name__ == "__main__":
    unittest.main()
