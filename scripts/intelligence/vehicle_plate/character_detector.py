#!/usr/bin/env python3
"""Dependency-light contract for Moroccan plate character detection.

The GPU trainer imports this module, while CI exercises the alphabet, source
admission, geometric reconstruction and metrics without importing PyTorch.
The detector is deliberately applied to a bounded plate crop, never to a full
vehicle image.
"""

from __future__ import annotations

import json
import math
import statistics
from dataclasses import dataclass
from pathlib import Path
from typing import Any, Iterable, Mapping, Sequence

from scripts.intelligence.vehicle_plate.generate_synthetic_dataset import (
    OFFICIAL_SERIES_MAPPING,
)
from scripts.intelligence.vehicle_plate.protocol import (
    ProtocolError,
    normalize_ocr_text,
    parse_plate_text,
)


CHARACTER_PROTOCOL_VERSION = "1.0.0"
DIGITS = tuple("0123456789")
ARABIC_SERIES = tuple(OFFICIAL_SERIES_MAPPING)
LATIN_SERIES = tuple(dict.fromkeys(OFFICIAL_SERIES_MAPPING.values()))
CHARACTER_ALPHABET = DIGITS + ARABIC_SERIES + LATIN_SERIES
CLASS_TO_ID = {character: index for index, character in enumerate(CHARACTER_ALPHABET, 1)}
ID_TO_CLASS = {class_id: character for character, class_id in CLASS_TO_ID.items()}
BACKGROUND_CLASS_ID = 0
MODEL_NUM_CLASSES = len(CHARACTER_ALPHABET) + 1
DEFAULT_SCORE_THRESHOLD = 0.45
DEFAULT_IOU_THRESHOLD = 0.50


if len(CLASS_TO_ID) != 40:
    raise AssertionError("L'alphabet ANPR marocain doit contenir exactement 40 classes.")


@dataclass(frozen=True)
class CharacterDetection:
    """One character prediction in plate-crop pixel coordinates."""

    label: str
    box: tuple[float, float, float, float]
    score: float

    @property
    def center_x(self) -> float:
        return (self.box[0] + self.box[2]) / 2.0

    @property
    def center_y(self) -> float:
        return (self.box[1] + self.box[3]) / 2.0

    @property
    def width(self) -> float:
        return self.box[2] - self.box[0]

    @property
    def height(self) -> float:
        return self.box[3] - self.box[1]


@dataclass(frozen=True)
class CharacterReading:
    accepted: bool
    canonical: str | None
    recognition_text: str | None
    format_profile: str | None
    confidence: float
    selected: tuple[CharacterDetection, ...]
    reasons: tuple[str, ...]

    def as_dict(self) -> dict[str, Any]:
        return {
            "accepted": self.accepted,
            "canonical": self.canonical,
            "recognition_text": self.recognition_text,
            "format_profile": self.format_profile,
            "confidence": self.confidence,
            "selected": [
                {"label": item.label, "box": list(item.box), "score": item.score}
                for item in self.selected
            ],
            "reasons": list(self.reasons),
        }


@dataclass(frozen=True)
class DetectionCounts:
    true_positives: int
    false_positives: int
    false_negatives: int

    @property
    def precision(self) -> float:
        denominator = self.true_positives + self.false_positives
        return self.true_positives / denominator if denominator else 0.0

    @property
    def recall(self) -> float:
        denominator = self.true_positives + self.false_negatives
        return self.true_positives / denominator if denominator else 0.0


def _validate_detection(item: CharacterDetection, image_width: float, image_height: float) -> None:
    label = normalize_ocr_text(item.label).strip()
    if label not in CLASS_TO_ID:
        raise ProtocolError(f"Classe de caractère ANPR inconnue: {item.label!r}.")
    if not math.isfinite(float(item.score)) or not 0.0 <= float(item.score) <= 1.0:
        raise ProtocolError("Le score de caractère doit être fini et compris entre 0 et 1.")
    x1, y1, x2, y2 = (float(value) for value in item.box)
    if not all(math.isfinite(value) for value in (x1, y1, x2, y2)):
        raise ProtocolError("Une boîte de caractère contient une coordonnée non finie.")
    if not (0.0 <= x1 < x2 <= image_width and 0.0 <= y1 < y2 <= image_height):
        raise ProtocolError(f"Boîte de caractère hors du crop plaque: {item.box!r}.")


