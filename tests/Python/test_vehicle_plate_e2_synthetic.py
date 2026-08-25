from __future__ import annotations

import csv
import hashlib
import tempfile
import unittest
from types import SimpleNamespace
from pathlib import Path
from unittest import mock

import scripts.intelligence.vehicle_plate.e2_synthetic as e2_module
from scripts.intelligence.vehicle_plate.e2_synthetic import (
    InferencePrediction,
    evaluate_predictions,
    load_synthetic_rows,
    parse_inference_predictions,
    select_candidate,
)
from scripts.intelligence.vehicle_plate.generate_synthetic_dataset import (
    DEFAULT_SERIES,
    FontProvenance,
    LatinFontProvenance,
    _materialize_dataset,
    build_sample_plan,
)
from scripts.intelligence.vehicle_plate.protocol import REQUIRED_COLUMNS, ProtocolError


def _row(
    *,
    sample_id: str,
    target: str,
    split: str = "validation",
    format_profile: str = "legacy_arabic",
    series_latin: str = "",
) -> dict[str, str]:
    serial, series_arabic, region = target.split("|")
    recognition_text = (
        f"MA{serial}{series_arabic}{series_latin}{region}"
        if format_profile == "unified_2026_arabic_latin"
        else f"{serial}{series_arabic}{region}"
    )
    return {
        "sample_id": sample_id,
        "image_path": f"images/{split}/{sample_id}.png",
        "group_id": f"group-{sample_id}",
        "task": "recognition",
        "target": target,
        "source_id": "synthetic_moroccan_plate_ofl_v2",
        "source_url": "https://github.com/notofonts/arabic/releases/tag/NotoSansArabic-v2.013",
        "license_id": "SYNTHETIC-OFL-1.1",
        "license_status": "approved",
        "license_proof": "licenses/OFL.txt",
        "sha256": "0" * 64,
        "split": split,
        "holdout_role": "development",
        "recognition_text": recognition_text,
        "format_profile": format_profile,
        "series_latin": series_latin,
        "variant_id": "variant-00",
    }


