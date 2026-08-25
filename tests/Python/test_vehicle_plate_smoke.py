from __future__ import annotations

import csv
import json
import tempfile
import unittest
from pathlib import Path

from scripts.intelligence.vehicle_plate.colab_smoke import (
    EXPECTED_DETECTOR,
    OCR_MODEL_NAME,
    OCR_WORKER_SCHEMA_VERSION,
    aggregate_smoke_metrics,
    box_iou,
    discover_images,
    expand_box,
    extract_ocr_result,
    load_bilingual_mapping,
    load_detector_selection,
    load_smoke_labels,
    validate_ocr_worker_payload,
    verify_checkpoint,
)
from scripts.intelligence.vehicle_plate.protocol import ProtocolError, file_sha256


class FakeOcrResult:
    json = {"res": {"rec_text": "12345 أ 7", "rec_score": 0.98}}


class VehiclePlateSmokeTest(unittest.TestCase):
    def write_labels(self, path: Path, **overrides: str) -> None:
        row = {
            "image_path": "vehicle/front.jpg",
            "group_id": "vehicle-1",
            "split": "validation",
            "target": "12345 أ 7",
            "plate_bbox": "[10,20,110,50]",
            "sha256": "a" * 64,
            "source_id": "rentfleet_private_consented_smoke",
            "consent_status": "approved",
        }
        row.update(overrides)
        with path.open("w", encoding="utf-8", newline="") as handle:
            writer = csv.DictWriter(handle, fieldnames=list(row))
            writer.writeheader()
            writer.writerow(row)

    def test_loads_consenting_development_labels(self):
        with tempfile.TemporaryDirectory() as directory:
            path = Path(directory) / "labels.csv"
            self.write_labels(path)
            labels = load_smoke_labels(path)
            self.assertEqual("12345|أ|7", labels["vehicle/front.jpg"].target)
            self.assertEqual((10.0, 20.0, 110.0, 50.0), labels["vehicle/front.jpg"].plate_bbox)

    def test_smoke_refuses_final_test_rows(self):
        with tempfile.TemporaryDirectory() as directory:
            path = Path(directory) / "labels.csv"
            self.write_labels(path, split="test")
            with self.assertRaisesRegex(ProtocolError, "test final réservé"):
                load_smoke_labels(path)

    def test_smoke_refuses_unapproved_consent(self):
        with tempfile.TemporaryDirectory() as directory:
            path = Path(directory) / "labels.csv"
            self.write_labels(path, consent_status="pending")
            with self.assertRaisesRegex(ProtocolError, "consentement non approuvé"):
                load_smoke_labels(path)

    def test_selection_and_checkpoint_hash_are_verified(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            checkpoint = root / "model.pt"
            checkpoint.write_bytes(b"trusted-private-checkpoint")
            selection_path = root / "selection.json"
            selection_path.write_text(
                json.dumps(
                    {
                        "selected_candidate": EXPECTED_DETECTOR,
                        "selected_model_sha256": file_sha256(checkpoint),
                        "calibrated_threshold": 0.425,
                        "selection_role": "development_only_not_independent_evidence",
                    }
                ),
                encoding="utf-8",
            )
            selection = load_detector_selection(selection_path)
            self.assertEqual(0.425, selection.threshold)
            self.assertEqual(file_sha256(checkpoint), verify_checkpoint(checkpoint, selection))
            checkpoint.write_bytes(b"tampered")
            with self.assertRaisesRegex(ProtocolError, "Empreinte du checkpoint"):
                verify_checkpoint(checkpoint, selection)

    def test_bilingual_mapping_requires_official_verification(self):
        with tempfile.TemporaryDirectory() as directory:
            path = Path(directory) / "mapping.json"
            path.write_text(
                json.dumps(
                    {
                        "verification_status": "verified_against_official_annex",
                        "official_source_url": "https://example.gov.ma/annex.pdf",
                        "mapping": {"أ": "A", "ب": "B"},
                    },
                    ensure_ascii=False,
                ),
                encoding="utf-8",
            )
            self.assertEqual({"أ": "A", "ب": "B"}, load_bilingual_mapping(path))
            payload = json.loads(path.read_text(encoding="utf-8"))
            payload["verification_status"] = "draft"
            path.write_text(json.dumps(payload, ensure_ascii=False), encoding="utf-8")
            with self.assertRaisesRegex(ProtocolError, "n'est pas attestée"):
                load_bilingual_mapping(path)

    def test_geometry_helpers_clamp_and_measure_iou(self):
        self.assertEqual((0, 6, 120, 54), expand_box((10, 10, 110, 50), 200, 100, 0.10))
        self.assertEqual(1.0, box_iou((0, 0, 10, 10), (0, 0, 10, 10)))
        self.assertEqual(0.0, box_iou((0, 0, 10, 10), (20, 20, 30, 30)))

    def test_extracts_documented_paddleocr_json_shape(self):
        self.assertEqual(("12345 أ 7", 0.98), extract_ocr_result(FakeOcrResult()))

    def test_accepts_closed_isolated_ocr_worker_contract(self):
        payload = {
            "schema_version": OCR_WORKER_SCHEMA_VERSION,
            "model_name": OCR_MODEL_NAME,
            "count": 1,
            "results": [{"crop_id": "crop-00000", "raw_text": "12345 أ 7", "score": 0.98}],
            "timings_seconds": {"ocr_load": 1.5, "ocr_inference_total": 0.2},
            "environment": {
                "isolated_process": True,
                "device": "gpu:0",
                "paddle_cuda_compiled": True,
            },
        }
        results = validate_ocr_worker_payload(payload, ["crop-00000"])
        self.assertEqual("12345 أ 7", results["crop-00000"]["raw_text"])

    def test_rejects_incomplete_or_non_isolated_ocr_worker_contract(self):
        payload = {
            "schema_version": OCR_WORKER_SCHEMA_VERSION,
            "model_name": OCR_MODEL_NAME,
            "count": 0,
            "results": [],
            "timings_seconds": {"ocr_load": 1.0, "ocr_inference_total": 0.0},
            "environment": {
                "isolated_process": False,
                "device": "gpu:0",
                "paddle_cuda_compiled": True,
            },
        }
        with self.assertRaisesRegex(ProtocolError, "isolation"):
            validate_ocr_worker_payload(payload, [])
        payload["environment"]["isolated_process"] = True
        with self.assertRaisesRegex(ProtocolError, "exactement un résultat"):
            validate_ocr_worker_payload(payload, ["crop-00000"])

    def test_aggregate_metrics_counts_abstention_as_end_to_end_error(self):
        metrics = aggregate_smoke_metrics(
            [
                {
                    "target": "12345|أ|7",
                    "raw_prediction": "12345|أ|7",
                    "accepted_prediction": "12345|أ|7",
                },
                {
                    "target": "999|ب|8",
                    "raw_prediction": "998|ب|8",
                    "accepted_prediction": None,
                },
            ],
            [0.75, 0.25],
        )
        self.assertEqual(0.5, metrics["raw_ocr_full_plate_exact"])
        self.assertEqual(1.0, metrics["selective_exact"])
        self.assertEqual(0.5, metrics["selective_coverage"])
        self.assertEqual(0.5, metrics["end_to_end_exact"])
        self.assertEqual(0.5, metrics["detection_recall_iou50"])

    def test_image_discovery_is_sorted_and_bounded(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            (root / "b.png").write_bytes(b"b")
            (root / "a.jpg").write_bytes(b"a")
            (root / "ignored.txt").write_text("x", encoding="utf-8")
            self.assertEqual([root / "a.jpg"], discover_images(root, 1))


if __name__ == "__main__":
    unittest.main()
