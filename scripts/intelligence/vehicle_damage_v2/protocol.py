"""Fail-closed safeguards for the RT-DETRv2 vehicle-damage experiment.

This module intentionally depends only on the Python standard library. GitHub
Actions can therefore validate data contracts, test isolation, source gates and
release criteria without downloading a dataset or a model.
"""

from __future__ import annotations

import csv
import hashlib
import json
import math
import re
from dataclasses import dataclass
from pathlib import Path, PurePosixPath
from typing import Any, Iterable, Mapping, Sequence


PROTOCOL_VERSION = "2.0.0"
UPSTREAM_REPOSITORY = "https://github.com/lyuwenyu/RT-DETR.git"
UPSTREAM_COMMIT = "068dfde65f2667ad6555883c69d73de886518cad"
LEGACY_TEST_SPLIT = "test"
TRAINING_SPLITS = frozenset({"train", "validation", "calibration"})
ALL_SPLITS = frozenset({*TRAINING_SPLITS, LEGACY_TEST_SPLIT})
SHA256_RE = re.compile(r"^[0-9a-f]{64}$")

REQUIRED_LEGACY_COLUMNS = (
    "label",
    "group_id",
    "source_id",
    "source_url",
    "license_id",
    "license_status",
    "license_proof",
    "split",
    "source_image_sha256",
    "source_image_name",
    "source_annotation",
)

HITL_SOURCE_ID = "hitl_car_parts_damage"
HITL_SOURCE_URL = (
    "https://humansintheloop.org/resources/datasets/"
    "car-parts-and-car-damages-dataset/"
)
HITL_LICENSE_ID = "CC0-1.0"

RELEASE_MINIMUMS = {
    "bbox_ap": 0.40,
    "bbox_ap50": 0.65,
    "photo_macro_f1": 0.90,
    "photo_balanced_accuracy": 0.90,
    "accepted_damage_precision": 0.95,
    "photo_damage_recall": 0.85,
    "accepted_coverage": 0.50,
}
RELEASE_MAXIMUMS = {"ece": 0.05}


class ProtocolError(ValueError):
    """Raised when an operation would invalidate the v2 protocol."""


@dataclass(frozen=True)
class SourceImage:
    """One original image and its immutable legacy split assignment."""

    sha256: str
    split: str
    image_name: str
    annotation_path: str
    group_id: str


@dataclass(frozen=True)
class GateResult:
    passed: bool
    reasons: tuple[str, ...]

    def as_dict(self) -> dict[str, object]:
        return {"passed": self.passed, "reasons": list(self.reasons)}


def file_sha256(path: str | Path, chunk_size: int = 1024 * 1024) -> str:
    digest = hashlib.sha256()
    with Path(path).open("rb") as handle:
        while chunk := handle.read(chunk_size):
            digest.update(chunk)
    return digest.hexdigest()


def load_csv(path: str | Path) -> list[dict[str, str]]:
    with Path(path).open("r", encoding="utf-8", newline="") as handle:
        reader = csv.DictReader(handle)
        if reader.fieldnames is None:
            raise ProtocolError("Le manifeste CSV ne contient pas d'en-tête.")
        missing = [name for name in REQUIRED_LEGACY_COLUMNS if name not in reader.fieldnames]
        if missing:
            raise ProtocolError(f"Colonnes v1.1 absentes: {', '.join(missing)}")
        return [
            {key: (value or "").strip() for key, value in row.items()}
            for row in reader
        ]


def _safe_relative(value: str, field: str) -> str:
    path = PurePosixPath(value)
    if not value or path.is_absolute() or ".." in path.parts:
        raise ProtocolError(f"{field} doit être un chemin relatif sûr.")
    return value


