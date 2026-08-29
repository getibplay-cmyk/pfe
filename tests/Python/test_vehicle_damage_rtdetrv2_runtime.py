"""Contract tests for the SaaS RT-DETRv2-S ONNX adapter."""

from __future__ import annotations

import hashlib
import importlib.util
import json
import subprocess
import sys
import tempfile
import unittest
from pathlib import Path

import numpy as np
from PIL import Image


ROOT = Path(__file__).resolve().parents[2]
ADAPTER_PATH = (
    ROOT
    / "scripts"
    / "intelligence"
    / "vehicle_damage"
    / "run_vehicle_damage_rtdetrv2_onnx.py"
)
sys.dont_write_bytecode = True
SPEC = importlib.util.spec_from_file_location("rentfleet_vehicle_damage_rtdetrv2_runtime", ADAPTER_PATH)
if SPEC is None or SPEC.loader is None:
    raise RuntimeError("Unable to load the RT-DETRv2-S runtime adapter.")
ADAPTER = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(ADAPTER)


class _Descriptor:
    def __init__(self, name: str, tensor_type: str) -> None:
        self.name = name
        self.type = tensor_type


class _Session:
    def get_inputs(self) -> list[_Descriptor]:
        return [
            _Descriptor(ADAPTER.INPUT_IMAGES_NAME, "tensor(float)"),
            _Descriptor(ADAPTER.INPUT_SIZES_NAME, "tensor(int64)"),
        ]

    def get_outputs(self) -> list[_Descriptor]:
        return [
            _Descriptor("labels", "tensor(int64)"),
            _Descriptor("boxes", "tensor(float)"),
            _Descriptor("scores", "tensor(float)"),
        ]

    def run(self, output_names: list[str], inputs: dict[str, np.ndarray]) -> list[np.ndarray]:
        if output_names != list(ADAPTER.OUTPUT_NAMES):
            raise AssertionError("Unexpected outputs")
        images = inputs[ADAPTER.INPUT_IMAGES_NAME]
        sizes = inputs[ADAPTER.INPUT_SIZES_NAME]
        if images.shape != (1, 3, 640, 640) or images.dtype != np.float32:
            raise AssertionError("Unexpected image tensor")
        if sizes.tolist() != [[768, 768]] or sizes.dtype != np.int64:
            raise AssertionError("Unexpected original-size tensor")
        return [
            np.asarray([[0, 0, 0]], dtype=np.int64),
            np.asarray(
                [[[10.0, 10.0, 210.0, 210.0], [20.0, 20.0, 200.0, 200.0], [600.0, 500.0, 780.0, 790.0]]],
                dtype=np.float32,
            ),
            np.asarray([[0.92, 0.90, 0.84]], dtype=np.float32),
        ]


def _model_card(model_sha256: str) -> dict[str, object]:
    return {
        "model_id": ADAPTER.MODEL_CARD_ID,
        "model_name": ADAPTER.MODEL_NAME,
        "model_version": ADAPTER.MODEL_VERSION,
        "task": ADAPTER.MODEL_TASK,
        "architecture": ADAPTER.MODEL_ARCHITECTURE,
        "classes": {"0": "dommage_visible"},
        "onnx_sha256": model_sha256,
        "decision_threshold": ADAPTER.DECISION_THRESHOLD,
        "input": {
            "images_name": "images",
            "orig_target_sizes_name": "orig_target_sizes",
            "color": "RGB",
            "resize": 640,
            "normalization": "zero_one",
        },
        "outputs": ["labels", "boxes", "scores"],
        "postprocess": {
            "type": "hard_nms",
            "class_agnostic": True,
            "iou_threshold": 0.72,
            "max_candidates": 12,
        },
        "source_checkpoint": {
            "filename": ADAPTER.SOURCE_CHECKPOINT,
            "sha256": ADAPTER.SOURCE_CHECKPOINT_SHA256,
            "epochs": [19, 24, 29],
            "weights": [0.25, 0.5, 0.25],
        },
        "validation": {
            "AP": 0.2967751101,
            "AP50": 0.4775844593,
            "AP75": 0.2862142242,
            "operating_profile": "precision_90",
            "precision_iou50": 0.9009009009,
            "recall_iou50": 0.2258610954,
            "tuned_on_validation": True,
        },
        "scientific_gate": {"AP": 0.40, "AP50": 0.65, "passed": False},
        "safety": {
            "human_review_required": True,
            "automatic_business_action_allowed": False,
            "final_test_sealed": True,
            "calibration_used": False,
            "test_used": False,
            "local_pilot_required": True,
        },
    }


