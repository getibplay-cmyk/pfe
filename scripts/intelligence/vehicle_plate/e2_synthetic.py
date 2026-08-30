#!/usr/bin/env python3
"""Run the synthetic-only E2 PaddleOCR recognizer experiment.

The experiment fine-tunes the official Arabic PP-OCRv5 recognizer on the
deterministic OFL synthetic development set. It never reads a real photograph
or the independent test split and can never qualify SaaS integration.
"""

from __future__ import annotations

import argparse
import csv
import importlib.metadata
import json
import os
import platform
import shutil
import statistics
import subprocess
import sys
import tempfile
from dataclasses import dataclass
from pathlib import Path
from typing import Any, Mapping, Sequence


REPOSITORY_ROOT = Path(__file__).resolve().parents[3]
if str(REPOSITORY_ROOT) not in sys.path:
    sys.path.insert(0, str(REPOSITORY_ROOT))

from scripts.intelligence.vehicle_plate.protocol import (
    ProtocolError,
    character_error_rate,
    file_sha256,
    load_manifest,
    normalize_ocr_text,
    parse_plate_text,
    sha256sum_lines,
    validate_manifest,
    verify_manifest_files,
)
from scripts.intelligence.vehicle_plate.generate_synthetic_dataset import (
    OFFICIAL_SERIES_MAPPING,
)


E2_VERSION = "1.2.0"
EXPECTED_SOURCE_ID = "synthetic_moroccan_plate_ofl_v2"
EXPECTED_PADDLEOCR_SHA = "b03f46425e8ff4442b268ce449e3eef758146cd4"
EXPECTED_CONFIG = Path(
    "configs/rec/PP-OCRv5/multi_language/arabic_PP-OCRv5_mobile_rec.yaml"
)
EXPECTED_DICTIONARY = Path("ppocr/utils/dict/ppocrv5_arabic_dict.txt")
OFFICIAL_PRETRAINED_URL = (
    "https://paddle-model-ecology.bj.bcebos.com/paddlex/official_pretrained_model/"
    "arabic_PP-OCRv5_mobile_rec_pretrained.pdparams"
)
DEVELOPMENT_SPLITS = frozenset({"train", "validation", "calibration"})
FORMAT_PROFILES = frozenset({"legacy_arabic", "unified_2026_arabic_latin"})
MINIMUM_SYNTHETIC_FORMAT_EXACT = 0.90


def _absolute_path_preserving_symlinks(path: str | Path) -> Path:
    """Return an absolute path without dereferencing a virtualenv launcher."""

    return Path(os.path.abspath(os.fspath(path)))


@dataclass(frozen=True)
class InferencePrediction:
    sample_id: str
    raw_text: str
    confidence: float


@dataclass(frozen=True)
class SelectionDecision:
    selected_candidate: str
    reason: str


