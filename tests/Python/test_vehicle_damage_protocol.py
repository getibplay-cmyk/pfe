from __future__ import annotations

import tempfile
import unittest
from collections import Counter
from pathlib import Path

from scripts.intelligence.vehicle_damage.protocol import (
    ProtocolError,
    evaluate_release_gate,
    file_sha256,
    grouped_bootstrap_indices,
    read_completed_run,
    remove_stale_model_export,
    sha256sum_lines,
    validate_manifest,
    verify_manifest_files,
    write_json,
    write_run_completion,
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

    def test_verifies_image_contents_against_manifest_hash(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            image = root / "data/images/sample.jpg"
            proof = root / "licences/hitl-access-receipt.pdf"
            image.parent.mkdir(parents=True)
            proof.parent.mkdir(parents=True)
            image.write_bytes(b"frozen-image")
            proof.write_bytes(b"private-proof")
            row = {
                "image_path": "images/sample.jpg",
                "sha256": file_sha256(image),
                "license_proof": "hitl-access-receipt.pdf",
            }

            verify_manifest_files([row], root / "data", root / "licences")
            image.write_bytes(b"modified-image")
            with self.assertRaisesRegex(ProtocolError, "Empreinte SHA-256 différente"):
                verify_manifest_files([row], root / "data", root / "licences")

    def test_grouped_bootstrap_keeps_all_patches_of_a_group_together(self):
        group_ids = ["vehicle-a", "vehicle-a", "vehicle-b", "vehicle-c", "vehicle-c"]
        first = list(grouped_bootstrap_indices(group_ids, iterations=12, seed=42))
        second = list(grouped_bootstrap_indices(group_ids, iterations=12, seed=42))
        self.assertEqual(first, second)

        group_members = {
            "vehicle-a": (0, 1),
            "vehicle-b": (2,),
            "vehicle-c": (3, 4),
        }
        for sample in first:
            counts = Counter(sample)
            for indices in group_members.values():
                multiplicities = {counts[index] for index in indices}
                self.assertEqual(1, len(multiplicities))

    def test_completed_run_guard_checks_frozen_evidence(self):
        with tempfile.TemporaryDirectory() as directory:
            output = Path(directory)
            artifact = output / "metrics.json"
            write_json(artifact, {"release_gate": {"passed": True}})
            (output / "SHA256SUMS").write_text(
                "\n".join(sha256sum_lines([artifact], output)) + "\n",
                encoding="utf-8",
            )

            self.assertTrue(read_completed_run(output))
            write_run_completion(output, passed=True)
            self.assertTrue(read_completed_run(output))
            artifact.write_text("tampered\n", encoding="utf-8")
            with self.assertRaisesRegex(ProtocolError, "Artefact absent ou modifié"):
                read_completed_run(output)

    def test_failed_run_removes_stale_onnx_export(self):
        with tempfile.TemporaryDirectory() as directory:
            model = Path(directory) / "model.onnx"
            model.write_bytes(b"stale-qualified-model")
            remove_stale_model_export(directory)
            self.assertFalse(model.exists())


if __name__ == "__main__":
    unittest.main()