def derive_source_images(rows: Sequence[Mapping[str, str]]) -> dict[str, SourceImage]:
    """Collapse patch rows to original images while preserving the frozen split."""

    if not rows:
        raise ProtocolError("Le manifeste v1.1 est vide.")

    records: dict[str, SourceImage] = {}
    positive_by_source: dict[str, bool] = {}
    for line, row in enumerate(rows, start=2):
        missing = [name for name in REQUIRED_LEGACY_COLUMNS if not str(row.get(name, "")).strip()]
        if missing:
            raise ProtocolError(
                f"Ligne {line}: valeurs v1.1 absentes: {', '.join(missing)}"
            )

        source_id = str(row["source_id"])
        if source_id != HITL_SOURCE_ID:
            raise ProtocolError(f"Ligne {line}: source inattendue {source_id!r}.")
        if str(row["source_url"]) != HITL_SOURCE_URL:
            raise ProtocolError(f"Ligne {line}: URL HITL non officielle.")
        if str(row["license_id"]) != HITL_LICENSE_ID:
            raise ProtocolError(f"Ligne {line}: licence HITL incohérente.")
        if str(row["license_status"]).lower() != "approved":
            raise ProtocolError(f"Ligne {line}: licence HITL non approuvée.")
        _safe_relative(str(row["license_proof"]), "license_proof")

        digest = str(row["source_image_sha256"]).lower()
        if not SHA256_RE.fullmatch(digest):
            raise ProtocolError(f"Ligne {line}: source_image_sha256 invalide.")
        split = str(row["split"])
        if split not in ALL_SPLITS:
            raise ProtocolError(f"Ligne {line}: split inconnu {split!r}.")

        group_id = str(row["group_id"])
        if group_id != digest:
            raise ProtocolError(
                f"Ligne {line}: group_id doit être l'empreinte de l'image source."
            )
        image_name = str(row["source_image_name"])
        if PurePosixPath(image_name).name != image_name:
            raise ProtocolError(f"Ligne {line}: source_image_name doit être un nom de fichier.")
        annotation_path = _safe_relative(
            str(row["source_annotation"]), "source_annotation"
        )
        candidate = SourceImage(
            sha256=digest,
            split=split,
            image_name=image_name,
            annotation_path=annotation_path,
            group_id=group_id,
        )
        previous = records.setdefault(digest, candidate)
        if previous != candidate:
            raise ProtocolError(
                f"Image source {digest[:12]} présente dans plusieurs splits ou descriptions."
            )
        positive_by_source[digest] = positive_by_source.get(digest, False) or str(
            row["label"]
        ) == "1"

    missing_positive = sorted(
        digest for digest, has_positive in positive_by_source.items() if not has_positive
    )
    if missing_positive:
        raise ProtocolError(
            "Images sans patch dommage attesté: " + ", ".join(missing_positive[:5])
        )
    if set(record.split for record in records.values()) != ALL_SPLITS:
        raise ProtocolError("Le mapping source doit conserver les quatre splits v1.1.")
    return records


def assert_training_splits(splits: Iterable[str]) -> tuple[str, ...]:
    normalized = tuple(dict.fromkeys(str(split) for split in splits))
    if not normalized:
        raise ProtocolError("Au moins un split d'entraînement est requis.")
    unknown = sorted(set(normalized) - TRAINING_SPLITS)
    if unknown:
        raise ProtocolError(
            "Le test v1.1 est verrouillé et ne peut pas être préparé pendant "
            f"l'entraînement: {', '.join(unknown)}"
        )
    return normalized


def polygon_area(points: Sequence[Sequence[float]]) -> float:
    if len(points) < 3:
        return 0.0
    return abs(
        sum(
            float(points[index][0]) * float(points[(index + 1) % len(points)][1])
            - float(points[(index + 1) % len(points)][0])
            * float(points[index][1])
            for index in range(len(points))
        )
    ) / 2.0


