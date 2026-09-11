"""Protocol and real small CPU-training regressions, using synthetic fixtures only."""
import copy
import hashlib
import importlib.util
import json
import sys
import tempfile
import unittest
from datetime import date, timedelta
from pathlib import Path
from unittest import mock

ROOT = Path(__file__).resolve().parents[2]
sys.path.insert(0, str(ROOT / "scripts/intelligence/training"))
sys.path.insert(0, str(ROOT / "scripts/intelligence"))
import workbench


def digest(value):
    return hashlib.sha256(value.encode()).hexdigest()


def demand_manifest():
    rows = [{"key": digest(str(i)), "group": digest("series"), "date": str(date(2025, 1, 1) + timedelta(days=i)), "value": 2 + i % 7, "split": "train" if i < 144 else ("validation" if i < 192 else "test")} for i in range(240)]
    return {"schema_version": "1.0", "campaign_id": "synthetic-campaign", "family": "demand", "baseline_version": "j5-v1", "seed": 42, "counts": {"train": 144, "validation": 48, "test": 48}, "rows": rows}


def save_envelope(folder, manifest):
    raw = json.dumps(manifest, ensure_ascii=False, separators=(",", ":"))
    path = Path(folder) / "campaign.json"
    path.write_text(json.dumps({"manifest_json": raw, "manifest_sha256": digest(raw)}))
    return path


class TrainingWorkbenchTest(unittest.TestCase):
    def test_manifest_integrity_is_verified_before_processing(self):
        with tempfile.TemporaryDirectory() as tmp:
            path = save_envelope(tmp, demand_manifest())
            payload = json.loads(path.read_text())
            payload["manifest_json"] = payload["manifest_json"].replace('"value":2,', '"value":8,', 1)
            path.write_text(json.dumps(payload))
            with self.assertRaisesRegex(ValueError, "digest"):
                workbench.load_campaign(path)

    def test_global_chronology_is_required_across_series(self):
        manifest = demand_manifest()
        manifest["rows"][230]["split"] = "train"
        with tempfile.TemporaryDirectory() as tmp:
            with self.assertRaisesRegex(ValueError, "Temporal leakage"):
                workbench.load_campaign(save_envelope(tmp, manifest))

    def test_missing_dates_are_rejected_instead_of_invented(self):
        manifest = demand_manifest()
        manifest["rows"].pop(20)
        with tempfile.TemporaryDirectory() as tmp:
            with self.assertRaisesRegex(ValueError, "Missing dates"):
                workbench.load_campaign(save_envelope(tmp, manifest))

    def test_vision_groups_cannot_cross_partitions(self):
        manifest = demand_manifest()
        manifest["family"] = "color"
        with tempfile.TemporaryDirectory() as tmp:
            with self.assertRaisesRegex(ValueError, "Group leakage"):
                workbench.load_campaign(save_envelope(tmp, manifest))

    def test_duplicate_image_is_rejected_even_under_another_key(self):
        manifest = demand_manifest()
        manifest["family"] = "color"
        for row in manifest["rows"]:
            row["group"] = digest(row["key"])
            row["image_sha256"] = digest("same photo")
        with tempfile.TemporaryDirectory() as tmp:
            with self.assertRaisesRegex(ValueError, "Duplicate image"):
                workbench.load_campaign(save_envelope(tmp, manifest))

    def test_unsafe_manifest_paths_are_refused(self):
        manifest = demand_manifest()
        manifest["rows"][0]["key"] = "../../image"
        with tempfile.TemporaryDirectory() as tmp:
            with self.assertRaisesRegex(ValueError, "Unsafe sample"):
                workbench.load_campaign(save_envelope(tmp, manifest))

    def test_forecast_features_never_read_target_or_future_observations(self):
        import run_demand_forecast as runtime
        manifest = demand_manifest()
        first = workbench.demand_examples(manifest, runtime.make_feature_row)
        modified = copy.deepcopy(manifest)
        modified["rows"][-1]["value"] = 9999
        second = workbench.demand_examples(modified, runtime.make_feature_row)
        for horizon in range(1, 8):
            self.assertTrue(first[horizon]["test"][-1][1].equals(second[horizon]["test"][-1][1]))
            self.assertNotEqual(first[horizon]["test"][-1][2], second[horizon]["test"][-1][2])

    def test_comparison_requires_exactly_the_same_test_observations(self):
        with tempfile.TemporaryDirectory() as tmp:
            manifest = workbench.load_campaign(save_envelope(tmp, demand_manifest()))
            artifact = Path(tmp) / "fixture.txt"
            artifact.write_text("synthetic artifact")
            before = {r["key"]: [1] * 7 for r in manifest["rows"] if r["split"] == "test"}
            after = dict(before)
            after.pop(next(iter(after)))
            with self.assertRaisesRegex(ValueError, "exactly"):
                workbench.report(manifest, before, after, artifact, "candidate-v2", Path(tmp) / "report.json")

    def test_private_results_cannot_be_silently_overwritten(self):
        with tempfile.TemporaryDirectory() as tmp:
            path = Path(tmp) / "result.json"
            workbench.write_json(path, {"first": True})
            with self.assertRaises(FileExistsError):
                workbench.write_json(path, {"first": False})

    def test_small_real_hgb_training_exports_all_horizons_and_quantiles(self):
        import numpy as np
        import pandas as pd
        import joblib
        import run_demand_forecast as runtime
        from sklearn.dummy import DummyRegressor
        from threadpoolctl import threadpool_limits
        manifest = demand_manifest()
        columns = ["lag_1_at_cutoff", "seasonal_lag_target_minus_7", "rolling_mean_7_at_cutoff"]
        dummy = DummyRegressor(strategy="constant", constant=2).fit(pd.DataFrame([[1, 1, 1]], columns=columns), np.asarray([2]))
        baseline = {"module": "demand_forecast_munich", "model_name": runtime.MODEL_NAME, "horizons": list(range(1, 8)), "quantiles": list(runtime.QUANTILES), "semantics": "observed_departures_not_total_demand", "feature_columns": columns, "point_models": {f"point_h{h}": dummy for h in range(1, 8)}, "legacy_public_score": "must not propagate"}
        with tempfile.TemporaryDirectory() as tmp, mock.patch.object(runtime, "load_bundle", return_value=baseline), threadpool_limits(limits=1):
            manifest = workbench.load_campaign(save_envelope(tmp, manifest))
            artifact = workbench.train_demand(manifest, Path(tmp) / "mock-qualified-baseline", Path(tmp) / "run", "test-candidate-v2")
            candidate = joblib.load(artifact)  # Our own synthetic test artifact, never an uploaded file.
            self.assertFalse(candidate["ready_for_saas"])
            self.assertNotIn("legacy_public_score", candidate)
            self.assertEqual(len(candidate["point_models"]), 7)
            self.assertEqual(len(candidate["quantile_models"]), 28)
            report = json.loads((artifact.parent / "report.json").read_text())
            self.assertEqual(len(report["predictions"]), 48)
            self.assertTrue(all(len(r["candidate"]) == 7 for r in report["predictions"]))
            self.assertEqual(report["artifact_sha256"], workbench.sha256(artifact))

    def test_anomaly_training_uses_frozen_training_statistics(self):
        from threadpoolctl import threadpool_limits
        manifest = demand_manifest()
        manifest["family"] = "anomaly"
        manifest["baseline_version"] = "robust_mad_top2::calibration-train-v1"
        for i, row in enumerate(manifest["rows"]):
            row.update({"group": digest(str(i)), "late_hours": 15 if i % 5 == 0 else 0, "km_per_day": 400 if i % 5 == 0 else 40, "fuel_drop_pct": 70 if i % 5 == 0 else 10, "label": int(i % 5 == 0)})
        with tempfile.TemporaryDirectory() as tmp, threadpool_limits(limits=1):
            manifest = workbench.load_campaign(save_envelope(tmp, manifest))
            artifact = workbench.train_anomaly(manifest, Path(tmp) / "run", "test-anomaly-v2")
            receipt = json.loads((artifact.parent / "recalibration.json").read_text())
            self.assertEqual(receipt["center"], [0, 40, 10])
            self.assertEqual(len(json.loads((artifact.parent / "report.json").read_text())["predictions"]), 48)

    def test_generated_notebook_is_clean_and_all_cells_compile(self):
        spec = importlib.util.spec_from_file_location("training_notebook_builder", ROOT / "scripts/intelligence/training/build_notebook.py")
        builder = importlib.util.module_from_spec(spec)
        spec.loader.exec_module(builder)
        notebook = builder.build()
        self.assertEqual(notebook, json.loads((ROOT / "notebooks/model_retraining_workbench.ipynb").read_text()))
        for i, cell in enumerate(notebook["cells"]):
            self.assertIn("id", cell)
            if cell["cell_type"] == "code":
                self.assertEqual(cell["outputs"], [])
                self.assertIsNone(cell["execution_count"])
                compile("".join(cell["source"]), f"cell-{i}", "exec")


