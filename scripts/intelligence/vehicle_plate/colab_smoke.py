#!/usr/bin/env python3
"""Run the private Moroccan ANPR v2 development smoke on Colab.

Heavy Torch, TorchVision, OpenCV and PaddleOCR imports are deliberately delayed
until execution. GitHub CI can therefore validate the data contract and pure
metrics without downloading a model or private vehicle photographs.
"""

from __future__ import annotations

import argparse
import csv
import gc
import json
import math
import os
import platform
import re
import statistics
import subprocess
import sys
import tempfile
import time
from collections import defaultdict
from dataclasses import asdict, dataclass
from pathlib import Path, PurePosixPath
from typing import Any, Mapping, Sequence

# Allow both `python -m ...` and direct script execution from Colab.
REPOSITORY_ROOT = Path(__file__).resolve().parents[3]
if str(REPOSITORY_ROOT) not in sys.path:
    sys.path.insert(0, str(REPOSITORY_ROOT))

from scripts.intelligence.vehicle_plate.protocol import (
    ProtocolError,
    ReadingCandidate,
    character_error_rate,
    exact_match_accuracy,
    file_sha256,
    parse_plate_text,
    select_consensus,
)


IMAGE_EXTENSIONS = frozenset({".jpg", ".jpeg", ".png", ".webp"})
SHA256_RE = re.compile(r"^[0-9a-f]{64}$")
DEVELOPMENT_SPLITS = frozenset({"train", "validation", "calibration"})
EXPECTED_DETECTOR = "fasterrcnn_resnet50_fpn_v2_multidomain_v1.2.0"
EXPECTED_ARCHITECTURE = "fasterrcnn_resnet50_fpn_v2"
OCR_MODEL_NAME = "arabic_PP-OCRv5_mobile_rec"
OCR_WORKER_SCHEMA_VERSION = "1.0.0"
SMOKE_VERSION = "2.0.0"


def _invocation_path(value: str | os.PathLike[str]) -> Path:
    """Return an absolute invocation path without dereferencing venv symlinks."""
    return Path(os.path.abspath(os.fspath(value)))


@dataclass(frozen=True)
class SmokeLabel:
    image_path: str
    group_id: str
    split: str
    target: str | None
    plate_bbox: tuple[float, float, float, float] | None
    sha256: str
    source_id: str
    consent_status: str


@dataclass(frozen=True)
class DetectorSelection:
    candidate: str
    checkpoint_sha256: str
    threshold: float
    role: str


@dataclass(frozen=True)
class Detection:
    box: tuple[float, float, float, float]
    score: float
    eligible_count: int
    ambiguous: bool


def read_json(path: str | Path) -> dict[str, Any]:
    payload = json.loads(Path(path).read_text(encoding="utf-8"))
    if not isinstance(payload, dict):
        raise ProtocolError(f"Objet JSON attendu: {path}")
    return payload


def parse_bbox(value: str) -> tuple[float, float, float, float] | None:
    value = str(value).strip()
    if not value:
        return None
    try:
        decoded = json.loads(value)
    except json.JSONDecodeError as error:
        raise ProtocolError("plate_bbox doit être un tableau JSON [x1,y1,x2,y2].") from error
    if not isinstance(decoded, list) or len(decoded) != 4:
        raise ProtocolError("plate_bbox doit contenir exactement quatre coordonnées.")
    try:
        box = tuple(float(item) for item in decoded)
    except (TypeError, ValueError) as error:
        raise ProtocolError("plate_bbox contient une coordonnée non numérique.") from error
    if not all(math.isfinite(item) for item in box):
        raise ProtocolError("plate_bbox contient une coordonnée non finie.")
    if box[2] <= box[0] or box[3] <= box[1]:
        raise ProtocolError("plate_bbox doit avoir une largeur et une hauteur positives.")
    return box  # type: ignore[return-value]


