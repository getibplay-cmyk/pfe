from __future__ import annotations

import unittest

from scripts.intelligence.vehicle_plate.hybrid_fallback import (
    HYBRID_FALLBACK_VERSION,
)
from scripts.intelligence.vehicle_plate.hybrid_ocr_worker import (
    OCR_MODEL_NAME,
    OUTPUT_SCHEMA_VERSION,
    _supports_segmented_fallback,
    fixed_zone_layouts,
    validate_hybrid_worker_payload,
)
from scripts.intelligence.vehicle_plate.protocol import ProtocolError


def suggestion_payload() -> dict:
    return {
        "schema_version": HYBRID_FALLBACK_VERSION,
        "status": "complete_segmented_suggestion",
        "canonical": "12345|أ|7",
        "display_text": "12345 | أ | 7",
        "confidence": 0.88,
        "confidence_semantics": "uncalibrated_evidence_score",
        "source": "segmented_ppocrv5_fusion",
        "model_name": OCR_MODEL_NAME,
        "components": [],
        "reasons": ["primary_empty_or_grammar_rejected"],
        "human_review_required": True,
        "operational_effect": "NO_OPERATIONAL_ACTION",
    }


class VehiclePlateHybridWorkerTest(unittest.TestCase):
    def test_fixed_zone_hypotheses_are_bounded_and_complete(self):
        layouts = fixed_zone_layouts(520, 110)

        self.assertEqual(
            {"legacy-wide", "legacy-balanced", "unified-2026"},
            {layout.layout_id for layout in layouts},
        )
        for layout in layouts:
            self.assertEqual(
                ("serial", "series", "region"),
                tuple(zone.role for zone in layout.zones),
            )
            for zone in layout.zones:
                x1, y1, x2, y2 = zone.box
                self.assertTrue(0 <= x1 < x2 <= 520)
                self.assertEqual((0, 110), (y1, y2))

    def test_tiny_crop_is_rejected_before_ocr(self):
        with self.assertRaisesRegex(ProtocolError, "trop petit"):
            fixed_zone_layouts(40, 12)

    def test_tiny_crop_keeps_full_crop_ocr_without_segmented_fallback(self):
        self.assertFalse(_supports_segmented_fallback(59, 40))
        self.assertFalse(_supports_segmented_fallback(76, 17))
        self.assertTrue(_supports_segmented_fallback(60, 20))

    def test_output_contract_requires_one_human_review_row_per_crop(self):
        payload = {
            "schema_version": OUTPUT_SCHEMA_VERSION,
            "fallback_version": HYBRID_FALLBACK_VERSION,
            "model_name": OCR_MODEL_NAME,
            "count": 1,
            "results": [
                {
                    "crop_id": "crop-00001",
                    "fallback_executed": True,
                    "suggestion": suggestion_payload(),
                    "observations": [],
                }
            ],
            "safeguards": {
                "human_review_required": True,
                "automatic_vehicle_update_allowed": False,
                "operational_effect": "NO_OPERATIONAL_ACTION",
                "second_ocr_model_used": False,
            },
        }

        indexed = validate_hybrid_worker_payload(payload, ["crop-00001"])
        self.assertEqual(["crop-00001"], list(indexed))

        payload["results"][0]["suggestion"]["human_review_required"] = False
        with self.assertRaisesRegex(ProtocolError, "validation humaine"):
            validate_hybrid_worker_payload(payload, ["crop-00001"])

    def test_output_contract_refuses_automatic_effect_or_wrong_crop_ids(self):
        payload = {
            "schema_version": OUTPUT_SCHEMA_VERSION,
            "fallback_version": HYBRID_FALLBACK_VERSION,
            "model_name": OCR_MODEL_NAME,
            "count": 1,
            "results": [
                {
                    "crop_id": "crop-00001",
                    "fallback_executed": True,
                    "suggestion": suggestion_payload(),
                    "observations": [],
                }
            ],
            "safeguards": {
                "human_review_required": True,
                "automatic_vehicle_update_allowed": False,
                "operational_effect": "NO_OPERATIONAL_ACTION",
                "second_ocr_model_used": False,
            },
        }
        payload["results"][0]["suggestion"]["operational_effect"] = "UPDATE_VEHICLE"
        with self.assertRaisesRegex(ProtocolError, "action automatique"):
            validate_hybrid_worker_payload(payload, ["crop-00001"])

        payload["results"][0]["suggestion"] = suggestion_payload()
        with self.assertRaisesRegex(ProtocolError, "crop_id"):
            validate_hybrid_worker_payload(payload, ["crop-99999"])


if __name__ == "__main__":
    unittest.main()
