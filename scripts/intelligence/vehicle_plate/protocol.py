"""Dependency-free safeguards for Moroccan plate detection and recognition.

The GPU notebook imports this module, while GitHub CI can validate the protocol
without downloading private images, detector weights, or OCR checkpoints.
"""

from __future__ import annotations

import csv
import hashlib
import json
import random
import re
import unicodedata
from collections import defaultdict
from dataclasses import dataclass
from pathlib import Path, PurePosixPath
from typing import Iterable, Iterator, Mapping, Sequence


PROTOCOL_VERSION = "2.0.0"
ALLOWED_SPLITS = frozenset({"train", "validation", "calibration", "test"})
ALLOWED_TASKS = frozenset({"detection", "recognition", "end_to_end"})
ALLOWED_HOLDOUT_ROLES = frozenset({"development", "independent"})
SHA256_RE = re.compile(r"^[0-9a-f]{64}$")
TOKEN_RE = re.compile(r"[0-9]+|[A-Z]+|[\u0600-\u06ff]+")
ARABIC_RE = re.compile(r"^[\u0600-\u06ff]{1,2}$")
LATIN_RE = re.compile(r"^[A-Z]{1,2}$")

REQUIRED_COLUMNS = (
    "sample_id",
    "image_path",
    "group_id",
    "task",
    "target",
    "source_id",
    "source_url",
    "license_id",
    "license_status",
    "license_proof",
    "sha256",
    "split",
    "holdout_role",
)

# Only sources whose licence/provenance was already admitted by the S7 detector
# audit are public here. The UM6P 705-image corpus and real RentFleet photos are
# intentionally absent until their exact licence/consent gates are satisfied.
SOURCE_POLICIES = {
    "moroccan_vehicle_registration_plates_cc0_v2": {
        "license_id": "CC0-1.0",
        "tasks": frozenset({"detection"}),
    },
    "ayoub_alaoui_moroccan_plates_v2": {
        "license_id": "CC-BY-SA-4.0",
        "tasks": frozenset({"detection"}),
    },
    "keremberke_license_plate_object_detection_a51194c7": {
        "license_id": "CC-BY-4.0",
        "tasks": frozenset({"detection"}),
    },
    "synthetic_moroccan_plate_ofl_v2": {
        "license_id": "SYNTHETIC-OFL-1.1",
        "tasks": frozenset({"recognition", "end_to_end"}),
    },
}

MINIMUM_RELEASE_METRICS = {
    "detection_map50": 0.95,
    "detection_recall": 0.95,
    "ocr_full_plate_exact": 0.90,
    "selective_exact": 0.97,
    "selective_coverage": 0.70,
    "end_to_end_exact": 0.90,
}
MAXIMUM_RELEASE_METRICS = {"ocr_cer": 0.02}
STRETCH_END_TO_END_EXACT = 0.95

ARABIC_INDIC_DIGITS = "٠١٢٣٤٥٦٧٨٩"
EASTERN_ARABIC_INDIC_DIGITS = "۰۱۲۳۴۵۶۷۸۹"
DIGIT_TRANSLATION = str.maketrans(
    ARABIC_INDIC_DIGITS + EASTERN_ARABIC_INDIC_DIGITS,
    "0123456789" * 2,
)
IGNORED_DIRECTIONAL_MARKS = {
    "\u061c",
    "\u200b",
    "\u200c",
    "\u200d",
    "\u200e",
    "\u200f",
    "\u202a",
    "\u202b",
    "\u202c",
    "\u202d",
    "\u202e",
    "\u2066",
    "\u2067",
    "\u2068",
    "\u2069",
}
ALEF_EQUIVALENTS = str.maketrans({"ا": "أ", "إ": "أ", "آ": "أ", "ٱ": "أ"})


class ProtocolError(ValueError):
    """Raised when an operation would invalidate the preregistered protocol."""


@dataclass(frozen=True)
class PlateParse:
    valid: bool
    canonical: str | None
    serial: str | None
    series_arabic: str | None
    series_latin: str | None
    region: str | None
    format_version: str | None
    bilingual_consistency: str
    reasons: tuple[str, ...]


@dataclass(frozen=True)
class ReadingCandidate:
    view_id: str
    raw_text: str
    ocr_confidence: float
    detector_confidence: float
    quality_passed: bool = True
    variant_id: str = "original"


@dataclass(frozen=True)
class ConsensusResult:
    accepted: bool
    canonical: str | None
    confidence: float
    supporting_views: tuple[str, ...]
    reason: str