def load_smoke_labels(path: str | Path) -> dict[str, SmokeLabel]:
    """Load consented development labels; final-test rows are forbidden."""

    required = {
        "image_path",
        "group_id",
        "split",
        "target",
        "plate_bbox",
        "sha256",
        "source_id",
        "consent_status",
    }
    labels: dict[str, SmokeLabel] = {}
    group_targets: dict[str, str] = {}
    with Path(path).open("r", encoding="utf-8", newline="") as handle:
        reader = csv.DictReader(handle)
        missing = sorted(required.difference(reader.fieldnames or []))
        if missing:
            raise ProtocolError(f"Colonnes smoke absentes: {', '.join(missing)}")
        for line_number, row in enumerate(reader, start=2):
            image_path = str(row["image_path"] or "").strip()
            relative = PurePosixPath(image_path)
            if not image_path or relative.is_absolute() or ".." in relative.parts:
                raise ProtocolError(f"Ligne {line_number}: image_path relatif invalide.")
            if image_path in labels:
                raise ProtocolError(f"Ligne {line_number}: image_path dupliqué {image_path!r}.")
            split = str(row["split"] or "").strip().lower()
            if split not in DEVELOPMENT_SPLITS:
                raise ProtocolError(
                    f"Ligne {line_number}: le smoke refuse le split {split!r}; test final réservé."
                )
            digest = str(row["sha256"] or "").strip().lower()
            if not SHA256_RE.fullmatch(digest):
                raise ProtocolError(f"Ligne {line_number}: SHA-256 invalide.")
            consent = str(row["consent_status"] or "").strip().lower()
            if consent != "approved":
                raise ProtocolError(f"Ligne {line_number}: consentement non approuvé.")
            source_id = str(row["source_id"] or "").strip()
            if not source_id:
                raise ProtocolError(f"Ligne {line_number}: source_id absent.")
            group_id = str(row["group_id"] or "").strip()
            if not group_id:
                raise ProtocolError(f"Ligne {line_number}: group_id absent.")
            target_value = str(row["target"] or "").strip()
            target = target_value or None
            if target is not None:
                parsed = parse_plate_text(target)
                if not parsed.valid or parsed.canonical is None:
                    raise ProtocolError(
                        f"Ligne {line_number}: cible OCR invalide: {parsed.reasons}."
                    )
                target = parsed.canonical
                previous = group_targets.setdefault(group_id, target)
                if previous != target:
                    raise ProtocolError(
                        f"Ligne {line_number}: cibles contradictoires pour {group_id!r}."
                    )
            labels[image_path] = SmokeLabel(
                image_path=image_path,
                group_id=group_id,
                split=split,
                target=target,
                plate_bbox=parse_bbox(str(row["plate_bbox"] or "")),
                sha256=digest,
                source_id=source_id,
                consent_status=consent,
            )
    if not labels:
        raise ProtocolError("Le manifeste smoke est vide.")
    return labels


def load_detector_selection(path: str | Path) -> DetectorSelection:
    payload = read_json(path)
    candidate = str(payload.get("selected_candidate", ""))
    digest = str(payload.get("selected_model_sha256", "")).lower()
    role = str(payload.get("selection_role", ""))
    try:
        threshold = float(payload["calibrated_threshold"])
    except (KeyError, TypeError, ValueError) as error:
        raise ProtocolError("Seuil calibré absent ou invalide dans la sélection.") from error
    if candidate != EXPECTED_DETECTOR:
        raise ProtocolError(f"Détecteur inattendu: {candidate!r}.")
    if not SHA256_RE.fullmatch(digest):
        raise ProtocolError("SHA-256 du détecteur absent ou invalide.")
    if not 0.0 < threshold < 1.0:
        raise ProtocolError("Seuil du détecteur hors domaine (0,1).")
    if role != "development_only_not_independent_evidence":
        raise ProtocolError("Le rôle développement du détecteur n'est pas attesté.")
    return DetectorSelection(candidate, digest, threshold, role)


def verify_checkpoint(path: str | Path, selection: DetectorSelection) -> str:
    digest = file_sha256(path)
    if digest != selection.checkpoint_sha256:
        raise ProtocolError("Empreinte du checkpoint différente de la sélection privée.")
    return digest


def load_bilingual_mapping(path: str | Path | None) -> dict[str, str] | None:
    if path is None:
        return None
    payload = read_json(path)
    if payload.get("verification_status") != "verified_against_official_annex":
        raise ProtocolError("La correspondance bilingue n'est pas attestée par l'annexe officielle.")
    source_url = str(payload.get("official_source_url", ""))
    if not source_url.startswith("https://"):
        raise ProtocolError("URL officielle HTTPS absente de la correspondance bilingue.")
    mapping = payload.get("mapping")
    if not isinstance(mapping, dict) or not mapping:
        raise ProtocolError("Correspondance bilingue vide ou invalide.")
    normalized: dict[str, str] = {}
    for arabic, latin in mapping.items():
        parsed_arabic = str(arabic).strip()
        parsed_latin = str(latin).strip().upper()
        if not re.fullmatch(r"[\u0600-\u06ff]", parsed_arabic):
            raise ProtocolError(f"Clé arabe invalide dans la correspondance: {arabic!r}.")
        if not re.fullmatch(r"[A-Z]", parsed_latin):
            raise ProtocolError(f"Valeur latine invalide dans la correspondance: {latin!r}.")
        normalized[parsed_arabic] = parsed_latin
    return normalized


