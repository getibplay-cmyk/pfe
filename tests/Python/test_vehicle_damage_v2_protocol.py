from __future__ import annotations

import json
import tempfile
import unittest
import zipfile
from pathlib import Path

from scripts.intelligence.vehicle_damage_v2.build_rtdetr_config import render_config
from scripts.intelligence.vehicle_damage_v2.prepare_hitl_coco import build_split
from scripts.intelligence.vehicle_damage_v2.protocol import (
    HITL_LICENSE_ID,
    HITL_SOURCE_ID,
    HITL_SOURCE_URL,
    ProtocolError,
    SourceImage,
    assert_training_splits,
    claim_once_only_test,
    derive_source_images,
    evaluate_release_gate,
    file_sha256,
    validate_coco_document,
)
from scripts.intelligence.vehicle_damage_v2.stage_hitl_archive import safe_extract


class VehicleDamageV2ProtocolTest(unittest.TestCase):
    def legacy_rows(self):
        rows = []
        for index, split in enumerate(
            ("train", "validation", "calibration", "test"), start=1
        ):
            digest = f"{index:064x}"
            rows.append(
                {
                    "label": "1",
                    "group_id": digest,
                    "source_id": HITL_SOURCE_ID,
                    "source_url": HITL_SOURCE_URL,
                    "license_id": HITL_LICENSE_ID,
                    "license_status": "approved",
                    "license_proof": "S7_DAMAGE_HITL_ARCHIVE_REPORT_v1.json",
                    "split": split,
                    "source_image_sha256": digest,
                    "source_image_name": f"sample-{index}.jpg",
                    "source_annotation": f"Car parts dataset/File1/ann/sample-{index}.jpg.json",
                }
            )
        return rows

    def test_collapses_patch_manifest_without_changing_source_split(self):
        rows = self.legacy_rows()
        rows.append(dict(rows[0]))
        records = derive_source_images(rows)
        self.assertEqual(4, len(records))
        self.assertEqual("test", records[f"{4:064x}"].split)

    def test_rejects_source_image_split_leakage(self):
        rows = self.legacy_rows()
        leaked = dict(rows[0])
        leaked["split"] = "validation"
        rows.append(leaked)
        with self.assertRaisesRegex(ProtocolError, "plusieurs splits"):
            derive_source_images(rows)

    def test_training_preparation_cannot_request_legacy_test(self):
        self.assertEqual(("train", "validation"), assert_training_splits(["train", "validation"]))
        with self.assertRaisesRegex(ProtocolError, "test v1.1 est verrouillé"):
            assert_training_splits(["train", "test"])

    def valid_coco(self):
        return {
            "categories": [{"id": 0, "name": "visible_damage"}],
            "images": [
                {
                    "id": 1,
                    "file_name": "train/a.jpg",
                    "width": 100,
                    "height": 80,
                    "source_sha256": "a" * 64,
                    "split": "train",
                }
            ],
            "annotations": [
                {
                    "id": 1,
                    "image_id": 1,
                    "category_id": 0,
                    "bbox": [10.0, 5.0, 20.0, 30.0],
                    "area": 600.0,
                    "iscrowd": 0,
                }
            ],
        }

    def test_validates_one_class_coco_contract(self):
        self.assertEqual(
            {"images": 1, "annotations": 1},
            validate_coco_document(self.valid_coco(), "train"),
        )
        invalid = self.valid_coco()
        invalid["annotations"][0]["bbox"] = [90.0, 5.0, 20.0, 30.0]
        with self.assertRaisesRegex(ProtocolError, "hors image"):
            validate_coco_document(invalid, "train")

    def test_release_gate_requires_precision_coverage_and_domain_evidence(self):
        metrics = {
            "bbox_ap": 0.40,
            "bbox_ap50": 0.65,
            "photo_macro_f1": 0.90,
            "photo_balanced_accuracy": 0.90,
            "accepted_damage_precision": 0.95,
            "photo_damage_recall": 0.85,
            "accepted_coverage": 0.50,
            "ece": 0.05,
            "verified_clean_photo_count": 200,
            "domain_source_count": 2,
        }
        self.assertTrue(evaluate_release_gate(metrics).passed)
        metrics.pop("accepted_coverage")
        result = evaluate_release_gate(metrics)
        self.assertFalse(result.passed)
        self.assertIn("accepted_coverage absent ou non fini", result.reasons)

    def test_once_only_test_lock_fails_closed(self):
        with tempfile.TemporaryDirectory() as directory:
            lock = Path(directory) / "TEST_EVALUATION_LOCK.json"
            claim_once_only_test(lock, "b" * 64)
            payload = json.loads(lock.read_text(encoding="utf-8"))
            self.assertEqual("claimed_before_test_read", payload["state"])
            with self.assertRaisesRegex(ProtocolError, "déjà été"):
                claim_once_only_test(lock, "b" * 64)

    def test_builds_t4_safe_one_class_config(self):
        content = render_config(Path("/data"), Path("/run"), 1, 2, 2, 0)
        self.assertIn("num_classes: 1", content)
        self.assertIn("remap_mscoco_category: False", content)
        self.assertIn('ann_file: "/data/annotations/instances_train.json"', content)
        self.assertNotIn("instances_test", content)

    def test_converts_supervisely_polygon_and_verifies_raw_hash(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            image = root / "hitl/Car parts dataset/File1/img/sample.jpg"
            annotation = root / "hitl/Car parts dataset/File1/ann/sample.jpg.json"
            image.parent.mkdir(parents=True)
            annotation.parent.mkdir(parents=True)
            image.write_bytes(b"immutable-image")
            annotation.write_text(
                json.dumps(
                    {
                        "size": {"width": 100, "height": 80},
                        "objects": [
                            {
                                "classTitle": "Scratch",
                                "points": {"exterior": [[10, 10], [30, 10], [30, 25], [10, 25]]},
                            }
                        ],
                    }
                ),
                encoding="utf-8",
            )
            record = SourceImage(
                sha256=file_sha256(image),
                split="train",
                image_name="sample.jpg",
                annotation_path="Car parts dataset/File1/ann/sample.jpg.json",
                group_id=file_sha256(image),
            )
            report = build_split([record], "train", root / "hitl", root / "coco")
            self.assertEqual(1, report["images"])
            self.assertEqual(1, report["annotations"])
            document = json.loads(
                (root / "coco/annotations/instances_train.json").read_text(encoding="utf-8")
            )
            self.assertEqual("Scratch", document["annotations"][0]["damage_type"])

    def test_zip_slip_is_rejected_before_extraction(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            archive = root / "danger.zip"
            with zipfile.ZipFile(archive, "w") as handle:
                handle.writestr("../escape.txt", "blocked")
            with self.assertRaisesRegex(ValueError, "Chemin ZIP dangereux"):
                safe_extract(archive, root / "extract", "c" * 64)
            self.assertFalse((root / "escape.txt").exists())


if __name__ == "__main__":
    unittest.main()

