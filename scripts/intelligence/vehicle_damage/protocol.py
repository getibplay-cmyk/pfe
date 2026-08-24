"""Pure-Python safeguards for the vehicle-damage scientific protocol.

This module deliberately has no machine-learning dependency so that GitHub CI can
verify provenance, split isolation, and release gates without downloading a model.
"""

from __future__ import annotations

import csv
import hashlib
import json
import random
import re
from collections import defaultdict
from dataclasses import dataclass
from pathlib import Path, PurePosixPath
from typing import Iterable, Iterator, Mapping, Sequence


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
RUN_COMPLETE_NAME = "RUN_COMPLETE.json"
TEST_LOCK_NAME = "TEST_EVALUATION_LOCK.json"


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


def file_sha256(path: str | Path, chunk_size: int = 1024 * 1024) -> str:
    digest = hashlib.sha256()
    with Path(path).open("rb") as handle:
        while chunk := handle.read(chunk_size):
            digest.update(chunk)
    return digest.hexdigest()


def verify_manifest_files(
    rows: Sequence[Mapping[str, str]],
    data_root: str | Path,
    license_root: str | Path,
) -> None:
    data_root_path = Path(data_root)
    license_root_path = Path(license_root)
    missing_images: list[str] = []
    hash_mismatches: list[str] = []

    for row in rows:
        relative_path = str(row["image_path"])
        image_path = data_root_path / relative_path
        if not image_path.is_file():
            missing_images.append(relative_path)
            continue
        if file_sha256(image_path) != str(row["sha256"]).lower():
            hash_mismatches.append(relative_path)

    if missing_images:
        preview = ", ".join(missing_images[:5])
        raise FileNotFoundError(f"Images absentes ({len(missing_images)}): {preview}")
    if hash_mismatches:
        preview = ", ".join(hash_mismatches[:5])
        raise ProtocolError(
            f"Empreinte SHA-256 différente du manifeste ({len(hash_mismatches)}): {preview}"
        )

    proof_paths = sorted({str(row["license_proof"]) for row in rows})
    missing_proofs = [proof for proof in proof_paths if not (license_root_path / proof).is_file()]
    if missing_proofs:
        raise FileNotFoundError(f"Preuves de licence absentes: {', '.join(missing_proofs)}")


def grouped_bootstrap_indices(
    group_ids: Sequence[str], iterations: int, seed: int
) -> Iterator[tuple[int, ...]]:
    if iterations < 1:
        raise ValueError("iterations doit être >= 1")
    if not group_ids:
        raise ValueError("group_ids ne peut pas être vide")

    group_to_indices: dict[str, list[int]] = defaultdict(list)
    for index, group_id in enumerate(group_ids):
        group_to_indices[str(group_id)].append(index)
    groups = sorted(group_to_indices)
    rng = random.Random(seed)
    for _ in range(iterations):
        selected_groups = rng.choices(groups, k=len(groups))
        yield tuple(
            index
            for group_id in selected_groups
            for index in group_to_indices[group_id]
        )


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


def verify_evidence_checksums(output: str | Path) -> None:
    output_path = Path(output)
    checksum_path = output_path / "SHA256SUMS"
    if not checksum_path.is_file():
        raise ProtocolError("SHA256SUMS absent; le run n'est pas attesté comme terminé.")

    for line_number, line in enumerate(
        checksum_path.read_text(encoding="utf-8").splitlines(), start=1
    ):
        digest, separator, relative_name = line.partition("  ")
        relative_path = PurePosixPath(relative_name)
        if (
            separator != "  "
            or not SHA256_RE.fullmatch(digest)
            or not relative_name
            or relative_path.is_absolute()
            or ".." in relative_path.parts
        ):
            raise ProtocolError(f"SHA256SUMS invalide à la ligne {line_number}.")
        artifact = output_path / relative_path
        if not artifact.is_file() or file_sha256(artifact) != digest:
            raise ProtocolError(f"Artefact absent ou modifié: {relative_name}")


def read_completed_run(output: str | Path) -> bool | None:
    output_path = Path(output)
    marker_path = output_path / RUN_COMPLETE_NAME
    metrics_path = output_path / "metrics.json"
    checksum_path = output_path / "SHA256SUMS"

    if marker_path.is_file():
        marker = json.loads(marker_path.read_text(encoding="utf-8"))
        expected_checksum = str(marker.get("sha256sums_sha256", ""))
        if not checksum_path.is_file() or file_sha256(checksum_path) != expected_checksum:
            raise ProtocolError("Le marqueur de fin ne correspond pas à SHA256SUMS.")
        verify_evidence_checksums(output_path)
        passed = marker.get("release_gate_passed")
        if not isinstance(passed, bool) or marker.get("test_evaluated_once") is not True:
            raise ProtocolError("RUN_COMPLETE.json est invalide.")
        return passed

    # Compatibility guard for the already qualified v1.1 run, created before
    # RUN_COMPLETE.json existed. Its frozen metrics and full checksum inventory
    # are sufficient to prevent a second test evaluation.
    if metrics_path.is_file() and checksum_path.is_file():
        verify_evidence_checksums(output_path)
        metrics = json.loads(metrics_path.read_text(encoding="utf-8"))
        passed = metrics.get("release_gate", {}).get("passed")
        if not isinstance(passed, bool):
            raise ProtocolError("metrics.json ne contient pas un release gate valide.")
        return passed
    return None


def write_run_completion(output: str | Path, passed: bool) -> None:
    output_path = Path(output)
    checksum_path = output_path / "SHA256SUMS"
    if not checksum_path.is_file():
        raise ProtocolError("Impossible de terminer le run sans SHA256SUMS.")
    write_json(
        output_path / RUN_COMPLETE_NAME,
        {
            "protocol_version": PROTOCOL_VERSION,
            "release_gate_passed": bool(passed),
            "sha256sums_sha256": file_sha256(checksum_path),
            "test_evaluated_once": True,
        },
    )


def remove_stale_model_export(output: str | Path) -> None:
    (Path(output) / "model.onnx").unlink(missing_ok=True)


def sha256sum_lines(paths: Iterable[Path], relative_to: Path) -> list[str]:
    lines: list[str] = []
    for path in sorted(paths):
        digest = file_sha256(path)
        lines.append(f"{digest}  {path.relative_to(relative_to).as_posix()}")
    return lines