def load_synthetic_rows(
    manifest_path: str | Path, dataset_dir: str | Path
) -> list[dict[str, str]]:
    rows = load_manifest(manifest_path)
    report = validate_manifest(rows)
    if report.independent_test_rows != 0 or report.split_counts.get("test", 0) != 0:
        raise ProtocolError("E2 synthétique refuse toute ligne du test indépendant.")
    for row in rows:
        if str(row["split"]) not in DEVELOPMENT_SPLITS:
            raise ProtocolError(f"Split E2 interdit: {row['split']!r}.")
        if str(row["source_id"]) != EXPECTED_SOURCE_ID:
            raise ProtocolError("E2 synthétique refuse une source réelle ou non admise.")
        if str(row["task"]) != "recognition":
            raise ProtocolError("E2 synthétique accepte uniquement la reconnaissance OCR.")
        if str(row["holdout_role"]) != "development":
            raise ProtocolError("E2 synthétique accepte uniquement le rôle development.")
        profile = str(row.get("format_profile", ""))
        if profile not in FORMAT_PROFILES:
            raise ProtocolError(f"Profil de plaque E2 inconnu: {profile!r}.")
        parsed = parse_plate_text(
            str(row.get("recognition_text", "")),
            bilingual_mapping=OFFICIAL_SERIES_MAPPING,
            require_verified_bilingual=(profile == "unified_2026_arabic_latin"),
        )
        if not parsed.valid or parsed.canonical != str(row["target"]):
            raise ProtocolError("Texte OCR synthétique incohérent avec sa cible canonique.")
        expected_latin = (
            OFFICIAL_SERIES_MAPPING.get(parsed.series_arabic or "", "")
            if profile == "unified_2026_arabic_latin"
            else ""
        )
        if str(row.get("series_latin", "")) != expected_latin:
            raise ProtocolError("Correspondance arabe/latine synthétique incohérente.")
    coverage = {
        (str(row["split"]), str(row.get("format_profile", ""))) for row in rows
    }
    expected_coverage = {
        (split, profile) for split in DEVELOPMENT_SPLITS for profile in FORMAT_PROFILES
    }
    missing_coverage = sorted(expected_coverage.difference(coverage))
    if missing_coverage:
        raise ProtocolError(
            "E2 exige les formats ancien et unifié dans chaque split; absence: "
            + ", ".join(f"{split}/{profile}" for split, profile in missing_coverage)
        )
    verify_manifest_files(rows, dataset_dir, dataset_dir)
    return rows


def parse_inference_predictions(path: str | Path) -> dict[str, InferencePrediction]:
    predictions: dict[str, InferencePrediction] = {}
    with Path(path).open("r", encoding="utf-8") as handle:
        for line_number, line in enumerate(handle, start=1):
            parts = line.rstrip("\n").rsplit("\t", 2)
            if len(parts) != 3:
                raise ProtocolError(
                    f"Ligne d'inférence {line_number}: chemin, texte et confiance attendus."
                )
            image_path, raw_text, raw_confidence = parts
            sample_id = Path(image_path).stem
            if not sample_id:
                raise ProtocolError(f"Ligne d'inférence {line_number}: sample_id absent.")
            if sample_id in predictions:
                raise ProtocolError(f"Prédiction dupliquée pour {sample_id!r}.")
            try:
                confidence = float(raw_confidence)
            except ValueError as error:
                raise ProtocolError(
                    f"Ligne d'inférence {line_number}: confiance invalide."
                ) from error
            if not 0.0 <= confidence <= 1.0:
                raise ProtocolError(
                    f"Ligne d'inférence {line_number}: confiance hors domaine [0,1]."
                )
            predictions[sample_id] = InferencePrediction(
                sample_id=sample_id,
                raw_text=raw_text,
                confidence=confidence,
            )
    if not predictions:
        raise ProtocolError("Le fichier de prédictions est vide.")
    return predictions


def _plate_metric_key(parsed: Any) -> str:
    return "|".join(
        (
            str(parsed.serial or ""),
            str(parsed.series_arabic or ""),
            str(parsed.series_latin or ""),
            str(parsed.region or ""),
        )
    )


def _metric_prediction_text(
    raw_text: str, *, format_profile: str
) -> tuple[str, bool, bool]:
    parsed = parse_plate_text(
        raw_text,
        bilingual_mapping=OFFICIAL_SERIES_MAPPING,
        require_verified_bilingual=(format_profile == "unified_2026_arabic_latin"),
        paddle_arabic_output=True,
    )
    if parsed.valid and parsed.canonical is not None:
        return (
            _plate_metric_key(parsed),
            True,
            parsed.bilingual_consistency == "verified",
        )
    compact = "".join(normalize_ocr_text(raw_text).split())
    return compact, False, False