def _digit_clusters(
    digits: Sequence[CharacterDetection], *, image_width: float
) -> tuple[tuple[CharacterDetection, ...], ...]:
    ordered = sorted(digits, key=lambda item: item.center_x)
    if not ordered:
        return ()
    median_width = statistics.median(max(item.width, 1.0) for item in ordered)
    # A serial/region separator is much wider than normal kerning. The absolute
    # term keeps the rule stable on high-resolution crops and narrow glyphs.
    split_gap = max(1.85 * median_width, 0.075 * image_width)
    clusters: list[list[CharacterDetection]] = [[ordered[0]]]
    for item in ordered[1:]:
        previous = clusters[-1][-1]
        if item.center_x - previous.center_x > split_gap:
            clusters.append([item])
        else:
            clusters[-1].append(item)
    return tuple(tuple(cluster) for cluster in clusters)


def _reject(reason: str, selected: Iterable[CharacterDetection] = ()) -> CharacterReading:
    chosen = tuple(selected)
    confidence = min((item.score for item in chosen), default=0.0)
    return CharacterReading(False, None, None, None, confidence, chosen, (reason,))


def reconstruct_moroccan_plate(
    detections: Sequence[CharacterDetection],
    *,
    image_width: float,
    image_height: float,
    score_threshold: float = DEFAULT_SCORE_THRESHOLD,
    require_ma_marker_for_unified: bool = True,
) -> CharacterReading:
    """Reconstruct a registration using only geometry and the Moroccan grammar.

    The rightmost digit cluster is the territorial code. A separate digit
    cluster to its left is the serial. The series cell lies between them; on a
    unified plate its Arabic and Latin symbols must match the official mapping.
    This function never invents or autocorrects a missing character.
    """

    if not math.isfinite(float(image_width)) or not math.isfinite(float(image_height)):
        raise ProtocolError("Dimensions du crop plaque non finies.")
    if image_width <= 0 or image_height <= 0:
        raise ProtocolError("Dimensions du crop plaque invalides.")
    if not 0.0 <= float(score_threshold) <= 1.0:
        raise ProtocolError("score_threshold doit être compris entre 0 et 1.")

    eligible: list[CharacterDetection] = []
    for item in detections:
        normalized = CharacterDetection(
            normalize_ocr_text(item.label).strip(),
            tuple(float(value) for value in item.box),
            float(item.score),
        )
        _validate_detection(normalized, float(image_width), float(image_height))
        if normalized.score >= score_threshold:
            eligible.append(normalized)

    digits = [item for item in eligible if item.label in DIGITS]
    clusters = _digit_clusters(digits, image_width=float(image_width))
    if len(clusters) != 2:
        return _reject("expected_exactly_two_digit_clusters", digits)
    serial_cluster, region_cluster = clusters
    if serial_cluster[-1].center_x >= region_cluster[0].center_x:
        return _reject("invalid_digit_cluster_order", digits)
    if not 1 <= len(serial_cluster) <= 5 or not 1 <= len(region_cluster) <= 2:
        return _reject("invalid_serial_or_region_length", digits)

    serial = "".join(item.label for item in serial_cluster)
    region = "".join(item.label for item in region_cluster)
    if (len(serial) > 1 and serial.startswith("0")) or (
        len(region) > 1 and region.startswith("0")
    ):
        return _reject("leading_zero_forbidden", digits)

    cell_left = serial_cluster[-1].box[2]
    cell_right = region_cluster[0].box[0]
    if cell_right <= cell_left:
        return _reject("series_cell_missing", digits)
    in_series_cell = [
        item
        for item in eligible
        if cell_left < item.center_x < cell_right and item.label not in DIGITS
    ]
    arabic = [item for item in in_series_cell if item.label in ARABIC_SERIES]
    latin = [item for item in in_series_cell if item.label in LATIN_SERIES]
    if len(arabic) != 1:
        return _reject("expected_one_arabic_series_character", digits + in_series_cell)
    if len(latin) > 1:
        return _reject("ambiguous_latin_series_character", digits + in_series_cell)

    series_arabic = arabic[0]
    selected: list[CharacterDetection] = [*serial_cluster, series_arabic, *region_cluster]
    if latin:
        series_latin = latin[0]
        expected_latin = OFFICIAL_SERIES_MAPPING[series_arabic.label]
        if series_latin.label != expected_latin:
            return _reject("arabic_latin_series_mismatch", digits + in_series_cell)
        marker_candidates = sorted(
            (
                item
                for item in eligible
                if item.center_x < serial_cluster[0].box[0] and item.label in {"M", "A"}
            ),
            key=lambda item: item.center_x,
        )
        if require_ma_marker_for_unified:
            if len(marker_candidates) != 2 or "".join(
                item.label for item in marker_candidates
            ) != "MA":
                return _reject("unified_ma_marker_missing_or_ambiguous", digits + in_series_cell)
            selected = [*marker_candidates, *serial_cluster, series_arabic, series_latin, *region_cluster]
        else:
            selected = [*serial_cluster, series_arabic, series_latin, *region_cluster]
        recognition_text = f"MA{serial}{series_arabic.label}{series_latin.label}{region}"
        format_profile = "unified_2026_arabic_latin"
        parsed = parse_plate_text(
            recognition_text,
            bilingual_mapping=OFFICIAL_SERIES_MAPPING,
            require_verified_bilingual=True,
        )
    else:
        # Latin marker characters outside the series cell are ignored only for
        # the legacy layout. Any other high-confidence non-digit inside the
        # cell was already rejected by the exact Arabic count above.
        recognition_text = f"{serial}{series_arabic.label}{region}"
        format_profile = "legacy_arabic"
        parsed = parse_plate_text(
            recognition_text,
            bilingual_mapping=OFFICIAL_SERIES_MAPPING,
        )
    if not parsed.valid or parsed.canonical is None:
        return _reject("moroccan_plate_grammar_rejected", selected)
    confidence = min(item.score for item in selected)
    return CharacterReading(
        True,
        parsed.canonical,
        recognition_text,
        format_profile,
        confidence,
        tuple(selected),
        (),
    )