def discover_images(input_dir: str | Path, maximum: int) -> list[Path]:
    if maximum < 1:
        raise ValueError("maximum doit être >= 1")
    root = Path(input_dir)
    if not root.is_dir():
        raise FileNotFoundError(f"Dossier smoke absent: {root}")
    images = sorted(
        path
        for path in root.rglob("*")
        if path.is_file() and path.suffix.lower() in IMAGE_EXTENSIONS
    )
    if not images:
        raise ProtocolError("Aucune image compatible dans le dossier smoke.")
    return images[:maximum]


def relative_image_path(path: Path, root: Path) -> str:
    return path.relative_to(root).as_posix()


def expand_box(
    box: Sequence[float], width: int, height: int, padding_ratio: float
) -> tuple[int, int, int, int]:
    if width < 1 or height < 1 or len(box) != 4:
        raise ValueError("Image ou boîte invalide.")
    x1, y1, x2, y2 = (float(item) for item in box)
    if x2 <= x1 or y2 <= y1:
        raise ValueError("La boîte doit avoir une surface positive.")
    pad_x = (x2 - x1) * float(padding_ratio)
    pad_y = (y2 - y1) * float(padding_ratio)
    return (
        max(0, int(math.floor(x1 - pad_x))),
        max(0, int(math.floor(y1 - pad_y))),
        min(width, int(math.ceil(x2 + pad_x))),
        min(height, int(math.ceil(y2 + pad_y))),
    )


def box_iou(left: Sequence[float], right: Sequence[float]) -> float:
    if len(left) != 4 or len(right) != 4:
        raise ValueError("Une boîte contient quatre coordonnées.")
    lx1, ly1, lx2, ly2 = (float(item) for item in left)
    rx1, ry1, rx2, ry2 = (float(item) for item in right)
    intersection_width = max(0.0, min(lx2, rx2) - max(lx1, rx1))
    intersection_height = max(0.0, min(ly2, ry2) - max(ly1, ry1))
    intersection = intersection_width * intersection_height
    left_area = max(0.0, lx2 - lx1) * max(0.0, ly2 - ly1)
    right_area = max(0.0, rx2 - rx1) * max(0.0, ry2 - ry1)
    union = left_area + right_area - intersection
    return intersection / union if union > 0.0 else 0.0


def extract_ocr_result(result: Any) -> tuple[str, float]:
    payload = getattr(result, "json", result)
    if callable(payload):
        payload = payload()
    if not isinstance(payload, Mapping):
        raise ProtocolError("Résultat PaddleOCR sans objet JSON.")
    inner = payload.get("res", payload)
    if not isinstance(inner, Mapping):
        raise ProtocolError("Résultat PaddleOCR sans section res.")
    text = str(inner.get("rec_text", ""))
    try:
        score = float(inner.get("rec_score", 0.0))
    except (TypeError, ValueError) as error:
        raise ProtocolError("Score PaddleOCR invalide.") from error
    if not math.isfinite(score) or not 0.0 <= score <= 1.0:
        raise ProtocolError("Score PaddleOCR hors domaine [0,1].")
    return text, score