@dataclass(frozen=True)
class GateResult:
    passed: bool
    stretch_95_passed: bool
    reasons: tuple[str, ...]

    def as_dict(self) -> dict[str, object]:
        return {
            "passed": self.passed,
            "stretch_95_passed": self.stretch_95_passed,
            "reasons": list(self.reasons),
        }


@dataclass(frozen=True)
class ManifestReport:
    rows: int
    split_counts: Mapping[str, int]
    task_counts: Mapping[str, int]
    source_counts: Mapping[str, int]
    independent_test_rows: int


def file_sha256(path: str | Path, chunk_size: int = 1024 * 1024) -> str:
    digest = hashlib.sha256()
    with Path(path).open("rb") as handle:
        while chunk := handle.read(chunk_size):
            digest.update(chunk)
    return digest.hexdigest()


def normalize_ocr_text(value: str) -> str:
    """Normalize Unicode and digits without guessing a plate's component order."""

    normalized = unicodedata.normalize("NFKC", str(value)).translate(DIGIT_TRANSLATION)
    normalized = normalized.translate(ALEF_EQUIVALENTS).upper()
    return "".join(character for character in normalized if character not in IGNORED_DIRECTIONAL_MARKS)


def tokenize_plate_text(value: str) -> tuple[str, ...]:
    tokens = TOKEN_RE.findall(normalize_ocr_text(value))
    if len(tokens) > 3 and tokens and tokens[0] == "MA":
        tokens = tokens[1:]
    if len(tokens) > 3 and tokens and tokens[-1] == "MA":
        tokens = tokens[:-1]
    return tuple(tokens)


def _bilingual_consistency(
    series_arabic: str | None,
    series_latin: str | None,
    mapping: Mapping[str, str] | None,
) -> str:
    if not series_arabic or not series_latin:
        return "not_applicable"
    if mapping is None or any(character not in mapping for character in series_arabic):
        return "unverified"
    expected = "".join(str(mapping[character]).upper() for character in series_arabic)
    return "verified" if expected == series_latin else "mismatch"


def parse_plate_text(
    value: str,
    *,
    bilingual_mapping: Mapping[str, str] | None = None,
    require_verified_bilingual: bool = False,
) -> PlateParse:
    """Parse legacy, international, and 2026 bilingual Moroccan plate text.

    Two visual orders are accepted: serial-series-region and its complete
    reverse. Ambiguous short-number cases retain the visual left-to-right order
    and must be resolved by multi-view consensus or human confirmation.
    """

    normalized_value = normalize_ocr_text(value)
    raw_tokens = TOKEN_RE.findall(normalized_value)
    has_ma_marker = bool(raw_tokens and (raw_tokens[0] == "MA" or raw_tokens[-1] == "MA"))
    tokens = list(tokenize_plate_text(normalized_value))
    reasons: list[str] = []
    number_positions = [index for index, token in enumerate(tokens) if token.isdigit()]
    if len(number_positions) != 2:
        reasons.append("expected_exactly_two_numeric_components")
        return PlateParse(False, None, None, None, None, None, None, "not_applicable", tuple(reasons))

    left_index, right_index = number_positions
    if left_index == right_index or not (left_index < right_index):
        reasons.append("invalid_numeric_component_order")
    between = tokens[left_index + 1 : right_index]
    outside = tokens[:left_index] + tokens[right_index + 1 :]
    if outside:
        reasons.append("unexpected_tokens_outside_plate_components")
    if not between:
        reasons.append("missing_series_component")

    left_number = tokens[left_index]
    right_number = tokens[right_index]
    if len(left_number) >= 3 and len(right_number) <= 2:
        serial, region = left_number, right_number
    elif len(right_number) >= 3 and len(left_number) <= 2:
        serial, region = right_number, left_number
        between.reverse()
    else:
        serial, region = left_number, right_number

    if not (1 <= len(serial) <= 5) or int(serial) == 0:
        reasons.append("serial_must_be_between_1_and_99999")
    if not (1 <= len(region) <= 2) or not (1 <= int(region) <= 99):
        reasons.append("region_must_be_between_1_and_99")

    arabic_tokens = [token for token in between if ARABIC_RE.fullmatch(token)]
    latin_tokens = [token for token in between if LATIN_RE.fullmatch(token) and token != "MA"]
    unknown = [
        token
        for token in between
        if token not in arabic_tokens and token not in latin_tokens
    ]
    if unknown:
        reasons.append("unsupported_series_token")
    series_arabic = "".join(arabic_tokens) or None
    series_latin = "".join(latin_tokens) or None
    if not series_arabic and not series_latin:
        reasons.append("series_must_contain_arabic_or_latin_letters")
    if series_arabic and not (1 <= len(series_arabic) <= 2):
        reasons.append("arabic_series_length_must_be_one_or_two")
    if series_latin and not (1 <= len(series_latin) <= 2):
        reasons.append("latin_series_length_must_be_one_or_two")

    consistency = _bilingual_consistency(series_arabic, series_latin, bilingual_mapping)
    if consistency == "mismatch":
        reasons.append("bilingual_series_mismatch")
    if require_verified_bilingual and series_arabic and series_latin and consistency != "verified":
        reasons.append("bilingual_series_not_verified")
    if require_verified_bilingual and has_ma_marker and not (series_arabic and series_latin):
        reasons.append("ma_marker_requires_verified_bilingual_series")

    if series_arabic and series_latin:
        format_version = "unified_2026"
        canonical_series = series_arabic
    elif series_arabic:
        format_version = "legacy_arabic"
        canonical_series = series_arabic
    else:
        format_version = "international_latin"
        canonical_series = series_latin

    valid = not reasons
    canonical = f"{serial}|{canonical_series}|{region}" if valid else None
    return PlateParse(
        valid,
        canonical,
        serial,
        series_arabic,
        series_latin,
        region,
        format_version,
        consistency,
        tuple(reasons),
    )