def box_iou(
    left: Sequence[float], right: Sequence[float]
) -> float:
    if len(left) != 4 or len(right) != 4:
        raise ProtocolError("Une boîte IoU doit contenir quatre coordonnées.")
    lx1, ly1, lx2, ly2 = (float(value) for value in left)
    rx1, ry1, rx2, ry2 = (float(value) for value in right)
    intersection_width = max(0.0, min(lx2, rx2) - max(lx1, rx1))
    intersection_height = max(0.0, min(ly2, ry2) - max(ly1, ry1))
    intersection = intersection_width * intersection_height
    left_area = max(0.0, lx2 - lx1) * max(0.0, ly2 - ly1)
    right_area = max(0.0, rx2 - rx1) * max(0.0, ry2 - ry1)
    union = left_area + right_area - intersection
    return intersection / union if union > 0 else 0.0


def match_character_detections(
    predictions: Sequence[CharacterDetection],
    targets: Sequence[CharacterDetection],
    *,
    iou_threshold: float = DEFAULT_IOU_THRESHOLD,
) -> DetectionCounts:
    """Greedy score-ordered class-aware matching for development diagnostics."""

    if not 0.0 < float(iou_threshold) <= 1.0:
        raise ProtocolError("iou_threshold doit être compris dans ]0, 1].")
    unmatched = set(range(len(targets)))
    true_positives = 0
    for prediction in sorted(predictions, key=lambda item: item.score, reverse=True):
        candidates = [
            (box_iou(prediction.box, targets[index].box), index)
            for index in unmatched
            if prediction.label == targets[index].label
        ]
        if not candidates:
            continue
        overlap, index = max(candidates)
        if overlap >= iou_threshold:
            unmatched.remove(index)
            true_positives += 1
    return DetectionCounts(
        true_positives=true_positives,
        false_positives=len(predictions) - true_positives,
        false_negatives=len(targets) - true_positives,
    )


def load_source_registry(path: str | Path) -> Mapping[str, Any]:
    document = json.loads(Path(path).read_text(encoding="utf-8"))
    if document.get("schema_version") != "1.0.0":
        raise ProtocolError("Version du registre de sources ANPR non prise en charge.")
    sources = document.get("sources")
    if not isinstance(sources, list) or not sources:
        raise ProtocolError("Le registre ANPR doit contenir une liste de sources.")
    indexed: dict[str, Mapping[str, Any]] = {}
    for source in sources:
        if not isinstance(source, dict) or not isinstance(source.get("source_id"), str):
            raise ProtocolError("Entrée de source ANPR invalide.")
        source_id = source["source_id"]
        if source_id in indexed:
            raise ProtocolError(f"source_id dupliqué dans le registre: {source_id}.")
        indexed[source_id] = source
    return indexed


def require_admitted_source(
    registry: Mapping[str, Mapping[str, Any]], *, source_id: str, task: str
) -> Mapping[str, Any]:
    source = registry.get(source_id)
    if source is None:
        raise ProtocolError(f"Source absente du registre ANPR: {source_id}.")
    if source.get("e22_training_enabled") is not True:
        raise ProtocolError(
            f"Source publique {source_id} non activée pour E2.2: "
            f"statut {source.get('public_status')!r}."
        )
    tasks = source.get("e22_allowed_tasks")
    if not isinstance(tasks, list) or task not in tasks:
        raise ProtocolError(f"Source {source_id} non admise pour la tâche {task}.")
    if source.get("independent_evidence_allowed") is not False:
        raise ProtocolError(
            f"Le registre doit interdire la source de développement {source_id} comme preuve indépendante."
        )
    return source
