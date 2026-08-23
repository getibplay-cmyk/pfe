"""Contract tests for the SaaS-invoked colour-v8 ONNX adapter."""

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
ADAPTER_PATH = ROOT / "scripts" / "intelligence" / "color_v8" / "run_color_v8_onnx.py"
sys.dont_write_bytecode = True
SPEC = importlib.util.spec_from_file_location("rentfleet_color_v8_runtime", ADAPTER_PATH)
if SPEC is None or SPEC.loader is None:
    raise RuntimeError("Unable to load the colour-v8 runtime adapter.")
ADAPTER = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(ADAPTER)


class _Descriptor:
    def __init__(self, name: str, data_type: str = "tensor(float)") -> None:
        self.name = name
        self.type = data_type


class _Session:
    def __init__(self, values: list[np.ndarray]) -> None:
        self.values = values

    def get_inputs(self) -> list[_Descriptor]:
        return [_Descriptor(ADAPTER.INPUT_NAME)]

    def get_outputs(self) -> list[_Descriptor]:
        types = ("tensor(float)", "tensor(int64)", "tensor(float)", "tensor(bool)")
        return [_Descriptor(name, data_type) for name, data_type in zip(ADAPTER.OUTPUT_NAMES, types)]

    def run(self, output_names: list[str], inputs: dict[str, np.ndarray]) -> list[np.ndarray]:
        if output_names != list(ADAPTER.OUTPUT_NAMES):
            raise AssertionError("Unexpected output names")
        if inputs[ADAPTER.INPUT_NAME].shape != (1, 3, 224, 224):
            raise AssertionError("Unexpected input tensor")
        return self.values


class ColorV8RuntimeTest(unittest.TestCase):
    def test_preprocessing_matches_frozen_bicubic_square_contract(self) -> None:
        with tempfile.TemporaryDirectory(prefix="rentfleet-color-v8-") as temporary:
            image_path = Path(temporary) / "vehicle.png"
            Image.new("RGB", (320, 180), color=(255, 0, 0)).save(image_path, format="PNG")
            tensor, identity = ADAPTER.load_image(image_path)

        self.assertEqual((1, 3, 224, 224), tensor.shape)
        self.assertEqual(np.float32, tensor.dtype)
        self.assertTrue(np.isfinite(tensor).all())
        self.assertAlmostEqual((1.0 - 0.485) / 0.229, float(tensor[0, 0, 100, 100]), places=5)
        self.assertAlmostEqual((0.0 - 0.456) / 0.224, float(tensor[0, 1, 100, 100]), places=5)
        self.assertAlmostEqual((0.0 - 0.406) / 0.225, float(tensor[0, 2, 100, 100]), places=5)
        self.assertEqual("image/png", identity["mime"])
        self.assertEqual(64, len(identity["sha256"]))
        self.assertGreater(identity["bytes"], 0)

    def test_closed_result_contract_recomputes_acceptance_and_safety(self) -> None:
        probabilities = np.asarray([[0.98, 0.003, 0.003, 0.003, 0.002, 0.002, 0.002, 0.002, 0.003]])
        session = _Session(
            [
                probabilities,
                np.asarray([0], dtype=np.int64),
                np.asarray([0.98], dtype=np.float32),
                np.asarray([True]),
            ]
        )
        values, index, confidence, accepted = ADAPTER.run_inference(
            session,
            np.zeros((1, 3, 224, 224), dtype=np.float32),
        )
        payload = ADAPTER.make_payload(
            values,
            index,
            confidence,
            accepted,
            {"sha256": "a" * 64, "bytes": 100, "mime": "image/png"},
        )

        self.assertEqual("black", payload["result"]["suggested_color"])
        self.assertTrue(payload["result"]["accepted"])
        self.assertEqual(list(ADAPTER.CLASSES), list(payload["result"]["probabilities"]))
        self.assertTrue(payload["safety"]["human_validation_required"])
        self.assertFalse(payload["safety"]["automatic_business_action_allowed"])
        self.assertEqual("NO_OPERATIONAL_ACTION", payload["safety"]["operational_effect"])
        json.dumps(payload, allow_nan=False)

    def test_adapter_rejects_a_forged_acceptance_from_the_onnx_boundary(self) -> None:
        probabilities = np.asarray([[0.50, 0.05, 0.05, 0.05, 0.05, 0.05, 0.05, 0.05, 0.15]])
        session = _Session(
            [
                probabilities,
                np.asarray([0], dtype=np.int64),
                np.asarray([0.50], dtype=np.float32),
                np.asarray([True]),
            ]
        )
        with self.assertRaisesRegex(ADAPTER.RuntimeContractError, "Acceptance policy mismatch"):
            ADAPTER.run_inference(session, np.zeros((1, 3, 224, 224), dtype=np.float32))

    def test_cli_failure_is_sanitized_and_does_not_echo_private_paths(self) -> None:
        result = subprocess.run(
            [
                sys.executable,
                str(ADAPTER_PATH),
                "--image",
                "/private/customer/secret-vehicle.png",
                "--model",
                "/private/models/secret.onnx",
                "--metadata",
                "/private/models/secret.json",
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
        self.assertEqual({"error": "COLOR_RUNTIME_CONTRACT_FAILED"}, json.loads(result.stderr))
        self.assertNotIn("/private/", result.stderr)


if __name__ == "__main__":
    unittest.main()