def select_consensus(
    candidates: Sequence[ReadingCandidate],
    *,
    bilingual_mapping: Mapping[str, str] | None = None,
    min_ocr_confidence: float = 0.80,
    min_detector_confidence: float = 0.425,
    min_supporting_views: int = 2,
    single_view_confidence: float = 0.97,
    minimum_margin: float = 0.10,
) -> ConsensusResult:
    """Fuse independent photos and abstain when confidence or agreement is weak."""

    # Crop padding, contrast and rectification variants from the same physical
    # photo are correlated. Keep only the best variant per canonical reading
    # and physical view so preprocessing cannot manufacture a consensus.
    grouped_by_view: dict[str, dict[str, tuple[ReadingCandidate, float]]] = defaultdict(dict)
    for candidate in candidates:
        if not candidate.quality_passed:
            continue
        if candidate.ocr_confidence < min_ocr_confidence:
            continue
        if candidate.detector_confidence < min_detector_confidence:
            continue
        parsed = parse_plate_text(
            candidate.raw_text,
            bilingual_mapping=bilingual_mapping,
            require_verified_bilingual=True,
        )
        if not parsed.valid or parsed.canonical is None:
            continue
        score = float(candidate.ocr_confidence) * float(candidate.detector_confidence)
        current = grouped_by_view[parsed.canonical].get(candidate.view_id)
        if current is None or score > current[1]:
            grouped_by_view[parsed.canonical][candidate.view_id] = (candidate, score)

    if not grouped_by_view:
        return ConsensusResult(False, None, 0.0, (), "no_eligible_reading")

    grouped = {
        canonical: list(readings_by_view.values())
        for canonical, readings_by_view in grouped_by_view.items()
    }

    ranked = sorted(
        (
            (canonical, readings, sum(score for _, score in readings) / len(readings))
            for canonical, readings in grouped.items()
        ),
        key=lambda item: (len({candidate.view_id for candidate, _ in item[1]}), item[2]),
        reverse=True,
    )
    canonical, readings, confidence = ranked[0]
    views = tuple(sorted({candidate.view_id for candidate, _ in readings}))
    runner_up = ranked[1][2] if len(ranked) > 1 else 0.0
    if confidence - runner_up < minimum_margin:
        return ConsensusResult(False, None, confidence, views, "ambiguous_consensus")
    if len(views) >= min_supporting_views:
        return ConsensusResult(True, canonical, confidence, views, "multi_view_consensus")
    if len(views) == 1 and confidence >= single_view_confidence:
        return ConsensusResult(True, canonical, confidence, views, "high_confidence_single_view")
    return ConsensusResult(False, None, confidence, views, "insufficient_view_support")


def levenshtein_distance(left: str, right: str) -> int:
    previous = list(range(len(right) + 1))
    for left_index, left_character in enumerate(left, start=1):
        current = [left_index]
        for right_index, right_character in enumerate(right, start=1):
            current.append(
                min(
                    current[-1] + 1,
                    previous[right_index] + 1,
                    previous[right_index - 1] + (left_character != right_character),
                )
            )
        previous = current
    return previous[-1]


