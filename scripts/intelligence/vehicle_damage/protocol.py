"""Pure-Python safeguards for the vehicle-damage scientific protocol.

This module deliberately has no machine-learning dependency so that GitHub CI can
verify provenance, split isolation, and release gates without downloading a model.
"""

from __future__ import annotations

import csv
import json
import re
from dataclasses import dataclass
from pathlib import Path, PurePosixPath
from typing import Iterable, Mapping, Sequence


PROTOCOL_VERSION = "1.0.0"
ALLOWED_SPLITS = frozenset({"train", "validation", "calibration", "test"})
REQUIRED_COLUMNS = (
    "image_path",
    "label",
    "group_id",
    "source_id",
    "source_url",
    "license_id",
    "license_status",
    "license_proof",
    "sha256",
    "split",
)
SHA256_RE = re.compile(r"^[0-9a-f]{64}$")
SOURCE_POLICIES = {
    "hitl_car_parts_damage": {
        "url_prefix": "https://humansintheloop.org/resources/datasets/car-parts-and-car-damages-dataset/",
        "license_id": "CC0-1.0",
    },
    "cardd": {
        "url_prefix": "https://cardd-ustc.github.io/",
        "license_id": "CarDD-academic-consent",
    },
    "tqvcd": {
        "url_prefix": "https://github.com/dxlabskku/TQVCD",
        "license_id": "TQVCD-author-consent",
    },
}

# User-approved floor: >= 75%. The recall floor is kept at the same explicit
# value; the protocol document records 90% as the PFE target, not as a hidden gate.
MINIMUM_RELEASE_METRICS = {
    "balanced_accuracy": 0.75,
    "macro_f1": 0.75,
    "damage_recall": 0.75,
}
MAXIMUM_RELEASE_METRICS = {"ece": 0.08}


class ProtocolError(ValueError):
    """Raised when an input would invalidate the preregistered experiment."""


@dataclass(frozen=True)
class ManifestReport:
    rows: int
    split_counts: Mapping[str, int]
    label_counts: Mapping[str, int]
    source_counts: Mapping[str, int]

    def as_dict(self) -> dict[str, object]:
        return {
            "protocol_version": PROTOCOL_VERSION,
            "rows": self.rows,
            "split_counts": dict(self.split_counts),
            "label_counts": dict(self.label_counts),
            "source_counts": dict(self.source_counts),
        }


@dataclass(frozen=True)
class GateResult:
    passed: bool
    reasons: tuple[str, ...]

    def as_dict(self) -> dict[str, object]:
        return {"passed": self.passed, "reasons": list(self.reasons)}


def load_manifest(path: str | Path) -> list[dict[str, str]]:
    manifest_path = Path(path)
    with manifest_path.open("r", encoding="utf-8", newline="") as handle:
        reader = csv.DictReader(handle)
        if reader.fieldnames is None:
            raise ProtocolError("Le manifeste CSV ne contient pas d'en-tête.")
        missing = [column for column in REQUIRED_COLUMNS if column not in reader.fieldnames]
        if missing:
            raise ProtocolError(f"Colonnes obligatoires absentes: {', '.join(missing)}")
        return [{key: (value or "").strip() for key, value in row.items()} for row in reader]


