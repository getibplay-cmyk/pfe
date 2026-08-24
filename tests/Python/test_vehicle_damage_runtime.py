"""Contract tests for the SaaS vehicle-damage ONNX adapter."""

from __future__ import annotations

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
    ROOT / "scripts" / "intelligence" / "vehicle_damage" / "run_vehicle_damage_onnx.py"
)
sys.dont_write_bytecode = True
SPEC = importlib.util.spec_from_file_location("rentfleet_vehicle_damage_runtime", ADAPTER_PATH)
if SPEC is None or SPEC.loader is None:
    raise RuntimeError("Unable to load the vehicle-damage runtime adapter.")
ADAPTER = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(ADAPTER)


class _Descriptor:
    def __init__(self, name: str) -> None:
        self.name = name
        self.type = "tensor(float)"


class _Session:
    def get_inputs(self) -> list[_Descriptor]:
        return [_Descriptor(ADAPTER.INPUT_NAME)]

    def get_outputs(self) -> list[_Descriptor]:
        return [_Descriptor(ADAPTER.OUTPUT_NAME)]

    def run(self, output_names: list[str], inputs: dict[str, np.ndarray]) -> list[np.ndarray]:
        if output_names != [ADAPTER.OUTPUT_NAME]:
            raise AssertionError("Unexpected outputs")
        batch = inputs[ADAPTER.INPUT_NAME]
        if batch.ndim != 4 or batch.shape[1:] != (3, 384, 384):
            raise AssertionError("Unexpected input tensor")
        return [np.linspace(0.20, 0.91, num=batch.shape[0], dtype=np.float32)]


class VehicleDamageRuntimeTest(unittest.TestCase):
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
        self.assertGreater(metrics["sharpness"], 0.50)

    def test_windows_are_deterministic_overlapping_and_bounded(self) -> None:
        first = ADAPTER.build_windows(1600, 1200, 36)
        second = ADAPTER.build_windows(1600, 1200, 36)
        self.assertEqual(first, second)
        self.assertEqual(36, len(first))
        for x, y, width, height in first:
            self.assertGreaterEqual(width, 384)
            self.assertEqual(width, height)
            self.assertLessEqual(x + width, 1600)
            self.assertLessEqual(y + height, 1200)

    def test_preprocessing_inference_regions_and_safety_contract(self) -> None:
        checker = np.indices((768, 768)).sum(axis=0) % 2
        image = Image.fromarray(
            np.repeat((checker * 255).astype(np.uint8)[:, :, None], 3, axis=2),
            mode="RGB",
        )
        windows = [(0, 0, 384, 384), (384, 0, 384, 384), (0, 384, 384, 384)]
        probabilities = ADAPTER.infer_probabilities(_Session(), image, windows)
        payload = ADAPTER.make_payload(
            "11111111-1111-4111-8111-111111111111",
            {"sha256": "a" * 64, "bytes": 100, "width": 768, "height": 768},
            "b" * 64,
            "c" * 64,
            {"brightness": 0.5, "contrast": 0.5, "sharpness": 1.0},
            [],
            windows,
            probabilities,
        )

        self.assertTrue(payload["result"]["suggested_damage"])
        self.assertAlmostEqual(0.91, payload["result"]["max_probability_damage"], places=6)
        self.assertGreaterEqual(payload["result"]["candidate_count"], 1)
        self.assertEqual("coarse_overlapping_patches", payload["scan"]["mode"])
        self.assertTrue(payload["safety"]["human_validation_required"])
        self.assertFalse(payload["safety"]["automatic_business_action_allowed"])
        self.assertFalse(payload["safety"]["pixel_precise_localization"])
        self.assertEqual("NO_OPERATIONAL_ACTION", payload["safety"]["operational_effect"])
        json.dumps(payload, allow_nan=False)

    def test_quality_abstention_never_runs_or_emits_a_prediction(self) -> None:
        payload = ADAPTER.make_payload(
            "11111111-1111-4111-8111-111111111111",
            {"sha256": "a" * 64, "bytes": 100, "width": 768, "height": 768},
            "b" * 64,
            "c" * 64,
            {"brightness": 0.5, "contrast": 0.0, "sharpness": 0.0},
            ["LOW_CONTRAST", "POSSIBLY_BLURRED"],
            [],
            np.empty((0,), dtype=np.float64),
        )
        self.assertEqual("abstained", payload["quality"]["status"])
        self.assertEqual(0, payload["scan"]["evaluated_patches"])
        self.assertIsNone(payload["result"]["suggested_damage"])
        self.assertIsNone(payload["result"]["max_probability_damage"])
        self.assertEqual([], payload["result"]["candidate_regions"])

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
