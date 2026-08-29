#!/usr/bin/env python3
"""Fuse segmented PP-OCRv5 readings into a consultative plate suggestion.

The existing full-crop recognizer remains the incumbent.  This module is used
only when that reading is empty or rejected by the Moroccan grammar.  It does
not load another model: the same official Arabic PP-OCRv5 recognizer is called
on bounded serial, series and territorial-code zones, then deterministic code
combines the observations.

No result from this module is an operational decision.  A human must confirm
or correct every suggestion before it can become training feedback.
"""

from __future__ import annotations

import math
import re
from collections import defaultdict
from dataclasses import dataclass
from typing import Any, Iterable, Mapping, Sequence

from scripts.intelligence.vehicle_plate.generate_synthetic_dataset import (
    OFFICIAL_SERIES_MAPPING,
)
from scripts.intelligence.vehicle_plate.protocol import (
    ProtocolError,
    normalize_ocr_text,
    parse_plate_text,
)


HYBRID_FALLBACK_VERSION = "1.0.0"
OCR_MODEL_NAME = "arabic_PP-OCRv5_mobile_rec"
OBSERVATION_ROLES = frozenset({"full", "serial", "series", "region"})
COMPONENT_ROLES = ("serial", "series", "region")
DIGIT_GROUP_RE = re.compile(r"[0-9]+")
LATIN_GROUP_RE = re.compile(r"[A-Z]+")
AMBIGUITY_MARGIN = 0.05


@dataclass(frozen=True)
class OcrObservation:
    """One PP-OCRv5 result for a bounded plate or component crop."""

    layout_id: str
    role: str
    variant_id: str
    raw_text: str
    score: float


@dataclass(frozen=True)
class ComponentCandidate:
    role: str
    value: str
    confidence: float
    support: int
    evidence: tuple[str, ...]
    inferred_from_latin: bool = False

    def as_dict(self) -> dict[str, Any]:
        return {
            "role": self.role,
            "value": self.value,
            "confidence": self.confidence,
            "support": self.support,
            "evidence": list(self.evidence),
            "inferred_from_latin": self.inferred_from_latin,
        }


@dataclass(frozen=True)
class HybridSuggestion:
    """A review suggestion; never a value accepted by the application."""

    status: str
    canonical: str | None
    display_text: str
    confidence: float
    source: str
    components: tuple[ComponentCandidate, ...]
    reasons: tuple[str, ...]
    human_review_required: bool = True
    operational_effect: str = "NO_OPERATIONAL_ACTION"

    @property
    def complete(self) -> bool:
        return self.canonical is not None

    def as_dict(self) -> dict[str, Any]:
        return {
            "schema_version": HYBRID_FALLBACK_VERSION,
            "status": self.status,
            "canonical": self.canonical,
            "display_text": self.display_text,
            "confidence": self.confidence,
            "confidence_semantics": "uncalibrated_evidence_score",
            "source": self.source,
            "model_name": OCR_MODEL_NAME,
            "components": [component.as_dict() for component in self.components],
            "reasons": list(self.reasons),
            "human_review_required": self.human_review_required,
            "operational_effect": self.operational_effect,
        }


def _validated_observations(
    observations: Sequence[OcrObservation],
) -> tuple[OcrObservation, ...]:
    validated: list[OcrObservation] = []
    for index, item in enumerate(observations):
        if item.role not in OBSERVATION_ROLES:
            raise ProtocolError(
                f"Observation hybride {index}: rôle inconnu {item.role!r}."
            )
        if not item.layout_id or not item.variant_id:
            raise ProtocolError(
                f"Observation hybride {index}: layout_id et variant_id sont requis."
            )
        score = float(item.score)
        if not math.isfinite(score) or not 0.0 <= score <= 1.0:
            raise ProtocolError(
                f"Observation hybride {index}: score hors limites [0,1]."
            )
        validated.append(
            OcrObservation(
                layout_id=str(item.layout_id),
                role=str(item.role),
                variant_id=str(item.variant_id),
                raw_text=str(item.raw_text),
                score=score,
            )
        )
    return tuple(validated)


def _primary_suggestion(
    observations: Sequence[OcrObservation],
    *,
    bilingual_mapping: Mapping[str, str],
) -> HybridSuggestion | None:
    ranked: list[tuple[float, str, str]] = []
    for item in observations:
        if item.role != "full" or not item.raw_text.strip():
            continue
        parsed = parse_plate_text(
            item.raw_text,
            bilingual_mapping=bilingual_mapping,
            require_verified_bilingual=True,
            paddle_arabic_output=True,
        )
        if parsed.valid and parsed.canonical is not None:
            ranked.append((item.score, parsed.canonical, item.variant_id))
    if not ranked:
        return None
    score, canonical, variant_id = max(ranked, key=lambda row: (row[0], row[1]))
    serial, series, region = canonical.split("|")
    components = tuple(
        ComponentCandidate(role, value, score, 1, (f"full:{variant_id}",))
        for role, value in zip(COMPONENT_ROLES, (serial, series, region), strict=True)
    )
    return HybridSuggestion(
        status="complete_primary_suggestion",
        canonical=canonical,
        display_text=f"{serial} | {series} | {region}",
        confidence=score,
        source="full_crop_ppocrv5",
        components=components,
        reasons=("primary_reading_passed_moroccan_grammar",),
    )


