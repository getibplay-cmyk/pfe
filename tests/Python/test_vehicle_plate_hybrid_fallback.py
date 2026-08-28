from __future__ import annotations

import unittest

from scripts.intelligence.vehicle_plate.hybrid_fallback import (
    OcrObservation,
    build_hybrid_suggestion,
)
from scripts.intelligence.vehicle_plate.protocol import ProtocolError


def observation(
    role: str,
    text: str,
    score: float = 0.90,
    *,
    layout: str = "legacy-fixed",
    variant: str = "original",
) -> OcrObservation:
    return OcrObservation(layout, role, variant, text, score)


class VehiclePlateHybridFallbackTest(unittest.TestCase):
    def test_keeps_valid_full_crop_as_primary_but_requires_human_review(self):
        suggestion = build_hybrid_suggestion(
            [observation("full", "7أ12345", 0.96, layout="full")]
        )

        self.assertEqual("complete_primary_suggestion", suggestion.status)
        self.assertEqual("12345|أ|7", suggestion.canonical)
        self.assertEqual("full_crop_ppocrv5", suggestion.source)
        self.assertTrue(suggestion.human_review_required)
        self.assertEqual("NO_OPERATIONAL_ACTION", suggestion.operational_effect)

    def test_combines_separately_read_digits_and_arabic_series(self):
        suggestion = build_hybrid_suggestion(
            [
                observation("full", "", 0.0, layout="full"),
                observation("serial", "١٢٣٤٥", 0.91),
                observation("series", "ا A", 0.88),
                observation("region", "٧", 0.93),
            ]
        )

        self.assertEqual("complete_segmented_suggestion", suggestion.status)
        self.assertEqual("12345|أ|7", suggestion.canonical)
        self.assertEqual("12345 | أ | 7", suggestion.display_text)
        self.assertEqual("segmented_ppocrv5_fusion", suggestion.source)
        self.assertTrue(suggestion.human_review_required)

    def test_variant_agreement_strengthens_component_evidence(self):
        suggestion = build_hybrid_suggestion(
            [
                observation("serial", "12345", 0.80),
                observation("serial", "١٢٣٤٥", 0.79, variant="clahe"),
                observation("series", "ب", 0.82),
                observation("region", "8", 0.81),
            ]
        )

        serial = suggestion.components[0]
        self.assertEqual("12345", serial.value)
        self.assertEqual(2, serial.support)
        self.assertAlmostEqual(0.83, serial.confidence)

    def test_maps_redundant_unified_latin_series_but_marks_the_inference(self):
        suggestion = build_hybrid_suggestion(
            [
                observation("serial", "8765", 0.95),
                observation("series", "B", 0.94),
                observation("region", "12", 0.96),
            ]
        )

        self.assertEqual("8765|ب|12", suggestion.canonical)
        series = suggestion.components[1]
        self.assertTrue(series.inferred_from_latin)
        self.assertIn(
            "series_inferred_from_verified_latin_mapping", suggestion.reasons
        )
        self.assertLess(series.confidence, 0.94)

    def test_rejects_mismatched_arabic_latin_series_as_partial(self):
        suggestion = build_hybrid_suggestion(
            [
                observation("serial", "8765", 0.95),
                observation("series", "أ B", 0.94),
                observation("region", "12", 0.96),
            ]
        )

        self.assertEqual("partial_segmented_suggestion", suggestion.status)
        self.assertIsNone(suggestion.canonical)
        self.assertEqual("8765 | ? | 12", suggestion.display_text)
        self.assertIn("missing_series", suggestion.reasons)

    def test_does_not_build_a_complete_plate_across_incompatible_layouts(self):
        suggestion = build_hybrid_suggestion(
            [
                observation("serial", "12345", layout="layout-a"),
                observation("series", "د", layout="layout-b"),
                observation("region", "7", layout="layout-a"),
            ]
        )

        self.assertEqual("partial_segmented_suggestion", suggestion.status)
        self.assertIsNone(suggestion.canonical)
        self.assertEqual("12345 | ? | 7", suggestion.display_text)

    def test_returns_best_candidate_but_marks_close_conflict_as_ambiguous(self):
        suggestion = build_hybrid_suggestion(
            [
                observation("serial", "12345", 0.90),
                observation("serial", "12346", 0.88, variant="clahe"),
                observation("series", "أ", 0.95),
                observation("region", "7", 0.95),
            ]
        )

        self.assertEqual("ambiguous_segmented_suggestion", suggestion.status)
        self.assertEqual("12345|أ|7", suggestion.canonical)
        self.assertIn("competing_candidate:12346|أ|7", suggestion.reasons)

    def test_returns_explicit_empty_suggestion_without_inventing_text(self):
        suggestion = build_hybrid_suggestion(
            [observation("full", "", 0.0, layout="full")]
        )

        self.assertEqual("empty_suggestion", suggestion.status)
        self.assertIsNone(suggestion.canonical)
        self.assertEqual("? | ? | ?", suggestion.display_text)
        self.assertEqual(("no_readable_plate_component",), suggestion.reasons)

    def test_refuses_unknown_role_or_non_finite_score(self):
        with self.assertRaisesRegex(ProtocolError, "rôle inconnu"):
            build_hybrid_suggestion([observation("owner", "123")])
        with self.assertRaisesRegex(ProtocolError, "score hors limites"):
            build_hybrid_suggestion([observation("serial", "123", float("nan"))])


if __name__ == "__main__":
    unittest.main()