def validate_manifest(rows: Sequence[Mapping[str, str]]) -> ManifestReport:
    if not rows:
        raise ProtocolError("Le manifeste est vide.")

    split_counts = {split: 0 for split in sorted(ALLOWED_SPLITS)}
    split_label_counts = {split: {"0": 0, "1": 0} for split in sorted(ALLOWED_SPLITS)}
    label_counts = {"0": 0, "1": 0}
    source_counts: dict[str, int] = {}
    source_split_label_counts: dict[str, dict[str, dict[str, int]]] = {}
    group_to_split: dict[str, str] = {}
    seen_hashes: dict[str, str] = {}

    for index, row in enumerate(rows, start=2):
        missing = [column for column in REQUIRED_COLUMNS if not str(row.get(column, "")).strip()]
        if missing:
            raise ProtocolError(f"Ligne {index}: valeurs obligatoires absentes: {', '.join(missing)}")

        label = str(row["label"])
        if label not in label_counts:
            raise ProtocolError(f"Ligne {index}: label {label!r}; seules les valeurs 0 et 1 sont permises.")

        split = str(row["split"])
        if split not in ALLOWED_SPLITS:
            raise ProtocolError(f"Ligne {index}: split inconnu {split!r}.")

        license_status = str(row["license_status"]).lower()
        if license_status != "approved":
            raise ProtocolError(
                f"Ligne {index}: licence non approuvée ({license_status!r}); aucune image ne peut être utilisée."
            )

        source_url = str(row["source_url"])
        if not source_url.startswith("https://"):
            raise ProtocolError(f"Ligne {index}: source_url doit être une URL HTTPS officielle.")

        source_id = str(row["source_id"])
        source_policy = SOURCE_POLICIES.get(source_id)
        if source_policy is None:
            raise ProtocolError(f"Ligne {index}: source non préenregistrée {source_id!r}.")
        if not source_url.startswith(source_policy["url_prefix"]):
            raise ProtocolError(f"Ligne {index}: URL non officielle pour la source {source_id!r}.")
        if str(row["license_id"]) != source_policy["license_id"]:
            raise ProtocolError(f"Ligne {index}: licence incohérente pour la source {source_id!r}.")

        relative_path = PurePosixPath(str(row["image_path"]))
        if relative_path.is_absolute() or ".." in relative_path.parts:
            raise ProtocolError(f"Ligne {index}: image_path doit rester relatif au dossier privé préparé.")

        proof_path = PurePosixPath(str(row["license_proof"]))
        if proof_path.is_absolute() or ".." in proof_path.parts:
            raise ProtocolError(f"Ligne {index}: license_proof doit être un chemin relatif et privé.")

        digest = str(row["sha256"]).lower()
        if not SHA256_RE.fullmatch(digest):
            raise ProtocolError(f"Ligne {index}: SHA-256 invalide.")
        if digest in seen_hashes:
            raise ProtocolError(
                f"Ligne {index}: doublon exact avec {seen_hashes[digest]}; le split final serait contaminé."
            )
        seen_hashes[digest] = str(row["image_path"])

        group_id = str(row["group_id"])
        previous_split = group_to_split.setdefault(group_id, split)
        if previous_split != split:
            raise ProtocolError(
                f"Ligne {index}: fuite de groupe {group_id!r} entre {previous_split!r} et {split!r}."
            )

        source_counts[source_id] = source_counts.get(source_id, 0) + 1
        source_split_label_counts.setdefault(
            source_id,
            {name: {"0": 0, "1": 0} for name in sorted(ALLOWED_SPLITS)},
        )[split][label] += 1
        split_counts[split] += 1
        split_label_counts[split][label] += 1
        label_counts[label] += 1

    empty_splits = [split for split, count in split_counts.items() if count == 0]
    if empty_splits:
        raise ProtocolError(f"Splits vides: {', '.join(empty_splits)}")
    one_class_splits = [
        split
        for split, counts in split_label_counts.items()
        if counts["0"] == 0 or counts["1"] == 0
    ]
    if one_class_splits:
        raise ProtocolError(f"Splits sans les deux labels: {', '.join(one_class_splits)}")
    incomplete_source_splits = [
        f"{source_id}/{split}"
        for source_id, source_splits in source_split_label_counts.items()
        for split, counts in source_splits.items()
        if counts["0"] == 0 or counts["1"] == 0
    ]
    if incomplete_source_splits:
        raise ProtocolError(
            "Chaque source doit contenir les deux labels dans chaque split: "
            + ", ".join(incomplete_source_splits)
        )
    empty_labels = [label for label, count in label_counts.items() if count == 0]
    if empty_labels:
        raise ProtocolError(f"Labels absents: {', '.join(empty_labels)}")

    return ManifestReport(
        rows=len(rows),
        split_counts=split_counts,
        label_counts=label_counts,
        source_counts=dict(sorted(source_counts.items())),
    )


def evaluate_release_gate(metrics: Mapping[str, float]) -> GateResult:
    reasons: list[str] = []
    for name, floor in MINIMUM_RELEASE_METRICS.items():
        if name not in metrics:
            reasons.append(f"métrique absente: {name}")
            continue
        value = float(metrics[name])
        if value < floor:
            reasons.append(f"{name}={value:.4f} < {floor:.2f}")

    for name, ceiling in MAXIMUM_RELEASE_METRICS.items():
        if name not in metrics:
            reasons.append(f"métrique absente: {name}")
            continue
        value = float(metrics[name])
        if value > ceiling:
            reasons.append(f"{name}={value:.4f} > {ceiling:.2f}")

    return GateResult(passed=not reasons, reasons=tuple(reasons))


def write_json(path: str | Path, payload: Mapping[str, object]) -> None:
    destination = Path(path)
    destination.parent.mkdir(parents=True, exist_ok=True)
    destination.write_text(
        json.dumps(payload, ensure_ascii=False, indent=2, sort_keys=True) + "\n",
        encoding="utf-8",
    )


def sha256sum_lines(paths: Iterable[Path], relative_to: Path) -> list[str]:
    import hashlib

    lines: list[str] = []
    for path in sorted(paths):
        digest = hashlib.sha256(path.read_bytes()).hexdigest()
        lines.append(f"{digest}  {path.relative_to(relative_to).as_posix()}")
    return lines