def validate_coco_document(document: Mapping[str, Any], split: str) -> dict[str, int]:
    if split not in TRAINING_SPLITS:
        raise ProtocolError("La validation COCO publique refuse le split test verrouillé.")
    categories = document.get("categories")
    if categories != [{"id": 0, "name": "visible_damage"}]:
        raise ProtocolError("L'ontologie COCO v2 doit contenir une seule classe d'identifiant 0.")
    images = document.get("images")
    annotations = document.get("annotations")
    if not isinstance(images, list) or not images:
        raise ProtocolError(f"Le split {split} ne contient aucune image.")
    if not isinstance(annotations, list) or not annotations:
        raise ProtocolError(f"Le split {split} ne contient aucune annotation dommage.")

    image_by_id: dict[int, Mapping[str, Any]] = {}
    source_hashes: set[str] = set()
    for image in images:
        image_id = image.get("id")
        if not isinstance(image_id, int) or image_id in image_by_id:
            raise ProtocolError("Identifiant image COCO invalide ou dupliqué.")
        width, height = image.get("width"), image.get("height")
        if not isinstance(width, int) or not isinstance(height, int) or min(width, height) < 1:
            raise ProtocolError("Dimensions COCO invalides.")
        file_name = _safe_relative(str(image.get("file_name", "")), "file_name")
        if PurePosixPath(file_name).parts[0] != split:
            raise ProtocolError("Le chemin COCO doit commencer par son split.")
        digest = str(image.get("source_sha256", "")).lower()
        if not SHA256_RE.fullmatch(digest) or digest in source_hashes:
            raise ProtocolError("Empreinte source COCO invalide ou dupliquée.")
        if image.get("split") != split:
            raise ProtocolError("Le champ split COCO ne correspond pas au fichier.")
        source_hashes.add(digest)
        image_by_id[image_id] = image

    annotation_ids: set[int] = set()
    for annotation in annotations:
        annotation_id = annotation.get("id")
        if not isinstance(annotation_id, int) or annotation_id in annotation_ids:
            raise ProtocolError("Identifiant annotation COCO invalide ou dupliqué.")
        annotation_ids.add(annotation_id)
        if annotation.get("category_id") != 0 or annotation.get("iscrowd") != 0:
            raise ProtocolError("Classe COCO ou iscrowd invalide.")
        image = image_by_id.get(annotation.get("image_id"))
        if image is None:
            raise ProtocolError("Annotation COCO orpheline.")
        bbox = annotation.get("bbox")
        if not isinstance(bbox, list) or len(bbox) != 4:
            raise ProtocolError("bbox COCO invalide.")
        if not all(isinstance(value, (int, float)) and math.isfinite(value) for value in bbox):
            raise ProtocolError("bbox COCO non finie.")
        x, y, width, height = (float(value) for value in bbox)
        if x < 0 or y < 0 or width <= 0 or height <= 0:
            raise ProtocolError("bbox COCO dégénérée.")
        if x + width > int(image["width"]) + 1e-6 or y + height > int(image["height"]) + 1e-6:
            raise ProtocolError("bbox COCO hors image.")
        area = annotation.get("area")
        if not isinstance(area, (int, float)) or not math.isfinite(area) or area <= 0:
            raise ProtocolError("Aire COCO invalide.")

    return {"images": len(images), "annotations": len(annotations)}


def evaluate_release_gate(metrics: Mapping[str, float | int]) -> GateResult:
    """Evaluate the end-to-end photo-level gate; missing evidence fails closed."""

    reasons: list[str] = []
    for name, minimum in RELEASE_MINIMUMS.items():
        value = metrics.get(name)
        if not isinstance(value, (int, float)) or not math.isfinite(float(value)):
            reasons.append(f"{name} absent ou non fini")
        elif float(value) < minimum:
            reasons.append(f"{name}={float(value):.4f} < {minimum:.4f}")
    for name, maximum in RELEASE_MAXIMUMS.items():
        value = metrics.get(name)
        if not isinstance(value, (int, float)) or not math.isfinite(float(value)):
            reasons.append(f"{name} absent ou non fini")
        elif float(value) > maximum:
            reasons.append(f"{name}={float(value):.4f} > {maximum:.4f}")

    clean_count = metrics.get("verified_clean_photo_count")
    if not isinstance(clean_count, int) or clean_count < 200:
        reasons.append("verified_clean_photo_count doit être >= 200")
    source_count = metrics.get("domain_source_count")
    if not isinstance(source_count, int) or source_count < 2:
        reasons.append("domain_source_count doit être >= 2")
    return GateResult(passed=not reasons, reasons=tuple(reasons))


def claim_once_only_test(lock_path: str | Path, manifest_sha256: str) -> None:
    """Atomically create the one-time evaluation lock before reading test data."""

    digest = manifest_sha256.lower()
    if not SHA256_RE.fullmatch(digest):
        raise ProtocolError("Empreinte du manifeste de test invalide.")
    path = Path(lock_path)
    path.parent.mkdir(parents=True, exist_ok=True)
    payload = json.dumps(
        {
            "protocol_version": PROTOCOL_VERSION,
            "manifest_sha256": digest,
            "state": "claimed_before_test_read",
        },
        ensure_ascii=False,
        indent=2,
        sort_keys=True,
    ) + "\n"
    try:
        with path.open("x", encoding="utf-8") as handle:
            handle.write(payload)
    except FileExistsError as error:
        raise ProtocolError("Le test final v2 a déjà été réclamé ou évalué.") from error