def validate_ocr_worker_payload(
    payload: Mapping[str, Any], expected_crop_ids: Sequence[str]
) -> dict[str, dict[str, Any]]:
    """Validate the closed JSON contract returned by the isolated OCR process."""

    if payload.get("schema_version") != OCR_WORKER_SCHEMA_VERSION:
        raise ProtocolError("Version de sortie du worker OCR inattendue.")
    if payload.get("model_name") != OCR_MODEL_NAME:
        raise ProtocolError("Modèle inattendu dans la sortie du worker OCR.")
    rows = payload.get("results")
    if not isinstance(rows, list):
        raise ProtocolError("Sortie du worker OCR sans liste de résultats.")
    expected = list(expected_crop_ids)
    if len(expected) != len(set(expected)):
        raise ProtocolError("Identifiants de crops attendus dupliqués.")
    validated: dict[str, dict[str, Any]] = {}
    for index, row in enumerate(rows):
        if not isinstance(row, Mapping):
            raise ProtocolError(f"Résultat OCR {index}: objet attendu.")
        crop_id = str(row.get("crop_id", ""))
        if not crop_id or crop_id in validated:
            raise ProtocolError(f"Résultat OCR {index}: identifiant absent ou dupliqué.")
        try:
            score = float(row.get("score"))
        except (TypeError, ValueError) as error:
            raise ProtocolError(f"Résultat OCR {index}: score invalide.") from error
        if not math.isfinite(score) or not 0.0 <= score <= 1.0:
            raise ProtocolError(f"Résultat OCR {index}: score hors domaine [0,1].")
        validated[crop_id] = {
            "crop_id": crop_id,
            "raw_text": str(row.get("raw_text", "")),
            "score": score,
        }
    if set(validated) != set(expected):
        raise ProtocolError("Le worker OCR n'a pas retourné exactement un résultat par crop.")
    try:
        declared_count = int(payload.get("count"))
    except (TypeError, ValueError) as error:
        raise ProtocolError("Compteur du worker OCR invalide.") from error
    if declared_count != len(expected):
        raise ProtocolError("Compteur du worker OCR incohérent.")
    timings = payload.get("timings_seconds")
    environment = payload.get("environment")
    if not isinstance(timings, Mapping) or not isinstance(environment, Mapping):
        raise ProtocolError("Métadonnées du worker OCR absentes.")
    for key in ("ocr_load", "ocr_inference_total"):
        try:
            value = float(timings[key])
        except (KeyError, TypeError, ValueError) as error:
            raise ProtocolError(f"Durée {key!r} du worker OCR invalide.") from error
        if not math.isfinite(value) or value < 0.0:
            raise ProtocolError(f"Durée {key!r} du worker OCR hors domaine.")
    if environment.get("isolated_process") is not True:
        raise ProtocolError("Le worker OCR n'atteste pas l'isolation du processus.")
    if environment.get("device") != "gpu:0" or environment.get("paddle_cuda_compiled") is not True:
        raise ProtocolError("Le worker OCR n'atteste pas l'exécution CUDA gpu:0.")
    return validated


def _json_safe(value: Any) -> Any:
    if isinstance(value, Mapping):
        return {str(key): _json_safe(item) for key, item in value.items()}
    if isinstance(value, (list, tuple)):
        return [_json_safe(item) for item in value]
    if isinstance(value, float) and not math.isfinite(value):
        return None
    return value


def aggregate_smoke_metrics(
    group_rows: Sequence[Mapping[str, Any]],
    detection_ious: Sequence[float],
) -> dict[str, Any]:
    labelled = [row for row in group_rows if row.get("target")]
    accepted = [row for row in labelled if row.get("accepted_prediction")]
    metrics: dict[str, Any] = {
        "group_count": len(group_rows),
        "labelled_group_count": len(labelled),
        "accepted_group_count": len(accepted),
        "detection_label_count": len(detection_ious),
        "detection_recall_iou50": (
            sum(iou >= 0.5 for iou in detection_ious) / len(detection_ious)
            if detection_ious
            else None
        ),
        "mean_detection_iou": statistics.fmean(detection_ious) if detection_ious else None,
        "raw_ocr_full_plate_exact": None,
        "raw_ocr_cer": None,
        "selective_exact": None,
        "selective_coverage": None,
        "end_to_end_exact": None,
    }
    if labelled:
        targets = [str(row["target"]) for row in labelled]
        raw_predictions = [str(row.get("raw_prediction") or "") for row in labelled]
        accepted_predictions = [
            str(row.get("accepted_prediction") or "") for row in labelled
        ]
        metrics.update(
            {
                "raw_ocr_full_plate_exact": exact_match_accuracy(raw_predictions, targets),
                "raw_ocr_cer": character_error_rate(raw_predictions, targets),
                "selective_coverage": len(accepted) / len(labelled),
                "end_to_end_exact": exact_match_accuracy(accepted_predictions, targets),
            }
        )
        if accepted:
            metrics["selective_exact"] = exact_match_accuracy(
                [str(row["accepted_prediction"]) for row in accepted],
                [str(row["target"]) for row in accepted],
            )
    return _json_safe(metrics)


def _load_detector(checkpoint_path: Path, device: str):
    import torch
    from torchvision.models.detection import fasterrcnn_resnet50_fpn_v2
    from torchvision.models.detection.faster_rcnn import FastRCNNPredictor

    checkpoint = torch.load(checkpoint_path, map_location="cpu", weights_only=True)
    if not isinstance(checkpoint, dict):
        raise ProtocolError("Checkpoint détecteur sans dictionnaire racine.")
    architecture = str(checkpoint.get("architecture", EXPECTED_ARCHITECTURE))
    if architecture != EXPECTED_ARCHITECTURE:
        raise ProtocolError(f"Architecture inattendue dans le checkpoint: {architecture!r}.")
    try:
        min_size = int(checkpoint.get("min_size", 768))
        max_size = int(checkpoint.get("max_size", 1280))
    except (TypeError, ValueError) as error:
        raise ProtocolError("Dimensions du détecteur invalides.") from error
    if not 256 <= min_size <= max_size <= 4096:
        raise ProtocolError("Dimensions du détecteur hors limites de sécurité.")
    model = fasterrcnn_resnet50_fpn_v2(
        weights=None,
        weights_backbone=None,
        min_size=min_size,
        max_size=max_size,
    )
    in_features = model.roi_heads.box_predictor.cls_score.in_features
    model.roi_heads.box_predictor = FastRCNNPredictor(in_features, 2)
    state = checkpoint.get("model_state_dict")
    if not isinstance(state, dict):
        raise ProtocolError("model_state_dict absent du checkpoint.")
    model.load_state_dict(state, strict=True)
    model.to(device).eval()
    return model, {"architecture": architecture, "min_size": min_size, "max_size": max_size}


