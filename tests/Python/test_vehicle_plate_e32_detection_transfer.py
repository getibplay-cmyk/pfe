from __future__ import annotations

import csv
import inspect
import json
import tempfile
import unittest
from collections import Counter
from pathlib import Path

from scripts.intelligence.vehicle_plate.e32_detection_transfer import (
    E32ProtocolError,
    aggregate_domain_metrics,
    balanced_epoch_records,
    build_model,
    evaluate_predictions,
    load_ccpd_records,
    normalize_private_records,
    select_calibration_threshold,
    select_candidate_epoch,
)


ROOT = Path(__file__).resolve().parents[2]


def metric(map50: float, recall: float, precision: float = 0.9) -> dict:
    return {
        "map50": map50,
        "recall": recall,
        "precision": precision,
        "f1": 2 * precision * recall / (precision + recall),
    }


class E32DetectionTransferContractTest(unittest.TestCase):
    def _bundle(self, root: Path) -> Path:
        bundle = root / "bundle"
        safeguards = {
            "contains_test_split": False,
            "final_test_opened": False,
            "qualification_claim": False,
            "saas_integration_allowed": False,
            "ccpd_sequence_field_parsed": False,
            "ccpd_sequence_field_used_as_ocr_truth": False,
        }
        (bundle / "annotations").mkdir(parents=True)
        (bundle / "generation_report.json").write_text(
            json.dumps(
                {
                    "status": "development_detection_source_bundle_not_qualified",
                    "source": {"source_id": "ccpd_official_mit"},
                    "safeguards": safeguards,
                }
            ),
            encoding="utf-8",
        )
        rows = []
        for index, split in enumerate(("train", "validation", "calibration"), start=1):
            sample = f"sample-{split}"
            image_relative = Path("images") / split / f"{sample}.jpg"
            image_path = bundle / image_relative
            image_path.parent.mkdir(parents=True)
            image_path.write_bytes(b"fixture")
            document = {
                "categories": [{"id": 1, "name": "plate", "supercategory": "plate"}],
                "images": [
                    {
                        "id": index,
                        "file_name": image_relative.as_posix(),
                        "width": 100,
                        "height": 60,
                        "sample_id": sample,
                        "group_id": f"group-{split}",
                        "source_partition": "ccpd_base",
                    }
                ],
                "annotations": [
                    {
                        "id": index,
                        "image_id": index,
                        "category_id": 1,
                        "bbox": [20, 20, 50, 20],
                        "area": 1000,
                        "iscrowd": 0,
                    }
                ],
            }
            (bundle / "annotations" / f"instances_{split}.json").write_text(
                json.dumps(document), encoding="utf-8"
            )
            rows.append(
                {
                    "sample_id": sample,
                    "split": split,
                    "holdout_role": "development",
                    "source_id": "ccpd_official_mit",
                    "ocr_truth_used": "false",
                    "group_id": f"group-{split}",
                }
            )
        with (bundle / "manifest.csv").open("w", encoding="utf-8", newline="") as handle:
            writer = csv.DictWriter(handle, fieldnames=list(rows[0]))
            writer.writeheader()
            writer.writerows(rows)
        return bundle

    def _private_row(self, image: Path, source: str, image_id: str, role: str) -> dict:
        return {
            "image_id": image_id,
            "image_path": image,
            "source": source,
            "role": role,
            "width": 100,
            "height": 60,
            "boxes": [[20, 20, 70, 40]],
        }

    def test_ccpd_bundle_is_detection_only_and_group_safe(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            bundle = self._bundle(root)
            records = load_ccpd_records(bundle)
            self.assertEqual(
                {"train": 1, "validation": 1, "calibration": 1},
                {name: len(rows) for name, rows in records.items()},
            )
            self.assertEqual("ccpd_official_mit", records["train"][0]["source"])
            self.assertNotIn("recognition_text", records["train"][0])

            with (bundle / "manifest.csv").open(encoding="utf-8", newline="") as handle:
                manifest = list(csv.DictReader(handle))
            manifest[0]["split"] = "test"
            with (bundle / "manifest.csv").open("w", encoding="utf-8", newline="") as handle:
                writer = csv.DictWriter(handle, fieldnames=list(manifest[0]))
                writer.writeheader()
                writer.writerows(manifest)
            with self.assertRaisesRegex(E32ProtocolError, "Split interdit"):
                load_ccpd_records(bundle)

    def test_private_adapter_rejects_final_or_independent_roles(self):
        with tempfile.TemporaryDirectory() as directory:
            image = Path(directory) / "plate.jpg"
            image.write_bytes(b"fixture")
            sources = (
                "primary_moroccan_cc0_v2",
                "secondary_moroccan_cc_by_sa_v2",
                "hf_generic_cc_by_4",
            )
            contract = {
                "training": [self._private_row(image, sources[0], "train", "training")],
                "primary_validation": [
                    self._private_row(image, sources[0], "primary-valid", "development_selection")
                ],
                "secondary_validation": [
                    self._private_row(
                        image,
                        sources[1],
                        "secondary-valid",
                        "consumed_development_selection",
                    )
                ],
                "primary_calibration": [
                    self._private_row(
                        image, sources[0], "primary-cal", "development_calibration"
                    )
                ],
                "secondary_calibration": [
                    self._private_row(
                        image,
                        sources[1],
                        "secondary-cal",
                        "consumed_development_calibration",
                    )
                ],
            }
            normalized = normalize_private_records(contract)
            self.assertEqual(5, sum(len(rows) for rows in normalized.values()))
            contract["secondary_calibration"][0]["role"] = "final_holdout"
            with self.assertRaisesRegex(E32ProtocolError, "Rôle privé interdit"):
                normalize_private_records(contract)

    def test_balanced_epoch_is_deterministic_and_equal_source(self):
        private = []
        for source in (
            "primary_moroccan_cc0_v2",
            "secondary_moroccan_cc_by_sa_v2",
            "hf_generic_cc_by_4",
        ):
            for index in range(3):
                private.append({"source": source, "image_id": f"{source}-{index}"})
        ccpd = [
            {"source": "ccpd_official_mit", "image_id": f"ccpd-{index}"}
            for index in range(3)
        ]
        quotas = {
            "primary_moroccan_cc0_v2": 4,
            "secondary_moroccan_cc_by_sa_v2": 4,
            "hf_generic_cc_by_4": 4,
            "ccpd_official_mit": 4,
        }
        first = balanced_epoch_records(private, ccpd, epoch=1, seed=7, quotas=quotas)
        second = balanced_epoch_records(private, ccpd, epoch=1, seed=7, quotas=quotas)
        self.assertEqual(first, second)
        self.assertEqual(quotas, Counter(row["source"] for row in first))

    def test_ap_matching_counts_duplicate_detection_as_false_positive(self):
        records = [
            {
                "image_id": "one",
                "boxes": [[10, 10, 30, 30]],
            }
        ]
        predictions = {
            "one": {
                "boxes": [[10, 10, 30, 30], [11, 11, 31, 31]],
                "scores": [0.9, 0.8],
            }
        }
        metrics = evaluate_predictions(records, predictions, score_threshold=0.1)
        self.assertEqual(1, metrics["true_positives"])
        self.assertEqual(1, metrics["false_positives"])
        self.assertEqual(0, metrics["false_negatives"])
        self.assertAlmostEqual(1.0, metrics["map50"])
        self.assertAlmostEqual(0.5, metrics["precision"])

    def test_selection_preserves_moroccan_anchors_and_improves_three_domains(self):
        incumbent = aggregate_domain_metrics(
            {
                "moroccan_primary": metric(0.99, 1.0),
                "moroccan_secondary_consumed": metric(0.88, 0.92),
                "ccpd_public": metric(0.55, 0.65),
            }
        )
        good = aggregate_domain_metrics(
            {
                "moroccan_primary": metric(0.98, 0.99),
                "moroccan_secondary_consumed": metric(0.89, 0.93),
                "ccpd_public": metric(0.84, 0.90),
            }
        )
        regressed = aggregate_domain_metrics(
            {
                "moroccan_primary": metric(0.94, 0.95),
                "moroccan_secondary_consumed": metric(0.91, 0.95),
                "ccpd_public": metric(0.92, 0.96),
            }
        )
        decision = select_candidate_epoch(
            incumbent,
            [
                {"epoch": 1, "development": good},
                {"epoch": 2, "development": regressed},
            ],
        )
        self.assertEqual("challenger", decision["selected"])
        self.assertEqual(1, decision["epoch"])

    def test_calibration_requires_recall_on_all_three_domains(self):
        rows = [
            {
                "score_threshold": 0.4,
                "moroccan_primary_recall": 0.98,
                "moroccan_secondary_consumed_recall": 0.96,
                "ccpd_public_recall": 0.97,
                "macro_f1": 0.91,
                "worst_domain_precision": 0.88,
                "worst_domain_recall": 0.96,
            },
            {
                "score_threshold": 0.6,
                "moroccan_primary_recall": 0.98,
                "moroccan_secondary_consumed_recall": 0.94,
                "ccpd_public_recall": 0.99,
                "macro_f1": 0.95,
                "worst_domain_precision": 0.93,
                "worst_domain_recall": 0.94,
            },
        ]
        selected, outcome = select_calibration_threshold(rows)
        self.assertEqual(0.4, selected["score_threshold"])
        self.assertIn("all_three", outcome)

    def test_e32_does_not_download_new_torchvision_weights(self):
        source = inspect.getsource(build_model)
        self.assertIn("weights=None", source)
        self.assertIn("weights_backbone=None", source)
        self.assertNotIn("Weights.DEFAULT", source)

    def test_public_protocol_and_notebook_do_not_embed_private_inputs(self):
        protocol_path = (
            ROOT
            / "docs/intelligence/evidence/moroccan-anpr-e3.2-detection-transfer-protocol.json"
        )
        protocol = json.loads(protocol_path.read_text(encoding="utf-8"))
        self.assertEqual(
            "preregistered_development_not_executed_not_qualified",
            protocol["status"],
        )
        self.assertFalse(protocol["safeguards"]["future_holdout_labels_opened"])
        self.assertFalse(protocol["safeguards"]["saas_integration_allowed"])
        self.assertFalse(protocol["architecture"]["new_torchvision_weights_downloaded"])

        cells_path = (
            ROOT
            / "scripts/intelligence/vehicle_plate/e32_detection_transfer_cells.json"
        )
        cells_text = cells_path.read_text(encoding="utf-8")
        self.assertIn("PRIVATE_ADAPTER_PATH = ''", cells_text)
        self.assertNotIn("RentFleet_PFE", cells_text)
        self.assertNotIn("S7_06", cells_text)
        notebook_path = (
            ROOT
            / "notebooks/colab/moroccan_vehicle_plate_anpr_v2_e32_detection_transfer.ipynb"
        )
        notebook = json.loads(notebook_path.read_text(encoding="utf-8"))
        code_cells = [cell for cell in notebook["cells"] if cell["cell_type"] == "code"]
        self.assertTrue(code_cells)
        self.assertTrue(all(cell["execution_count"] is None for cell in code_cells))
        self.assertTrue(all(cell["outputs"] == [] for cell in code_cells))


if __name__ == "__main__":
    unittest.main()