def evaluate_predictions(
    rows: Sequence[Mapping[str, str]],
    predictions: Mapping[str, InferencePrediction],
    *,
    split: str,
    variant_id: str | None,
    format_profile: str | None = None,
) -> dict[str, float | int | str | None]:
    selected_rows = [
        row
        for row in rows
        if str(row["split"]) == split
        and (variant_id is None or str(row.get("variant_id", "")) == variant_id)
        and (
            format_profile is None
            or str(row.get("format_profile", "")) == format_profile
        )
    ]
    if not selected_rows:
        raise ProtocolError(f"Aucune ligne à évaluer pour {split}/{variant_id or 'all'}.")

    expected_ids = {str(row["sample_id"]) for row in selected_rows}
    missing = sorted(expected_ids.difference(predictions))
    if missing:
        raise ProtocolError(
            f"Prédictions absentes pour {len(missing)} échantillon(s), dont {missing[0]!r}."
        )

    metric_predictions: list[str] = []
    targets: list[str] = []
    confidences: list[float] = []
    grammar_valid = 0
    bilingual_verified = 0
    bilingual_samples = 0
    exact = 0
    for row in selected_rows:
        prediction = predictions[str(row["sample_id"])]
        profile = str(row.get("format_profile", "legacy_arabic"))
        metric_text, valid, verified = _metric_prediction_text(
            prediction.raw_text, format_profile=profile
        )
        target_parsed = parse_plate_text(
            str(row["recognition_text"]),
            bilingual_mapping=OFFICIAL_SERIES_MAPPING,
            require_verified_bilingual=(profile == "unified_2026_arabic_latin"),
        )
        if not target_parsed.valid:
            raise ProtocolError("Cible OCR synthétique invalide pendant l'évaluation.")
        target = _plate_metric_key(target_parsed)
        metric_predictions.append(metric_text)
        targets.append(target)
        confidences.append(prediction.confidence)
        grammar_valid += int(valid)
        if profile == "unified_2026_arabic_latin":
            bilingual_samples += 1
            bilingual_verified += int(verified)
        exact += int(metric_text == target)

    return {
        "split": split,
        "variant_scope": variant_id or "all",
        "format_profile": format_profile or "all",
        "sample_count": len(selected_rows),
        "exact_match": exact / len(selected_rows),
        "cer": character_error_rate(metric_predictions, targets),
        "grammar_valid_rate": grammar_valid / len(selected_rows),
        "bilingual_verified_rate": (
            bilingual_verified / bilingual_samples if bilingual_samples else None
        ),
        "mean_confidence": statistics.fmean(confidences),
        "minimum_confidence": min(confidences),
    }


def select_candidate(
    baseline_metrics: Mapping[str, float | int | str | None],
    challenger_metrics: Mapping[str, float | int | str | None],
    *,
    baseline_segments: Sequence[Mapping[str, float | int | str | None]] = (),
    challenger_segments: Sequence[Mapping[str, float | int | str | None]] = (),
    minimum_segment_exact: float = MINIMUM_SYNTHETIC_FORMAT_EXACT,
) -> SelectionDecision:
    if not 0.0 <= minimum_segment_exact <= 1.0:
        raise ProtocolError("Plancher d'exact-match synthétique hors domaine [0,1].")
    if len(baseline_segments) != len(challenger_segments):
        raise ProtocolError("Segments baseline/challenger déséquilibrés.")
    for baseline_segment, challenger_segment in zip(
        baseline_segments, challenger_segments, strict=True
    ):
        if float(challenger_segment["exact_match"]) < minimum_segment_exact:
            return SelectionDecision(
                "official_arabic_ppocrv5_incumbent",
                "challenger_below_required_format_floor",
            )
        if float(challenger_segment["exact_match"]) < float(
            baseline_segment["exact_match"]
        ):
            return SelectionDecision(
                "official_arabic_ppocrv5_incumbent",
                "challenger_regressed_on_required_format_segment",
            )
    baseline_exact = float(baseline_metrics["exact_match"])
    challenger_exact = float(challenger_metrics["exact_match"])
    baseline_cer = float(baseline_metrics["cer"])
    challenger_cer = float(challenger_metrics["cer"])
    if challenger_exact > baseline_exact:
        return SelectionDecision(
            "fine_tuned_synthetic_challenger",
            "higher_validation_exact_match",
        )
    if challenger_exact == baseline_exact and challenger_cer < baseline_cer:
        return SelectionDecision(
            "fine_tuned_synthetic_challenger",
            "validation_exact_tie_lower_cer",
        )
    return SelectionDecision(
        "official_arabic_ppocrv5_incumbent",
        "challenger_did_not_improve_validation_exact_then_cer",
    )


