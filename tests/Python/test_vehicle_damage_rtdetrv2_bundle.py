"""Evidence-contract tests for the RT-DETRv2-S bundle builder."""

from __future__ import annotations

import importlib.util
import json
import sys
import tempfile
import unittest
from pathlib import Path


ROOT = Path(__file__).resolve().parents[2]
BUILDER_PATH = (
    ROOT
    / "scripts"
    / "intelligence"
    / "vehicle_damage"
    / "build_rtdetrv2_s_bundle.py"
)
sys.dont_write_bytecode = True
SPEC = importlib.util.spec_from_file_location("rentfleet_rtdetrv2_bundle", BUILDER_PATH)
if SPEC is None or SPEC.loader is None:
    raise RuntimeError("Unable to load the RT-DETRv2-S bundle builder.")
BUILDER = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(BUILDER)


def _policy() -> dict[str, object]:
    return {
        "schema_version": "2.0.0",
        "model": "RT-DETRv2-S RentFleet",
        "selected_checkpoint": BUILDER.CHECKPOINT_FILENAME,
        "selected_checkpoint_bytes": BUILDER.CHECKPOINT_BYTES,
        "selected_checkpoint_sha256": BUILDER.CHECKPOINT_SHA256,
        "selected_variant": "soup_19_24_29_centered_nms_0.72",
        "fixed_input_size": 640,
        "default_operating_profile": "precision_90",
        "calibration_used": False,
        "test_used": False,
        "final_test_sealed": True,
        "deployment_requires_human_review": True,
        "postprocess": {"type": "hard_nms", "class_agnostic": True, "iou_threshold": 0.72},
        "operating_points_validation_iou50": {
            "precision_90": {
                "score_threshold": BUILDER.DECISION_THRESHOLD,
                "precision_iou50": 0.9009009009009009,
                "recall_iou50": 0.22586109542631283,
            }
        },
        "validation_metrics": {
            "AP": 0.2967751100548477,
            "AP50": 0.4775844593080958,
            "AP75": 0.28621422418385917,
        },
        "validation_tuned_warning": "Optimistic development-validation estimate.",
        "threshold_gate": {"AP": 0.40, "AP50": 0.65, "passed": False},
        "weight_average": {"epochs": [19, 24, 29], "weights": [0.25, 0.5, 0.25]},
    }


class VehicleDamageRtDetrBundleTest(unittest.TestCase):
    def test_approved_public_policy_is_accepted(self) -> None:
        with tempfile.TemporaryDirectory() as directory:
            path = Path(directory) / "selected_inference_policy.json"
            path.write_text(json.dumps(_policy()), encoding="utf-8")
            validated = BUILDER.validate_policy(path)
        self.assertEqual("precision_90", validated["default_operating_profile"])

    def test_opening_the_final_test_is_rejected(self) -> None:
        policy = _policy()
        policy["test_used"] = True
        with tempfile.TemporaryDirectory() as directory:
            path = Path(directory) / "selected_inference_policy.json"
            path.write_text(json.dumps(policy), encoding="utf-8")
            with self.assertRaises(RuntimeError):
                BUILDER.validate_policy(path)


if __name__ == "__main__":
    unittest.main()