class VehiclePlateE2SyntheticTest(unittest.TestCase):
    def test_parses_official_inference_result_shape(self):
        with tempfile.TemporaryDirectory() as directory:
            result = Path(directory) / "predictions.txt"
            result.write_text(
                "/content/eval/syn-a.png\t123أ45\t0.875\n",
                encoding="utf-8",
            )
            predictions = parse_inference_predictions(result)
        self.assertEqual("123أ45", predictions["syn-a"].raw_text)
        self.assertAlmostEqual(0.875, predictions["syn-a"].confidence)

    def test_metrics_use_canonical_exact_match_and_report_grammar(self):
        rows = [_row(sample_id="a", target="123|أ|45"), _row(sample_id="b", target="98|ب|7")]
        predictions = {
            "a": InferencePrediction("a", "١٢٣ أ ٤٥", 0.9),
            "b": InferencePrediction("b", "98-7", 0.5),
        }
        metrics = evaluate_predictions(
            rows, predictions, split="validation", variant_id="variant-00"
        )
        self.assertEqual(2, metrics["sample_count"])
        self.assertEqual(0.5, metrics["exact_match"])
        self.assertEqual(0.5, metrics["grammar_valid_rate"])
        self.assertGreater(float(metrics["cer"]), 0.0)
        self.assertAlmostEqual(0.7, float(metrics["mean_confidence"]))

    def test_unified_metric_requires_correct_latin_equivalent(self):
        rows = [
            _row(
                sample_id="unified",
                target="123|أ|45",
                format_profile="unified_2026_arabic_latin",
                series_latin="A",
            )
        ]
        wrong = {
            "unified": InferencePrediction("unified", "123أB45MA", 0.9)
        }
        wrong_metrics = evaluate_predictions(
            rows,
            wrong,
            split="validation",
            variant_id="variant-00",
            format_profile="unified_2026_arabic_latin",
        )
        self.assertEqual(0.0, wrong_metrics["exact_match"])
        self.assertEqual(0.0, wrong_metrics["bilingual_verified_rate"])

        correct = {
            "unified": InferencePrediction("unified", "123 أ A 45 MA", 0.9)
        }
        correct_metrics = evaluate_predictions(
            rows,
            correct,
            split="validation",
            variant_id="variant-00",
            format_profile="unified_2026_arabic_latin",
        )
        self.assertEqual(1.0, correct_metrics["exact_match"])
        self.assertEqual(1.0, correct_metrics["bilingual_verified_rate"])

    def test_selection_is_validation_exact_then_cer(self):
        incumbent = {"exact_match": 0.5, "cer": 0.2}
        better_exact = {"exact_match": 0.6, "cer": 0.3}
        better_cer = {"exact_match": 0.5, "cer": 0.1}
        worse = {"exact_match": 0.5, "cer": 0.2}
        self.assertEqual(
            "fine_tuned_synthetic_challenger",
            select_candidate(incumbent, better_exact).selected_candidate,
        )
        self.assertEqual(
            "fine_tuned_synthetic_challenger",
            select_candidate(incumbent, better_cer).selected_candidate,
        )
        self.assertEqual(
            "official_arabic_ppocrv5_incumbent",
            select_candidate(incumbent, worse).selected_candidate,
        )
        self.assertEqual(
            "official_arabic_ppocrv5_incumbent",
            select_candidate(
                incumbent,
                better_exact,
                baseline_segments=[{"exact_match": 0.8}],
                challenger_segments=[{"exact_match": 0.7}],
            ).selected_candidate,
        )

    def test_loader_rejects_test_and_real_source_before_training(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            (root / "images/test").mkdir(parents=True)
            (root / "licenses").mkdir()
            image = root / "images/test/sample.png"
            image.write_bytes(b"fixture")
            (root / "licenses/OFL.txt").write_text("SIL OFL fixture\n", encoding="utf-8")
            row = _row(sample_id="sample", target="123|أ|45", split="test")
            row["image_path"] = "images/test/sample.png"
            row["sha256"] = hashlib.sha256(b"fixture").hexdigest()
            manifest = root / "manifest.csv"
            fieldnames = list(REQUIRED_COLUMNS) + [
                "recognition_text",
                "format_profile",
                "series_latin",
                "variant_id",
            ]
            with manifest.open("w", encoding="utf-8", newline="") as handle:
                writer = csv.DictWriter(handle, fieldnames=fieldnames)
                writer.writeheader()
                writer.writerow(row)
            with self.assertRaisesRegex(ProtocolError, "test indépendant"):
                load_synthetic_rows(manifest, root)

            row["split"] = "validation"
            row["image_path"] = "images/test/sample.png"
            row["source_id"] = "moroccan_vehicle_registration_plates_cc0_v2"
            row["license_id"] = "CC0-1.0"
            row["task"] = "detection"
            with manifest.open("w", encoding="utf-8", newline="") as handle:
                writer = csv.DictWriter(handle, fieldnames=fieldnames)
                writer.writeheader()
                writer.writerow(row)
            with self.assertRaises(ProtocolError):
                load_synthetic_rows(manifest, root)

    def test_orchestrator_writes_auditable_non_qualified_bundle(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            font = root / "NotoSansArabic-SemiBold.ttf"
            latin_font = root / "NotoSans-Regular.ttf"
            license_file = root / "OFL.txt"
            latin_license_file = root / "OFL-NotoSans.txt"
            font.write_bytes(b"font-fixture")
            latin_font.write_bytes(b"latin-font-fixture")
            license_file.write_text(
                "SIL OPEN FONT LICENSE Version 1.1\n", encoding="utf-8"
            )
            latin_license_file.write_text(
                "SIL OPEN FONT LICENSE Version 1.1\n", encoding="utf-8"
            )
            provenance = FontProvenance(
                "Noto Sans Arabic",
                "SemiBold",
                "1" * 64,
                "2" * 64,
                "https://github.com/notofonts/arabic/releases/tag/NotoSansArabic-v2.013",
                "https://github.com/notofonts/arabic/releases/download/NotoSansArabic-v2.013/NotoSansArabic-v2.013.zip",
                "3" * 64,
                "test",
                "test",
                True,
            )
            latin_provenance = LatinFontProvenance(
                "Noto Sans",
                "Regular",
                "4" * 64,
                "5" * 64,
                "https://raw.githubusercontent.com/google/fonts/commit/font.ttf",
                "https://raw.githubusercontent.com/google/fonts/commit/OFL.txt",
                "a" * 40,
            )
            samples = build_sample_plan(
                seed=20260825,
                group_counts={"train": 2, "validation": 2, "calibration": 2},
                variants_per_group=2,
                series=DEFAULT_SERIES,
            )

            def renderer(sample, destination, _font_path, _latin_font_path):
                destination.parent.mkdir(parents=True, exist_ok=True)
                destination.write_bytes(b"png-fixture\0" + sample.sample_id.encode())

            dataset = root / "dataset"
            _materialize_dataset(
                output_dir=dataset,
                samples=samples,
                series=DEFAULT_SERIES,
                seed=20260825,
                group_counts={"train": 2, "validation": 2, "calibration": 2},
                variants_per_group=2,
                font_path=font,
                license_path=license_file,
                provenance=provenance,
                latin_font_path=latin_font,
                latin_license_path=latin_license_file,
                latin_provenance=latin_provenance,
                renderer=renderer,
            )
            rows = load_synthetic_rows(dataset / "manifest.csv", dataset)

            paddleocr = root / "PaddleOCR"
            config = paddleocr / e2_module.EXPECTED_CONFIG
            dictionary = paddleocr / e2_module.EXPECTED_DICTIONARY
            config.parent.mkdir(parents=True)
            dictionary.parent.mkdir(parents=True)
            config.write_text("Global: {}\n", encoding="utf-8")
            dictionary_characters = tuple(
                dict.fromkeys(
                    tuple("0123456789MA")
                    + tuple(e2_module.OFFICIAL_SERIES_MAPPING)
                    + tuple(e2_module.OFFICIAL_SERIES_MAPPING.values())
                )
            )
            dictionary.write_text(
                "".join(f"{character}\n" for character in dictionary_characters),
                encoding="utf-8",
            )
            fake_python = root / "venv/bin/python"
            fake_python.parent.mkdir(parents=True)
            fake_python.write_text("fixture", encoding="utf-8")
            pretrained_prefix = root / "official-pretrained"
            pretrained_prefix.with_suffix(".pdparams").write_bytes(b"incumbent")
            output = root / "output"

            def fake_stage(command, *, cwd, environment, log_path, announce):
                del cwd, environment
                log_path.parent.mkdir(parents=True, exist_ok=True)
                log_path.write_text(f"{announce} complete\n", encoding="utf-8")
                if announce == "fine_tuning":
                    save_argument = next(
                        item for item in command if item.startswith("Global.save_model_dir=")
                    )
                    training = Path(save_argument.split("=", 1)[1])
                    training.mkdir(parents=True)
                    (training / "best_accuracy.pdparams").write_bytes(b"challenger")
                    (training / "best_accuracy.states").write_text("{}", encoding="utf-8")
                    return
                prediction = log_path.parent.parent / "predictions" / f"{announce}.txt"
                split = "calibration" if "calibration" in announce else "validation"
                selected = [
                    row
                    for row in rows
                    if row["split"] == split
                    and (
                        announce == "tuned_validation_all_variants"
                        or row["variant_id"] == "variant-00"
                    )
                ]
                lines = []
                for row in selected:
                    text = row["recognition_text"] if announce.startswith("tuned") else "1-2"
                    lines.append(f"/eval/{row['sample_id']}.png\t{text}\t0.9\n")
                prediction.write_text("".join(lines), encoding="utf-8")

            with (
                mock.patch.object(e2_module, "_run_logged", side_effect=fake_stage),
                mock.patch.object(
                    e2_module.subprocess,
                    "check_output",
                    return_value="Pillow==fixture\n",
                ),
                mock.patch.object(
                    e2_module.subprocess,
                    "run",
                    return_value=SimpleNamespace(returncode=1, stdout=""),
                ),
            ):
                report = e2_module.run_e2(
                    python_executable=fake_python,
                    paddleocr_dir=paddleocr,
                    dataset_dir=dataset,
                    output_dir=output,
                    pretrained_prefix=pretrained_prefix,
                    repository_sha="a" * 40,
                    paddleocr_sha=e2_module.EXPECTED_PADDLEOCR_SHA,
                    epochs=1,
                    batch_size=1,
                    seed=20260825,
                )

            self.assertEqual("synthetic_e2_complete_not_qualified", report["status"])
            self.assertFalse(report["qualification_claim"])
            self.assertFalse(report["final_test_opened"])
            self.assertFalse(report["saas_integration_allowed"])
            self.assertEqual(
                "fine_tuned_synthetic_challenger",
                report["selection"]["selected_candidate"],
            )
            self.assertTrue((output / "SHA256SUMS").is_file())
            self.assertTrue((output / "model/best_accuracy.pdparams").is_file())
            self.assertTrue(
                (
                    output
                    / "model/official_arabic_PP-OCRv5_mobile_rec_pretrained.pdparams"
                ).is_file()
            )
            self.assertFalse(any(path.name == "test" for path in output.rglob("*")))


if __name__ == "__main__":
    unittest.main()