def _run_logged(
    command: Sequence[str],
    *,
    cwd: Path,
    environment: Mapping[str, str],
    log_path: Path,
    announce: str,
) -> None:
    print(json.dumps({"stage": announce, "status": "started"}, sort_keys=True), flush=True)
    log_path.parent.mkdir(parents=True, exist_ok=True)
    with log_path.open("w", encoding="utf-8") as log_handle:
        process = subprocess.run(
            list(command),
            cwd=cwd,
            env=dict(environment),
            stdout=log_handle,
            stderr=subprocess.STDOUT,
            text=True,
            check=False,
        )
    if process.returncode != 0:
        tail = log_path.read_text(encoding="utf-8", errors="replace").splitlines()[-25:]
        raise RuntimeError(
            f"Étape {announce!r} échouée ({process.returncode}).\n" + "\n".join(tail)
        )
    print(json.dumps({"stage": announce, "status": "completed"}, sort_keys=True), flush=True)


def _create_clean_eval_directory(
    root: Path,
    rows: Sequence[Mapping[str, str]],
    dataset_dir: Path,
    split: str,
) -> Path:
    destination = root / f"clean-{split}"
    destination.mkdir(parents=True, exist_ok=False)
    clean_rows = [
        row
        for row in rows
        if str(row["split"]) == split and str(row.get("variant_id", "")) == "variant-00"
    ]
    if not clean_rows:
        raise ProtocolError(f"Variante propre absente pour {split}.")
    for row in clean_rows:
        source = dataset_dir / str(row["image_path"])
        snapshot = destination / source.name
        shutil.copy2(source, snapshot)
    return destination


def _inference_command(
    *,
    python_executable: Path,
    config_path: Path,
    checkpoint_prefix: Path,
    image_directory: Path,
    prediction_path: Path,
) -> list[str]:
    return [
        os.fspath(python_executable),
        "tools/infer_rec.py",
        "-c",
        os.fspath(config_path),
        "-o",
        f"Global.pretrained_model={checkpoint_prefix}",
        f"Global.infer_img={image_directory}",
        f"Global.save_res_path={prediction_path}",
        "Global.use_gpu=True",
        "Global.distributed=False",
    ]


def _copy_dataset_provenance(dataset_dir: Path, destination: Path) -> None:
    destination.mkdir(parents=True, exist_ok=False)
    for name in (
        "manifest.csv",
        "generation_report.json",
        "SHA256SUMS",
        "paddleocr_dict.txt",
    ):
        shutil.copy2(dataset_dir / name, destination / name)
    for name in ("labels", "fonts", "licenses"):
        shutil.copytree(dataset_dir / name, destination / name)