def _valid_digit_value(role: str, value: str) -> bool:
    if not value.isdigit() or (len(value) > 1 and value.startswith("0")):
        return False
    number = int(value)
    if role == "serial":
        return 1 <= len(value) <= 5 and 1 <= number <= 99999
    if role == "region":
        return 1 <= len(value) <= 2 and 1 <= number <= 99
    return False


def _observation_component_values(
    observation: OcrObservation,
    *,
    bilingual_mapping: Mapping[str, str],
) -> tuple[tuple[str, bool, float], ...]:
    normalized = normalize_ocr_text(observation.raw_text)
    if observation.role in {"serial", "region"}:
        return tuple(
            (value, False, observation.score)
            for value in DIGIT_GROUP_RE.findall(normalized)
            if _valid_digit_value(observation.role, value)
        )
    if observation.role != "series":
        return ()

    arabic = tuple(
        dict.fromkeys(character for character in normalized if character in bilingual_mapping)
    )
    allowed_latin = frozenset(str(value).upper() for value in bilingual_mapping.values())
    latin = tuple(
        dict.fromkeys(
            character
            for group in LATIN_GROUP_RE.findall(normalized)
            if group != "MA"
            for character in group
            if character in allowed_latin
        )
    )
    reverse_mapping = {
        str(latin_value).upper(): arabic_value
        for arabic_value, latin_value in bilingual_mapping.items()
    }

    if len(arabic) == 1:
        expected_latin = str(bilingual_mapping[arabic[0]]).upper()
        if latin and expected_latin not in latin:
            return ()
        bonus = 0.03 if expected_latin in latin else 0.0
        return ((arabic[0], False, min(1.0, observation.score + bonus)),)
    if not arabic and len(latin) == 1:
        # The unified 2026 plate intentionally repeats the series in Arabic
        # and Latin.  Mapping the recognized Latin symbol back to its official
        # Arabic series is deterministic, but remains visibly marked as an
        # inference and receives a conservative score penalty.
        return ((reverse_mapping[latin[0]], True, max(0.0, observation.score - 0.15)),)
    return ()


def _aggregate_components(
    observations: Iterable[OcrObservation],
    *,
    role: str,
    bilingual_mapping: Mapping[str, str],
) -> tuple[ComponentCandidate, ...]:
    grouped: dict[str, list[tuple[float, str, bool]]] = defaultdict(list)
    for item in observations:
        if item.role != role:
            continue
        for value, inferred, score in _observation_component_values(
            item, bilingual_mapping=bilingual_mapping
        ):
            grouped[value].append(
                (score, f"{item.layout_id}:{item.variant_id}", inferred)
            )

    candidates: list[ComponentCandidate] = []
    for value, evidence_rows in grouped.items():
        unique_evidence: dict[str, tuple[float, str, bool]] = {}
        for row in evidence_rows:
            current = unique_evidence.get(row[1])
            if current is None or row[0] > current[0]:
                unique_evidence[row[1]] = row
        evidence_rows = sorted(
            unique_evidence.values(), key=lambda row: (-row[0], row[1])
        )
        best = evidence_rows[0][0]
        support = len(evidence_rows)
        confidence = min(1.0, best + min(0.03 * (support - 1), 0.09))
        candidates.append(
            ComponentCandidate(
                role=role,
                value=value,
                confidence=confidence,
                support=support,
                evidence=tuple(row[1] for row in evidence_rows),
                inferred_from_latin=all(row[2] for row in evidence_rows),
            )
        )
    return tuple(
        sorted(
            candidates,
            key=lambda item: (
                item.confidence,
                item.support,
                not item.inferred_from_latin,
                item.value,
            ),
            reverse=True,
        )
    )