@unittest.skipUnless(importlib.util.find_spec("PIL"), "Vision preprocessing is tested in the image runtime job")
class TrainingVisionPreparationTest(unittest.TestCase):
    def test_prepared_images_strip_metadata_and_preserve_verified_coco_boxes(self):
        from PIL import Image
        with tempfile.TemporaryDirectory() as tmp:
            root = Path(tmp)
            source = root / "source.png"
            image = Image.new("RGB", (120, 80), "red")
            exif = Image.Exif()
            exif[270] = "private metadata"
            image.save(source, exif=exif)
            image_hash = workbench.sha256(source)
            source.rename(root / (image_hash + ".png"))
            row = {"key": digest("image"), "group": digest("vehicle"), "split": "train", "image_sha256": image_hash, "boxes": [{"x": .25, "y": .25, "w": .5, "h": .5}]}
            paths = workbench.prepare_vision({"family": "damage", "rows": [row]}, root, root / "prepared")
            with Image.open(paths[row["key"]]) as result:
                self.assertEqual(len(result.getexif()), 0)
                self.assertEqual(result.size, (120, 80))
            coco = json.loads((root / "prepared/train/annotations.json").read_text())
            self.assertEqual(coco["annotations"][0]["bbox"], [30, 20, 60, 40])

    def test_image_digest_mismatch_is_blocked_before_training(self):
        from PIL import Image
        with tempfile.TemporaryDirectory() as tmp:
            root = Path(tmp)
            fake_hash = digest("not these bytes")
            Image.new("RGB", (40, 40), "red").save(root / (fake_hash + ".png"))
            row = {"key": digest("image"), "group": digest("vehicle"), "split": "test", "image_sha256": fake_hash, "label": "red"}
            with self.assertRaisesRegex(ValueError, "digest"):
                workbench.prepare_vision({"family": "color", "rows": [row]}, root, root / "prepared")


if __name__ == '__main__':
    unittest.main()