def character_error_rate(predictions: Sequence[str], targets: Sequence[str]) -> float:
    if len(predictions) != len(targets) or not targets:
        raise ValueError("predictions et targets doivent avoir la même taille non nulle")
    normalized_targets = [normalize_ocr_text(value) for value in targets]
    denominator = sum(len(value) for value in normalized_targets)
    if denominator == 0:
        raise ValueError("targets ne peut pas contenir uniquement des chaînes vides")
    errors = sum(
        levenshtein_distance(normalize_ocr_text(prediction), target)
        for prediction, target in zip(predictions, normalized_targets, strict=True)
    )
    return errors / denominator


def exact_match_accuracy(predictions: Sequence[str], targets: Sequence[str]) -> float:
    if len(predictions) != len(targets) or not targets:
        raise ValueError("predictions et targets doivent avoir la même taille non nulle")
    matches = sum(
        normalize_ocr_text(prediction) == normalize_ocr_text(target)
        for prediction, target in zip(predictions, targets, strict=True)
    )
    return matches / len(targets)


def evaluate_release_gate(
    metrics: Mapping[str, float],
    *,
    independent_test: bool,
    evaluation_count: int,
) -> GateResult:
    reasons: list[str] = []
    if not independent_test:
        reasons.append("Le jeu final n'est pas indépendant.")
    if evaluation_count != 1:
        reasons.append("Le jeu final doit être évalué exactement une fois.")

    for name, minimum in MINIMUM_RELEASE_METRICS.items():
        value = metrics.get(name)
        if value is None:
            reasons.append(f"Métrique absente: {name}.")
        elif not 0.0 <= float(value) <= 1.0:
            reasons.append(f"Métrique hors domaine [0,1]: {name}.")
        elif float(value) < minimum:
            reasons.append(f"{name}={float(value):.6f} < {minimum:.6f}.")
    for name, maximum in MAXIMUM_RELEASE_METRICS.items():
        value = metrics.get(name)
        if value is None:
            reasons.append(f"Métrique absente: {name}.")
        elif not 0.0 <= float(value) <= 1.0:
            reasons.append(f"Métrique hors domaine [0,1]: {name}.")
        elif float(value) > maximum:
            reasons.append(f"{name}={float(value):.6f} > {maximum:.6f}.")

    end_to_end = metrics.get("end_to_end_exact")
    stretch_passed = end_to_end is not None and float(end_to_end) >= STRETCH_END_TO_END_EXACT
    return GateResult(not reasons, stretch_passed, tuple(reasons))