def _layout_suggestions(
    observations: Sequence[OcrObservation],
    *,
    bilingual_mapping: Mapping[str, str],
) -> tuple[HybridSuggestion, ...]:
    by_layout: dict[str, list[OcrObservation]] = defaultdict(list)
    for item in observations:
        if item.role in COMPONENT_ROLES:
            by_layout[item.layout_id].append(item)

    suggestions: list[HybridSuggestion] = []
    for layout_id, layout_observations in by_layout.items():
        ranked = {
            role: _aggregate_components(
                layout_observations,
                role=role,
                bilingual_mapping=bilingual_mapping,
            )
            for role in COMPONENT_ROLES
        }
        if any(not ranked[role] for role in COMPONENT_ROLES):
            continue
        for serial in ranked["serial"][:3]:
            for series in ranked["series"][:3]:
                for region in ranked["region"][:3]:
                    parsed = parse_plate_text(
                        f"{serial.value} {series.value} {region.value}",
                        bilingual_mapping=bilingual_mapping,
                    )
                    if not parsed.valid or parsed.canonical is None:
                        continue
                    components = (serial, series, region)
                    confidence = min(item.confidence for item in components)
                    reasons = ["primary_empty_or_grammar_rejected", f"layout:{layout_id}"]
                    if series.inferred_from_latin:
                        reasons.append("series_inferred_from_verified_latin_mapping")
                    suggestions.append(
                        HybridSuggestion(
                            status="complete_segmented_suggestion",
                            canonical=parsed.canonical,
                            display_text=(
                                f"{serial.value} | {series.value} | {region.value}"
                            ),
                            confidence=confidence,
                            source="segmented_ppocrv5_fusion",
                            components=components,
                            reasons=tuple(reasons),
                        )
                    )
    return tuple(suggestions)


def _best_partial(
    observations: Sequence[OcrObservation],
    *,
    bilingual_mapping: Mapping[str, str],
) -> HybridSuggestion:
    by_layout: dict[str, list[OcrObservation]] = defaultdict(list)
    for item in observations:
        if item.role in COMPONENT_ROLES:
            by_layout[item.layout_id].append(item)

    ranked_layouts: list[
        tuple[int, float, str, tuple[ComponentCandidate, ...], tuple[str, ...]]
    ] = []
    for layout_id, layout_observations in by_layout.items():
        components: list[ComponentCandidate] = []
        missing: list[str] = []
        for role in COMPONENT_ROLES:
            values = _aggregate_components(
                layout_observations,
                role=role,
                bilingual_mapping=bilingual_mapping,
            )
            if values:
                components.append(values[0])
            else:
                missing.append(role)
        confidence = min((item.confidence for item in components), default=0.0)
        ranked_layouts.append(
            (len(components), confidence, layout_id, tuple(components), tuple(missing))
        )

    if not ranked_layouts:
        return HybridSuggestion(
            status="empty_suggestion",
            canonical=None,
            display_text="? | ? | ?",
            confidence=0.0,
            source="segmented_ppocrv5_fusion",
            components=(),
            reasons=("no_readable_plate_component",),
        )

    _, confidence, layout_id, components, missing = max(
        ranked_layouts,
        key=lambda row: (row[0], row[1], row[2]),
    )
    values = {item.role: item.value for item in components}
    return HybridSuggestion(
        status="partial_segmented_suggestion",
        canonical=None,
        display_text=" | ".join(values.get(role, "?") for role in COMPONENT_ROLES),
        confidence=confidence,
        source="segmented_ppocrv5_fusion",
        components=components,
        reasons=(
            "primary_empty_or_grammar_rejected",
            f"layout:{layout_id}",
            *(f"missing_{role}" for role in missing),
        ),
    )


def build_hybrid_suggestion(
    observations: Sequence[OcrObservation],
    *,
    bilingual_mapping: Mapping[str, str] = OFFICIAL_SERIES_MAPPING,
) -> HybridSuggestion:
    """Return the best review suggestion without inventing a missing value."""

    validated = _validated_observations(observations)
    if not bilingual_mapping:
        raise ProtocolError("La correspondance bilingue officielle est requise.")

    primary = _primary_suggestion(
        validated, bilingual_mapping=bilingual_mapping
    )
    if primary is not None:
        return primary

    segmented = _layout_suggestions(
        validated, bilingual_mapping=bilingual_mapping
    )
    if not segmented:
        return _best_partial(validated, bilingual_mapping=bilingual_mapping)

    ranked = sorted(
        segmented,
        key=lambda item: (
            item.confidence,
            sum(component.support for component in item.components),
            item.canonical or "",
        ),
        reverse=True,
    )
    selected = ranked[0]
    competing = next(
        (item for item in ranked[1:] if item.canonical != selected.canonical),
        None,
    )
    if (
        competing is not None
        and selected.confidence - competing.confidence < AMBIGUITY_MARGIN
    ):
        return HybridSuggestion(
            status="ambiguous_segmented_suggestion",
            canonical=selected.canonical,
            display_text=selected.display_text,
            confidence=selected.confidence,
            source=selected.source,
            components=selected.components,
            reasons=selected.reasons
            + (f"competing_candidate:{competing.canonical}",),
        )
    return selected