def run_e2(
    *,
    python_executable: Path,
    paddleocr_dir: Path,
    dataset_dir: Path,
    output_dir: Path,
    pretrained_prefix: Path,
    repository_sha: str,
    paddleocr_sha: str,
    epochs: int,
    batch_size: int,
    seed: int,
) -> dict[str, Any]:
    if output_dir.exists():
        raise FileExistsError(f"Sortie E2 existante, aucun écrasement: {output_dir}")
    if not 1 <= epochs <= 200:
        raise ProtocolError("epochs doit être compris entre 1 et 200.")
    if not 1 <= batch_size <= 512:
        raise ProtocolError("batch_size doit être compris entre 1 et 512.")
    if paddleocr_sha != EXPECTED_PADDLEOCR_SHA:
        raise ProtocolError(
            f"Commit PaddleOCR inattendu: {paddleocr_sha}; {EXPECTED_PADDLEOCR_SHA} attendu."
        )

    config_path = paddleocr_dir / EXPECTED_CONFIG
    dictionary_path = paddleocr_dir / EXPECTED_DICTIONARY
    pretrained_params = pretrained_prefix.with_suffix(".pdparams")
    for required in (
        python_executable,
        config_path,
        dictionary_path,
        pretrained_params,
        dataset_dir / "manifest.csv",
    ):
        if not required.is_file():
            raise FileNotFoundError(f"Artefact E2 absent: {required}")

    rows = load_synthetic_rows(dataset_dir / "manifest.csv", dataset_dir)
    dictionary_characters = set(dictionary_path.read_text(encoding="utf-8").splitlines())
    required_characters = set("0123456789")
    for row in rows:
        required_characters.update(
            character
            for character in normalize_ocr_text(str(row["recognition_text"]))
            if not character.isspace()
        )
    missing_characters = sorted(required_characters.difference(dictionary_characters))
    if missing_characters:
        raise ProtocolError(
            "Dictionnaire arabe officiel incomplet pour la synthèse: "
            + ", ".join(missing_characters)
        )

    output_dir.parent.mkdir(parents=True, exist_ok=True)
    with tempfile.TemporaryDirectory(prefix=".anpr-e2-", dir=output_dir.parent) as temporary:
        root = Path(temporary)
        work = root / "_work"
        training_dir = work / "training"
        predictions_dir = root / "predictions"
        logs_dir = root / "logs"
        model_dir = root / "model"
        provenance_dir = root / "provenance"
        work.mkdir()
        predictions_dir.mkdir()
        logs_dir.mkdir()
        model_dir.mkdir()
        provenance_dir.mkdir()

        clean_validation = _create_clean_eval_directory(
            work, rows, dataset_dir, "validation"
        )
        clean_calibration = _create_clean_eval_directory(
            work, rows, dataset_dir, "calibration"
        )

        environment = os.environ.copy()
        environment.update(
            {
                "PYTHONHASHSEED": str(seed),
                "FLAGS_cudnn_deterministic": "1",
                "PADDLE_PDX_MODEL_SOURCE": "BOS",
            }
        )

        inference_jobs = (
            ("baseline_validation_clean", pretrained_prefix, clean_validation),
            ("baseline_calibration_clean", pretrained_prefix, clean_calibration),
        )
        for name, checkpoint, image_directory in inference_jobs:
            prediction_path = predictions_dir / f"{name}.txt"
            _run_logged(
                _inference_command(
                    python_executable=python_executable,
                    config_path=config_path,
                    checkpoint_prefix=checkpoint,
                    image_directory=image_directory,
                    prediction_path=prediction_path,
                ),
                cwd=paddleocr_dir,
                environment=environment,
                log_path=logs_dir / f"{name}.log",
                announce=name,
            )

        train_command = [
            os.fspath(python_executable),
            "tools/train.py",
            "-c",
            os.fspath(config_path),
            "-o",
            f"Global.pretrained_model={pretrained_prefix}",
            f"Global.save_model_dir={training_dir}",
            f"Global.epoch_num={epochs}",
            "Global.eval_batch_step=[0,10]",
            "Global.save_epoch_step=5",
            "Global.print_batch_step=10",
            "Global.use_gpu=True",
            "Global.distributed=False",
            f"Global.seed={seed}",
            f"Global.character_dict_path={dictionary_path}",
            "Global.use_space_char=True",
            "Optimizer.lr.learning_rate=0.0001",
            "Optimizer.lr.warmup_epoch=2",
            f"Train.dataset.data_dir={dataset_dir}",
            "Train.dataset.label_file_list="
            f"[{dataset_dir / 'labels/rec_gt_train.txt'}]",
            f"Eval.dataset.data_dir={dataset_dir}",
            "Eval.dataset.label_file_list="
            f"[{dataset_dir / 'labels/rec_gt_validation.txt'}]",
            f"Train.sampler.first_bs={batch_size}",
            "Train.loader.num_workers=2",
            "Eval.loader.num_workers=2",
        ]
        _run_logged(
            train_command,
            cwd=paddleocr_dir,
            environment=environment,
            log_path=logs_dir / "training.log",
            announce="fine_tuning",
        )

        tuned_prefix = training_dir / "best_accuracy"
        tuned_params = tuned_prefix.with_suffix(".pdparams")
        if not tuned_params.is_file():
            raise FileNotFoundError("Checkpoint best_accuracy absent après fine-tuning.")

        tuned_inference_jobs = (
            ("tuned_validation_clean", clean_validation),
            ("tuned_calibration_clean", clean_calibration),
            ("tuned_validation_all_variants", dataset_dir / "images/validation"),
        )
        for name, image_directory in tuned_inference_jobs:
            prediction_path = predictions_dir / f"{name}.txt"
            _run_logged(
                _inference_command(
                    python_executable=python_executable,
                    config_path=config_path,
                    checkpoint_prefix=tuned_prefix,
                    image_directory=image_directory,
                    prediction_path=prediction_path,
                ),
                cwd=paddleocr_dir,
                environment=environment,
                log_path=logs_dir / f"{name}.log",
                announce=name,
            )

        baseline_validation_predictions = parse_inference_predictions(
            predictions_dir / "baseline_validation_clean.txt"
        )
        baseline_calibration_predictions = parse_inference_predictions(
            predictions_dir / "baseline_calibration_clean.txt"
        )
        tuned_validation_predictions = parse_inference_predictions(
            predictions_dir / "tuned_validation_clean.txt"
        )
        tuned_calibration_predictions = parse_inference_predictions(
            predictions_dir / "tuned_calibration_clean.txt"
        )
        tuned_robustness_predictions = parse_inference_predictions(
            predictions_dir / "tuned_validation_all_variants.txt"
        )

        baseline_validation = evaluate_predictions(
            rows,
            baseline_validation_predictions,
            split="validation",
            variant_id="variant-00",
        )
        baseline_calibration = evaluate_predictions(
            rows,
            baseline_calibration_predictions,
            split="calibration",
            variant_id="variant-00",
        )
        tuned_validation = evaluate_predictions(
            rows,
            tuned_validation_predictions,
            split="validation",
            variant_id="variant-00",
        )
        tuned_calibration = evaluate_predictions(
            rows,
            tuned_calibration_predictions,
            split="calibration",
            variant_id="variant-00",
        )
        tuned_robustness = evaluate_predictions(
            rows,
            tuned_robustness_predictions,
            split="validation",
            variant_id=None,
        )
        baseline_validation_segments = [
            evaluate_predictions(
                rows,
                baseline_validation_predictions,
                split="validation",
                variant_id="variant-00",
                format_profile=profile,
            )
            for profile in sorted(FORMAT_PROFILES)
        ]
        tuned_validation_segments = [
            evaluate_predictions(
                rows,
                tuned_validation_predictions,
                split="validation",
                variant_id="variant-00",
                format_profile=profile,
            )
            for profile in sorted(FORMAT_PROFILES)
        ]
        tuned_robustness_segments = [
            evaluate_predictions(
                rows,
                tuned_robustness_predictions,
                split="validation",
                variant_id=None,
                format_profile=profile,
            )
            for profile in sorted(FORMAT_PROFILES)
        ]
        decision = select_candidate(
            baseline_validation,
            tuned_validation,
            baseline_segments=baseline_validation_segments,
            challenger_segments=tuned_validation_segments,
        )

        for checkpoint_file in sorted(training_dir.glob("best_accuracy.*")):
            if checkpoint_file.is_file():
                shutil.copy2(checkpoint_file, model_dir / checkpoint_file.name)
        shutil.copy2(
            pretrained_params,
            model_dir / "official_arabic_PP-OCRv5_mobile_rec_pretrained.pdparams",
        )
        shutil.copy2(config_path, provenance_dir / config_path.name)
        shutil.copy2(dictionary_path, provenance_dir / dictionary_path.name)
        _copy_dataset_provenance(dataset_dir, provenance_dir / "synthetic_dataset")

        pip_freeze = subprocess.check_output(
            [os.fspath(python_executable), "-m", "pip", "freeze"],
            cwd=paddleocr_dir,
            env=dict(environment),
            text=True,
        )
        (provenance_dir / "pip-freeze.txt").write_text(
            pip_freeze, encoding="utf-8"
        )
        gpu_query = subprocess.run(
            [
                "nvidia-smi",
                "--query-gpu=name,driver_version,memory.total",
                "--format=csv,noheader",
            ],
            check=False,
            capture_output=True,
            text=True,
        )

        def package_version(name: str) -> str | None:
            try:
                return importlib.metadata.version(name)
            except importlib.metadata.PackageNotFoundError:
                return None

        environment_report = {
            "python": sys.version.split()[0],
            "platform": platform.platform(),
            "paddlepaddle_gpu": package_version("paddlepaddle-gpu"),
            "paddleocr": package_version("paddleocr"),
            "pillow": package_version("Pillow"),
            "gpu": gpu_query.stdout.strip() if gpu_query.returncode == 0 else None,
            "pip_freeze_sha256": file_sha256(provenance_dir / "pip-freeze.txt"),
        }

        generator_report = json.loads(
            (dataset_dir / "generation_report.json").read_text(encoding="utf-8")
        )
        report: dict[str, Any] = {
            "schema_version": "1.0.0",
            "experiment_version": E2_VERSION,
            "experiment_id": "E2_synthetic_only",
            "status": "synthetic_e2_complete_not_qualified",
            "qualification_claim": False,
            "real_photo_accuracy_claim": False,
            "saas_integration_allowed": False,
            "final_test_opened": False,
            "synthetic_only": True,
            "source": {
                "repository_sha": repository_sha,
                "paddleocr_sha": paddleocr_sha,
                "official_pretrained_model_url": OFFICIAL_PRETRAINED_URL,
                "paddleocr_config_sha256": file_sha256(config_path),
                "paddleocr_dictionary_sha256": file_sha256(dictionary_path),
                "official_pretrained_model_sha256": file_sha256(pretrained_params),
                "synthetic_manifest_sha256": file_sha256(dataset_dir / "manifest.csv"),
                "synthetic_dataset_images_sha256": generator_report["artifacts"][
                    "dataset_images_sha256"
                ],
            },
            "environment": environment_report,
            "training": {
                "epochs": epochs,
                "batch_size": batch_size,
                "seed": seed,
                "learning_rate": 0.0001,
                "warmup_epochs": 2,
                "train_split": "train",
                "selection_split": "validation",
                "calibration_split_used_for_selection": False,
                "independent_test_used": False,
                "official_747_character_dictionary_preserved": True,
                "required_format_segments": sorted(FORMAT_PROFILES),
                "minimum_synthetic_format_exact": MINIMUM_SYNTHETIC_FORMAT_EXACT,
                "detector_trained_by_this_experiment": False,
                "recognizer_input_contract": "plate_crop_only",
            },
            "metrics": {
                "baseline_validation_clean": baseline_validation,
                "baseline_validation_clean_by_format": baseline_validation_segments,
                "baseline_calibration_clean": baseline_calibration,
                "challenger_validation_clean": tuned_validation,
                "challenger_validation_clean_by_format": tuned_validation_segments,
                "challenger_calibration_clean": tuned_calibration,
                "challenger_validation_all_variants": tuned_robustness,
                "challenger_validation_all_variants_by_format": tuned_robustness_segments,
            },
            "selection": {
                "rule": (
                    "require at least 0.90 exact-match on every synthetic format; "
                    "refuse regression on legacy or unified validation segment; "
                    "then maximize validation exact-match; break ties with lower CER"
                ),
                "selected_candidate": decision.selected_candidate,
                "reason": decision.reason,
                "selected_for": "synthetic_development_only",
                "selected_model_sha256": (
                    file_sha256(tuned_params)
                    if decision.selected_candidate == "fine_tuned_synthetic_challenger"
                    else file_sha256(pretrained_params)
                ),
                "selected_artifact": (
                    "model/best_accuracy.pdparams"
                    if decision.selected_candidate == "fine_tuned_synthetic_challenger"
                    else "model/official_arabic_PP-OCRv5_mobile_rec_pretrained.pdparams"
                ),
            },
            "limits": [
                "Synthetic-only metrics are not evidence of real Moroccan plate accuracy.",
                "The independent real-world holdout remains sealed and unevaluated.",
                "No detector or end-to-end ANPR release gate is evaluated by E2 synthetic.",
                "Production inference must crop a detected plate before OCR; full-frame OCR is forbidden.",
                "SaaS integration remains forbidden until the final preregistered gate passes.",
            ],
        }
        report_path = root / "E2_SYNTHETIC_COMPLETE.json"
        report_path.write_text(
            json.dumps(report, ensure_ascii=False, sort_keys=True, indent=2) + "\n",
            encoding="utf-8",
        )

        shutil.rmtree(work)
        checksum_paths = [
            path
            for path in root.rglob("*")
            if path.is_file() and path.name != "SHA256SUMS"
        ]
        (root / "SHA256SUMS").write_text(
            "\n".join(sha256sum_lines(checksum_paths, root)) + "\n",
            encoding="utf-8",
        )
        root.replace(output_dir)
    return report