def load_manifest(path: str | Path) -> list[dict[str, str]]:
    with Path(path).open("r", encoding="utf-8", newline="") as handle:
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
    task_counts = {task: 0 for task in sorted(ALLOWED_TASKS)}
    source_counts: dict[str, int] = {}
    group_to_split: dict[str, str] = {}
    seen_hashes: dict[str, str] = {}
    seen_sample_ids: set[str] = set()
    source_splits: dict[str, set[str]] = defaultdict(set)
    independent_test_rows = 0

    for line_number, row in enumerate(rows, start=2):
        missing = [column for column in REQUIRED_COLUMNS if not str(row.get(column, "")).strip()]
        if missing:
            raise ProtocolError(
                f"Ligne {line_number}: valeurs obligatoires absentes: {', '.join(missing)}"
            )

        sample_id = str(row["sample_id"])
        if sample_id in seen_sample_ids:
            raise ProtocolError(f"Ligne {line_number}: sample_id dupliqué {sample_id!r}.")
        seen_sample_ids.add(sample_id)

        task = str(row["task"])
        if task not in ALLOWED_TASKS:
            raise ProtocolError(f"Ligne {line_number}: tâche inconnue {task!r}.")
        split = str(row["split"])
        if split not in ALLOWED_SPLITS:
            raise ProtocolError(f"Ligne {line_number}: split inconnu {split!r}.")
        holdout_role = str(row["holdout_role"])
        if holdout_role not in ALLOWED_HOLDOUT_ROLES:
            raise ProtocolError(f"Ligne {line_number}: rôle holdout inconnu {holdout_role!r}.")
        if holdout_role == "independent" and split != "test":
            raise ProtocolError("Un échantillon indépendant appartient uniquement au split test.")

        source_id = str(row["source_id"])
        policy = SOURCE_POLICIES.get(source_id)
        if policy is None:
            raise ProtocolError(f"Ligne {line_number}: source non admise {source_id!r}.")
        if str(row["license_status"]).lower() != "approved":
            raise ProtocolError(f"Ligne {line_number}: licence non approuvée.")
        if str(row["license_id"]) != policy["license_id"]:
            raise ProtocolError(f"Ligne {line_number}: licence incohérente pour {source_id!r}.")
        if task not in policy["tasks"]:
            raise ProtocolError(
                f"Ligne {line_number}: la source {source_id!r} n'est pas annotée pour {task!r}."
            )
        if not str(row["source_url"]).startswith("https://"):
            raise ProtocolError(f"Ligne {line_number}: source_url doit être HTTPS.")

        image_path = PurePosixPath(str(row["image_path"]))
        proof_path = PurePosixPath(str(row["license_proof"]))
        if image_path.is_absolute() or ".." in image_path.parts:
            raise ProtocolError(f"Ligne {line_number}: image_path doit rester relatif.")
        if proof_path.is_absolute() or ".." in proof_path.parts:
            raise ProtocolError(f"Ligne {line_number}: license_proof doit rester relatif et privé.")

        digest = str(row["sha256"]).lower()
        if not SHA256_RE.fullmatch(digest):
            raise ProtocolError(f"Ligne {line_number}: SHA-256 invalide.")
        if digest in seen_hashes:
            raise ProtocolError(
                f"Ligne {line_number}: doublon exact avec {seen_hashes[digest]!r}."
            )
        seen_hashes[digest] = sample_id

        group_id = str(row["group_id"])
        previous_split = group_to_split.setdefault(group_id, split)
        if previous_split != split:
            raise ProtocolError(
                f"Ligne {line_number}: fuite de groupe {group_id!r} entre {previous_split!r} et {split!r}."
            )

        if task in {"recognition", "end_to_end"}:
            target = parse_plate_text(str(row["target"]))
            if not target.valid:
                raise ProtocolError(
                    f"Ligne {line_number}: cible OCR incompatible avec la grammaire: {target.reasons}."
                )

        split_counts[split] += 1
        task_counts[task] += 1
        source_counts[source_id] = source_counts.get(source_id, 0) + 1
        source_splits[source_id].add(split)
        if holdout_role == "independent":
            independent_test_rows += 1

    independent_sources = {
        source_id
        for source_id, splits in source_splits.items()
        if any(
            str(row["source_id"]) == source_id and str(row["holdout_role"]) == "independent"
            for row in rows
        )
    }
    contaminated = [
        source_id
        for source_id in sorted(independent_sources)
        if source_splits[source_id] != {"test"}
    ]
    if contaminated:
        raise ProtocolError(
            "Une source indépendante ne peut alimenter aucun split de développement: "
            + ", ".join(contaminated)
        )

    return ManifestReport(
        len(rows),
        split_counts,
        task_counts,
        source_counts,
        independent_test_rows,
    )


def verify_manifest_files(
    rows: Sequence[Mapping[str, str]],
    data_root: str | Path,
    license_root: str | Path,
) -> None:
    data_root_path = Path(data_root)
    license_root_path = Path(license_root)
    for row in rows:
        image_path = data_root_path / str(row["image_path"])
        if not image_path.is_file():
            raise FileNotFoundError(f"Image absente: {row['image_path']}")
        if file_sha256(image_path) != str(row["sha256"]).lower():
            raise ProtocolError(f"Empreinte image différente: {row['image_path']}")
        proof_path = license_root_path / str(row["license_proof"])
        if not proof_path.is_file():
            raise FileNotFoundError(f"Preuve de licence absente: {row['license_proof']}")


def grouped_bootstrap_indices(
    group_ids: Sequence[str], iterations: int, seed: int
) -> Iterator[tuple[int, ...]]:
    if iterations < 1 or not group_ids:
        raise ValueError("iterations doit être >= 1 et group_ids non vide")
    grouped: dict[str, list[int]] = defaultdict(list)
    for index, group_id in enumerate(group_ids):
        grouped[str(group_id)].append(index)
    groups = sorted(grouped)
    rng = random.Random(seed)
    for _ in range(iterations):
        selected = rng.choices(groups, k=len(groups))
        yield tuple(index for group_id in selected for index in grouped[group_id])


def write_test_lock(path: str | Path, *, manifest_sha256: str) -> None:
    lock_path = Path(path)
    if lock_path.exists():
        existing = json.loads(lock_path.read_text(encoding="utf-8"))
        if int(existing.get("evaluation_count", 0)) >= 1:
            raise ProtocolError("Le jeu final indépendant a déjà été évalué.")
    payload = {
        "protocol_version": PROTOCOL_VERSION,
        "manifest_sha256": manifest_sha256,
        "evaluation_count": 1,
        "labels_opened": True,
    }
    lock_path.write_text(json.dumps(payload, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")


def sha256sum_lines(paths: Iterable[Path], root: Path) -> list[str]:
    return [f"{file_sha256(path)}  {path.relative_to(root).as_posix()}" for path in sorted(paths)]