def _detect(model: Any, image: Any, threshold: float, device: str) -> Detection | None:
    import torch
    from torchvision.transforms.functional import pil_to_tensor

    tensor = pil_to_tensor(image).float().div(255.0).to(device)
    with torch.inference_mode():
        output = model([tensor])[0]
    eligible: list[tuple[tuple[float, float, float, float], float]] = []
    for box, label, score in zip(
        output["boxes"].detach().cpu().tolist(),
        output["labels"].detach().cpu().tolist(),
        output["scores"].detach().cpu().tolist(),
        strict=True,
    ):
        if int(label) == 1 and float(score) >= threshold:
            eligible.append((tuple(float(item) for item in box), float(score)))
    if not eligible:
        return None
    eligible.sort(key=lambda item: item[1], reverse=True)
    top_box, top_score = eligible[0]
    ambiguous = any(
        second_score >= max(threshold, top_score - 0.05)
        and box_iou(top_box, second_box) < 0.30
        for second_box, second_score in eligible[1:]
    )
    return Detection(top_box, top_score, len(eligible), ambiguous)


def _order_quad(points: Any):
    import numpy as np

    points = np.asarray(points, dtype="float32").reshape(4, 2)
    ordered = np.zeros((4, 2), dtype="float32")
    sums = points.sum(axis=1)
    differences = np.diff(points, axis=1).reshape(-1)
    ordered[0] = points[int(np.argmin(sums))]
    ordered[2] = points[int(np.argmax(sums))]
    ordered[1] = points[int(np.argmin(differences))]
    ordered[3] = points[int(np.argmax(differences))]
    return ordered


def _rectify_plate(crop: Any) -> Any | None:
    """Conservative, non-generative quadrilateral rectification."""

    import cv2
    import numpy as np
    from PIL import Image

    rgb = np.asarray(crop.convert("RGB"))
    gray = cv2.cvtColor(rgb, cv2.COLOR_RGB2GRAY)
    blurred = cv2.GaussianBlur(gray, (5, 5), 0)
    edges = cv2.Canny(blurred, 50, 150)
    contours, _ = cv2.findContours(edges, cv2.RETR_LIST, cv2.CHAIN_APPROX_SIMPLE)
    image_area = float(rgb.shape[0] * rgb.shape[1])
    candidates: list[tuple[float, Any]] = []
    for contour in contours:
        perimeter = cv2.arcLength(contour, True)
        approximation = cv2.approxPolyDP(contour, 0.02 * perimeter, True)
        area = abs(float(cv2.contourArea(approximation)))
        if len(approximation) != 4 or area < image_area * 0.20:
            continue
        rectangle = cv2.minAreaRect(approximation)
        short_side, long_side = sorted(rectangle[1])
        if short_side <= 0:
            continue
        ratio = long_side / short_side
        if 2.0 <= ratio <= 7.5:
            candidates.append((area, approximation.reshape(4, 2)))
    if not candidates:
        return None
    _, points = max(candidates, key=lambda item: item[0])
    top_left, top_right, bottom_right, bottom_left = _order_quad(points)
    width = int(
        round(
            max(
                np.linalg.norm(bottom_right - bottom_left),
                np.linalg.norm(top_right - top_left),
            )
        )
    )
    height = int(
        round(
            max(
                np.linalg.norm(top_right - bottom_right),
                np.linalg.norm(top_left - bottom_left),
            )
        )
    )
    if width < 80 or height < 20 or not 2.0 <= width / height <= 7.5:
        return None
    destination = np.asarray(
        [[0, 0], [width - 1, 0], [width - 1, height - 1], [0, height - 1]],
        dtype="float32",
    )
    transform = cv2.getPerspectiveTransform(
        np.asarray([top_left, top_right, bottom_right, bottom_left], dtype="float32"),
        destination,
    )
    warped = cv2.warpPerspective(rgb, transform, (width, height), flags=cv2.INTER_CUBIC)
    return Image.fromarray(warped, mode="RGB")


