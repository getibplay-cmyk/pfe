"""Compatibility and artifact-closure tests for the RT-DETRv2-S exporter."""

from __future__ import annotations

import importlib.util
import subprocess
import sys
import tempfile
import types
import unittest
from pathlib import Path
from unittest import mock


ROOT = Path(__file__).resolve().parents[2]
EXPORTER_PATH = (
    ROOT
    / "scripts"
    / "intelligence"
    / "vehicle_damage"
    / "export_rtdetrv2_s_onnx.py"
)
sys.dont_write_bytecode = True
SPEC = importlib.util.spec_from_file_location("rentfleet_rtdetrv2_export", EXPORTER_PATH)
if SPEC is None or SPEC.loader is None:
    raise RuntimeError("Unable to load the RT-DETRv2-S exporter.")
EXPORTER = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(EXPORTER)


class VehicleDamageRtDetrExportTest(unittest.TestCase):
    def test_export_environment_supports_trusted_pytorch_checkpoint_loading(self) -> None:
        completed = subprocess.CompletedProcess(["true"], 0, stdout="", stderr="")
        with mock.patch.object(EXPORTER.subprocess, "run", return_value=completed) as invoked:
            EXPORTER.run(["true"], ROOT)

        environment = invoked.call_args.kwargs["env"]
        self.assertEqual("1", environment["TORCH_FORCE_NO_WEIGHTS_ONLY_LOAD"])
        self.assertEqual("", environment["CUDA_VISIBLE_DEVICES"])

    def test_external_data_is_materialized_as_one_atomic_onnx_file(self) -> None:
        model = types.SimpleNamespace(
            graph=types.SimpleNamespace(
                initializer=[types.SimpleNamespace(external_data=[])],
            )
        )
        loads: list[tuple[str, bool]] = []
        saves: list[bool] = []

        def load(path: str, *, load_external_data: bool) -> object:
            loads.append((path, load_external_data))
            return model

        def save_model(value: object, path: str, *, save_as_external_data: bool) -> None:
            self.assertIs(model, value)
            saves.append(save_as_external_data)
            Path(path).write_bytes(b"closed-onnx")

        fake_onnx = types.SimpleNamespace(
            checker=types.SimpleNamespace(check_model=lambda value: self.assertIs(model, value)),
            load=load,
            save_model=save_model,
        )

        with tempfile.TemporaryDirectory() as directory:
            raw = Path(directory) / "raw.onnx"
            output = Path(directory) / "model.onnx"
            raw.write_bytes(b"raw-onnx")
            with mock.patch.dict(sys.modules, {"onnx": fake_onnx}):
                EXPORTER.materialize_single_file_onnx(raw, output)

            self.assertEqual(b"closed-onnx", output.read_bytes())

        self.assertEqual([True, False], [external for _, external in loads])
        self.assertEqual([False], saves)

    def test_colab_and_runtime_pins_share_a_numpy_2_2_environment(self) -> None:
        colab = (ROOT / "scripts/intelligence/requirements-vehicle-damage-colab.txt").read_text()
        runtime = (ROOT / "scripts/intelligence/requirements-vehicle-damage-runtime.txt").read_text()
        self.assertIn("onnxscript==0.6.2", colab)
        self.assertIn("numpy==2.2.6", colab)
        self.assertIn("numpy==2.2.6", runtime)


if __name__ == "__main__":
    unittest.main()