class VehicleDamageRtDetrRuntimeTest(unittest.TestCase):
    def test_model_card_closes_checkpoint_export_and_safety_contract(self) -> None:
        model_sha256 = "a" * 64
        with tempfile.TemporaryDirectory() as directory:
            path = Path(directory) / "model_card.json"
            path.write_text(json.dumps(_model_card(model_sha256)), encoding="utf-8")
            card_sha256 = hashlib.sha256(path.read_bytes()).hexdigest()
            card = ADAPTER.validate_model_card(path, card_sha256, model_sha256)
        self.assertFalse(card["scientific_gate"]["passed"])
        self.assertFalse(card["safety"]["test_used"])

    def test_quality_abstains_on_flat_image_and_accepts_detailed_image(self) -> None:
        flat = Image.new("RGB", (768, 768), color=(128, 128, 128))
        _, flat_reasons = ADAPTER.quality_assessment(flat)
        self.assertIn("LOW_CONTRAST", flat_reasons)
        self.assertIn("POSSIBLY_BLURRED", flat_reasons)

        checker = np.indices((768, 768)).sum(axis=0) % 2
        detailed = Image.fromarray(
            np.repeat((checker * 255).astype(np.uint8)[:, :, None], 3, axis=2),
            mode="RGB",
        )
        metrics, reasons = ADAPTER.quality_assessment(detailed)
        self.assertEqual([], reasons)
        self.assertGreater(metrics["contrast"], 0.40)

    def test_full_image_inference_nms_clamping_and_safety_contract(self) -> None:
        checker = np.indices((768, 768)).sum(axis=0) % 2
        image = Image.fromarray(
            np.repeat((checker * 255).astype(np.uint8)[:, :, None], 3, axis=2),
            mode="RGB",
        )
        labels, boxes, scores = ADAPTER.infer_detections(_Session(), image)
        self.assertEqual([0, 0, 0], labels.tolist())
        detections = ADAPTER.valid_ranked_detections(boxes, scores, 768, 768)
        payload = ADAPTER.make_payload(
            "11111111-1111-4111-8111-111111111111",
            {"sha256": "a" * 64, "bytes": 100, "width": 768, "height": 768},
            "b" * 64,
            "c" * 64,
            {"brightness": 0.5, "contrast": 0.5, "sharpness": 1.0},
            [],
            detections,
        )

        self.assertTrue(payload["result"]["suggested_damage"])
        self.assertAlmostEqual(0.92, payload["result"]["max_probability_damage"], places=6)
        self.assertEqual(2, payload["result"]["candidate_count"])
        self.assertEqual(168, payload["result"]["candidate_regions"][1]["width"])
        self.assertEqual("full_image_rtdetrv2_s", payload["scan"]["mode"])
        self.assertEqual(1, payload["scan"]["evaluated_patches"])
        self.assertTrue(payload["safety"]["human_validation_required"])
        self.assertFalse(payload["safety"]["automatic_business_action_allowed"])
        self.assertFalse(payload["safety"]["pixel_precise_localization"])
        json.dumps(payload, allow_nan=False)

    def test_subthreshold_output_is_negative_without_candidate(self) -> None:
        detections = [(0.80, (10.0, 10.0, 200.0, 200.0))]
        payload = ADAPTER.make_payload(
            "11111111-1111-4111-8111-111111111111",
            {"sha256": "a" * 64, "bytes": 100, "width": 768, "height": 768},
            "b" * 64,
            "c" * 64,
            {"brightness": 0.5, "contrast": 0.5, "sharpness": 1.0},
            [],
            detections,
        )
        self.assertFalse(payload["result"]["suggested_damage"])
        self.assertEqual([], payload["result"]["candidate_regions"])

    def test_quality_abstention_never_emits_a_prediction(self) -> None:
        payload = ADAPTER.make_payload(
            "11111111-1111-4111-8111-111111111111",
            {"sha256": "a" * 64, "bytes": 100, "width": 768, "height": 768},
            "b" * 64,
            "c" * 64,
            {"brightness": 0.5, "contrast": 0.0, "sharpness": 0.0},
            ["LOW_CONTRAST", "POSSIBLY_BLURRED"],
            [],
        )
        self.assertEqual("abstained", payload["quality"]["status"])
        self.assertEqual(0, payload["scan"]["evaluated_patches"])
        self.assertIsNone(payload["result"]["suggested_damage"])
        self.assertIsNone(payload["result"]["max_probability_damage"])

    def test_cli_failure_is_sanitized_and_does_not_echo_private_paths(self) -> None:
        result = subprocess.run(
            [
                sys.executable,
                str(ADAPTER_PATH),
                "--run-id",
                "11111111-1111-4111-8111-111111111111",
                "--image",
                "/private/customer/return-photo.jpg",
                "--model",
                "/private/models/model.onnx",
                "--model-card",
                "/private/models/model_card.json",
                "--model-sha256",
                "a" * 64,
                "--model-card-sha256",
                "b" * 64,
                "--input-sha256",
                "c" * 64,
                "--input-bytes",
                "100",
                "--input-width",
                "768",
                "--input-height",
                "768",
                "--max-patches",
                "1",
                "--stdout",
            ],
            cwd=Path(tempfile.gettempdir()),
            capture_output=True,
            text=True,
            timeout=10,
            check=False,
        )
        self.assertEqual(2, result.returncode)
        self.assertEqual("", result.stdout)
        self.assertEqual({"error": "DAMAGE_RUNTIME_CONTRACT_FAILED"}, json.loads(result.stderr))
        self.assertNotIn("/private/", result.stderr)


if __name__ == "__main__":
    unittest.main()