def _crop_variants(image: Any, detection: Detection) -> list[tuple[str, Any]]:
    from PIL import ImageOps

    variants: list[tuple[str, Any]] = []
    for padding in (0.03, 0.08, 0.15):
        crop = image.crop(expand_box(detection.box, image.width, image.height, padding))
        variants.append((f"padding-{padding:.2f}", crop.convert("RGB")))
    base = variants[1][1]
    variants.append(("autocontrast", ImageOps.autocontrast(base.convert("L")).convert("RGB")))
    rectified = _rectify_plate(base)
    if rectified is not None:
        variants.append(("perspective", rectified))
        variants.append(
            ("perspective-autocontrast", ImageOps.autocontrast(rectified.convert("L")).convert("RGB"))
        )
    return variants


def _detector_environment() -> dict[str, Any]:
    import cv2
    import numpy
    import PIL
    import torch
    import torchvision

    return {
        "python": platform.python_version(),
        "torch": torch.__version__,
        "torchvision": torchvision.__version__,
        "torch_cuda": torch.version.cuda,
        "opencv": cv2.__version__,
        "numpy": numpy.__version__,
        "pillow": PIL.__version__,
        "gpu": torch.cuda.get_device_name(0) if torch.cuda.is_available() else None,
        "process_role": "detector",
    }


def _run_isolated_ocr(
    pending_ocr: Sequence[dict[str, Any]],
    ocr_python: Path,
    batch_size: int,
    timeout_seconds: int,
) -> tuple[dict[str, dict[str, Any]], Mapping[str, Any], Mapping[str, Any]]:
    worker = Path(__file__).with_name("paddle_ocr_worker.py").resolve()
    if not worker.is_file():
        raise ProtocolError("Worker PaddleOCR absent du dépôt.")
    if not ocr_python.is_file():
        raise ProtocolError("Interpréteur OCR isolé absent.")
    if _invocation_path(ocr_python) == _invocation_path(sys.executable):
        raise ProtocolError("L'OCR Paddle doit utiliser un interpréteur distinct de PyTorch.")

    with tempfile.TemporaryDirectory(prefix="rentfleet-anpr-ocr-") as directory:
        root = Path(directory)
        crop_root = root / "crops"
        crop_root.mkdir()
        manifest_rows: list[dict[str, str]] = []
        expected_ids: list[str] = []
        for index, item in enumerate(pending_ocr):
            crop_id = f"crop-{index:05d}"
            relative_name = f"{crop_id}.png"
            image = item.pop("image")
            image.save(crop_root / relative_name, format="PNG")
            item["crop_id"] = crop_id
            expected_ids.append(crop_id)
            manifest_rows.append({"crop_id": crop_id, "image_path": relative_name})

        manifest_path = root / "manifest.json"
        result_path = root / "result.json"
        manifest_path.write_text(
            json.dumps(
                {
                    "schema_version": OCR_WORKER_SCHEMA_VERSION,
                    "model_name": OCR_MODEL_NAME,
                    "batch_size": batch_size,
                    "crops": manifest_rows,
                },
                ensure_ascii=False,
                indent=2,
                sort_keys=True,
            )
            + "\n",
            encoding="utf-8",
        )
        environment = os.environ.copy()
        environment["PADDLE_PDX_MODEL_SOURCE"] = "BOS"
        try:
            completed = subprocess.run(
                [
                    str(ocr_python),
                    str(worker),
                    "--manifest",
                    str(manifest_path),
                    "--crop-root",
                    str(crop_root),
                    "--output",
                    str(result_path),
                ],
                cwd=REPOSITORY_ROOT,
                env=environment,
                check=False,
                capture_output=True,
                text=True,
                timeout=timeout_seconds,
            )
        except subprocess.TimeoutExpired as error:
            raise ProtocolError("Le worker OCR isolé a dépassé son délai.") from error
        if completed.returncode != 0:
            diagnostic = (completed.stderr or completed.stdout or "").strip()[-2000:]
            raise ProtocolError(
                f"Le worker OCR isolé a échoué (code {completed.returncode}): {diagnostic}"
            )
        if not result_path.is_file():
            raise ProtocolError("Le worker OCR n'a pas produit son contrat JSON.")
        payload = read_json(result_path)
        results = validate_ocr_worker_payload(payload, expected_ids)
        return results, payload["timings_seconds"], payload["environment"]