def build_parser() -> argparse.ArgumentParser:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--python", required=True, type=Path)
    parser.add_argument("--paddleocr-dir", required=True, type=Path)
    parser.add_argument("--dataset-dir", required=True, type=Path)
    parser.add_argument("--output-dir", required=True, type=Path)
    parser.add_argument("--pretrained-prefix", required=True, type=Path)
    parser.add_argument("--repository-sha", required=True)
    parser.add_argument("--paddleocr-sha", required=True)
    parser.add_argument("--epochs", type=int, default=20)
    parser.add_argument("--batch-size", type=int, default=64)
    parser.add_argument("--seed", type=int, default=20260825)
    return parser


def main(argv: Sequence[str] | None = None) -> int:
    arguments = build_parser().parse_args(argv)
    report = run_e2(
        # ``venv/bin/python`` is commonly a symlink. Resolving it selects the
        # system interpreter and silently drops the venv where Paddle is
        # installed, so keep the launcher path while making it absolute.
        python_executable=_absolute_path_preserving_symlinks(arguments.python),
        paddleocr_dir=arguments.paddleocr_dir.resolve(),
        dataset_dir=arguments.dataset_dir.resolve(),
        output_dir=arguments.output_dir.resolve(),
        pretrained_prefix=arguments.pretrained_prefix.resolve(),
        repository_sha=arguments.repository_sha,
        paddleocr_sha=arguments.paddleocr_sha,
        epochs=arguments.epochs,
        batch_size=arguments.batch_size,
        seed=arguments.seed,
    )
    print(
        json.dumps(
            {
                "status": report["status"],
                "metrics": report["metrics"],
                "selection": report["selection"],
                "qualification_claim": report["qualification_claim"],
                "final_test_opened": report["final_test_opened"],
            },
            ensure_ascii=False,
            sort_keys=True,
            indent=2,
        )
    )
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