def run_smoke(args: argparse.Namespace) -> dict[str, Any]:
    import torch
    from PIL import Image, ImageFile

    ImageFile.LOAD_TRUNCATED_IMAGES = False
    if not torch.cuda.is_available():
        raise RuntimeError("GPU Torch absent: activez un runtime GPU Colab.")

    input_root = Path(args.input_dir).resolve()
    checkpoint_path = Path(args.checkpoint).resolve()
    selection_path = Path(args.selection).resolve()
    ocr_python = _invocation_path(args.ocr_python)
    output = Path(args.output_dir).resolve()
    if not 60 <= int(args.ocr_timeout_seconds) <= 3600:
        raise ProtocolError("Délai OCR hors limites [60,3600] secondes.")
    output.mkdir(parents=True, exist_ok=True)
    private_predictions_path = output / "PRIVATE_predictions.jsonl"
    if private_predictions_path.exists():
        raise ProtocolError(
            "Le dossier de sortie contient déjà des prédictions; utilisez un nouveau RUN_ID."
        )

    selection = load_detector_selection(selection_path)
    checkpoint_digest = verify_checkpoint(checkpoint_path, selection)
    mapping = load_bilingual_mapping(args.series_mapping)
    labels = load_smoke_labels(args.labels) if args.labels else {}
    images = discover_images(input_root, args.max_images)
    if labels:
        discovered = {relative_image_path(path, input_root) for path in images}
        missing = sorted(set(labels).difference(discovered))
        if missing:
            raise ProtocolError(
                "Le plafond max-images ou le dossier exclut des lignes du manifeste: "
                + ", ".join(missing[:5])
            )

    detector_device = "cuda:0"
    started = time.perf_counter()
    detector, detector_configuration = _load_detector(checkpoint_path, detector_device)
    detector_load_seconds = time.perf_counter() - started

    pending_ocr: list[dict[str, Any]] = []
    image_records: list[dict[str, Any]] = []
    detection_ious: list[float] = []
    detector_seconds = 0.0
    for image_path in images:
        relative = relative_image_path(image_path, input_root)
        label = labels.get(relative)
        if labels and label is None:
            raise ProtocolError(f"Image sans ligne de manifeste: {relative}")
        if label and file_sha256(image_path) != label.sha256:
            raise ProtocolError(f"Empreinte image différente: {relative}")
        with Image.open(image_path) as opened:
            image = opened.convert("RGB")
        tick = time.perf_counter()
        detection = _detect(detector, image, selection.threshold, detector_device)
        detector_seconds += time.perf_counter() - tick
        group_id = label.group_id if label else relative
        record: dict[str, Any] = {
            "image_path": relative,
            "group_id": group_id,
            "target": label.target if label else None,
            "detection": asdict(detection) if detection else None,
            "detection_iou": None,
            "ocr_candidates": [],
        }
        if label and label.plate_bbox is not None:
            iou = box_iou(detection.box, label.plate_bbox) if detection else 0.0
            detection_ious.append(iou)
            record["detection_iou"] = iou
        if detection is not None:
            for variant_id, crop in _crop_variants(image, detection):
                pending_ocr.append(
                    {
                        "record": record,
                        "view_id": relative,
                        "variant_id": variant_id,
                        "detector_confidence": detection.score,
                        "quality_passed": (
                            not detection.ambiguous and crop.width >= 80 and crop.height >= 20
                        ),
                        "image": crop,
                    }
                )
        image_records.append(record)

    del detector
    gc.collect()
    torch.cuda.empty_cache()

    batch_size = max(1, min(int(args.ocr_batch_size), 16))
    ocr_results, ocr_timings, ocr_environment = _run_isolated_ocr(
        pending_ocr,
        ocr_python,
        batch_size,
        int(args.ocr_timeout_seconds),
    )
    for item in pending_ocr:
        result = ocr_results[str(item["crop_id"])]
        raw_text = str(result["raw_text"])
        score = float(result["score"])
        parsed = parse_plate_text(
            raw_text,
            bilingual_mapping=mapping,
            require_verified_bilingual=True,
        )
        item["record"]["ocr_candidates"].append(
            {
                "view_id": item["view_id"],
                "variant_id": item["variant_id"],
                "raw_text": raw_text,
                "ocr_confidence": score,
                "detector_confidence": item["detector_confidence"],
                "quality_passed": item["quality_passed"],
                "canonical": parsed.canonical,
                "parse_valid": parsed.valid,
                "parse_reasons": list(parsed.reasons),
            }
        )

    grouped_records: dict[str, list[dict[str, Any]]] = defaultdict(list)
    for record in image_records:
        grouped_records[str(record["group_id"])].append(record)

    group_rows: list[dict[str, Any]] = []
    for group_id, records in sorted(grouped_records.items()):
        candidates: list[ReadingCandidate] = []
        target_values = {str(row["target"]) for row in records if row.get("target")}
        if len(target_values) > 1:
            raise ProtocolError(f"Cibles contradictoires pour le groupe {group_id!r}.")
        target = next(iter(target_values), None)
        raw_ranked: list[tuple[float, str]] = []
        for record in records:
            for candidate in record["ocr_candidates"]:
                candidates.append(
                    ReadingCandidate(
                        view_id=str(candidate["view_id"]),
                        variant_id=str(candidate["variant_id"]),
                        raw_text=str(candidate["raw_text"]),
                        ocr_confidence=float(candidate["ocr_confidence"]),
                        detector_confidence=float(candidate["detector_confidence"]),
                        quality_passed=bool(candidate["quality_passed"]),
                    )
                )
                if candidate["canonical"] and candidate["quality_passed"]:
                    raw_ranked.append(
                        (
                            float(candidate["ocr_confidence"])
                            * float(candidate["detector_confidence"]),
                            str(candidate["canonical"]),
                        )
                    )
        consensus = select_consensus(candidates, bilingual_mapping=mapping)
        group_rows.append(
            {
                "group_id": group_id,
                "target": target,
                "raw_prediction": max(raw_ranked)[1] if raw_ranked else None,
                "accepted_prediction": consensus.canonical if consensus.accepted else None,
                "accepted": consensus.accepted,
                "consensus_confidence": consensus.confidence,
                "supporting_views": list(consensus.supporting_views),
                "decision_reason": consensus.reason,
            }
        )

    with private_predictions_path.open("x", encoding="utf-8", newline="\n") as handle:
        for record in image_records:
            handle.write(json.dumps(_json_safe(record), ensure_ascii=False, sort_keys=True) + "\n")
        for record in group_rows:
            handle.write(
                json.dumps(
                    {"record_type": "group_consensus", **_json_safe(record)},
                    ensure_ascii=False,
                    sort_keys=True,
                )
                + "\n"
            )

    metrics = aggregate_smoke_metrics(group_rows, detection_ious)
    summary = {
        "schema_version": SMOKE_VERSION,
        "status": "smoke_complete_not_qualified",
        "qualification_claim": False,
        "final_test_opened": False,
        "detector": {
            "candidate": selection.candidate,
            "threshold": selection.threshold,
            "selection_role": selection.role,
            "checkpoint_sha256": checkpoint_digest,
            **detector_configuration,
        },
        "ocr": {
            "model_name": OCR_MODEL_NAME,
            "bilingual_mapping_verified": mapping is not None,
            "runtime_isolated": True,
            "device": "gpu:0",
        },
        "counts": {
            "images": len(images),
            "ocr_crops": len(pending_ocr),
            "groups": len(group_rows),
        },
        "metrics": metrics,
        "timings_seconds": {
            "detector_load": detector_load_seconds,
            "detector_inference_total": detector_seconds,
            "ocr_load": float(ocr_timings["ocr_load"]),
            "ocr_inference_total": float(ocr_timings["ocr_inference_total"]),
            "wall_total": time.perf_counter() - started,
        },
        "environment": {
            "detector_process": _detector_environment(),
            "ocr_process": _json_safe(ocr_environment),
            "separate_python_environments": True,
        },
        "privacy": {
            "predictions_file": private_predictions_path.name,
            "predictions_must_remain_private": True,
            "github_publication_allowed": False,
        },
    }
    summary_path = output / "SMOKE_COMPLETE.json"
    summary_path.write_text(
        json.dumps(_json_safe(summary), ensure_ascii=False, indent=2, sort_keys=True) + "\n",
        encoding="utf-8",
    )
    return summary


def build_parser() -> argparse.ArgumentParser:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--input-dir", required=True)
    parser.add_argument("--checkpoint", required=True)
    parser.add_argument("--selection", required=True)
    parser.add_argument("--ocr-python", required=True)
    parser.add_argument("--output-dir", required=True)
    parser.add_argument("--labels")
    parser.add_argument("--series-mapping")
    parser.add_argument("--max-images", type=int, default=24)
    parser.add_argument("--ocr-batch-size", type=int, default=8)
    parser.add_argument("--ocr-timeout-seconds", type=int, default=900)
    return parser


def main() -> int:
    args = build_parser().parse_args()
    summary = run_smoke(args)
    public_console = {
        "status": summary["status"],
        "qualification_claim": summary["qualification_claim"],
        "counts": summary["counts"],
        "metrics": summary["metrics"],
        "timings_seconds": summary["timings_seconds"],
    }
    print(json.dumps(public_console, ensure_ascii=False, indent=2, sort_keys=True))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
