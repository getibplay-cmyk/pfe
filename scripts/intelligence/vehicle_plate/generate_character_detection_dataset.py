#!/usr/bin/env python3
"""Generate COCO character boxes for deterministic Moroccan plate crops.

This is a new E2.2 development bundle. It reuses the frozen plate plan and
renderer without changing the E2.1 PaddleOCR images. Character masks are
rotated with the same geometric transform as the rendered plate, producing
boxes for digits, all 15 Arabic series, all 15 Latin equivalents and ``MA``.
"""

from __future__ import annotations

import argparse
import csv
import json
import os
import shutil
import sys
import tempfile
from collections import Counter, defaultdict
from dataclasses import asdict, dataclass
from pathlib import Path
from typing import Any, Callable, Mapping, Sequence


REPOSITORY_ROOT = Path(__file__).resolve().parents[3]
if str(REPOSITORY_ROOT) not in sys.path:
    sys.path.insert(0, str(REPOSITORY_ROOT))

from scripts.intelligence.vehicle_plate.character_detector import (
    CHARACTER_ALPHABET,
    CHARACTER_PROTOCOL_VERSION,
    CLASS_TO_ID,
)
from scripts.intelligence.vehicle_plate.generate_synthetic_dataset import (
    DEFAULT_GROUP_COUNTS,
    DEFAULT_SERIES,
    DEFAULT_UNIFIED_FRACTION,
    DEVELOPMENT_SPLITS,
    FORMAT_EVIDENCE_URL,
    FORMAT_REGULATION_ID,
    GENERATOR_VERSION as RECOGNITION_GENERATOR_VERSION,
    LICENSE_ID,
    OFFICIAL_SERIES_MAPPING,
    REQUIRED_COLUMNS,
    SOURCE_ID,
    SOURCE_URL,
    FontProvenance,
    LatinFontProvenance,
    SyntheticSample,
    build_sample_plan,
    inspect_noto_font,
    inspect_noto_latin_font,
    parse_series,
    render_sample,
)
from scripts.intelligence.vehicle_plate.protocol import (
    PROTOCOL_VERSION,
    ProtocolError,
    file_sha256,
    sha256sum_lines,
    validate_manifest,
    verify_manifest_files,
)


GENERATOR_VERSION = "1.0.0"
EXTRA_MANIFEST_COLUMNS = (
    "recognition_text",
    "format_profile",
    "series_latin",
    "variant_id",
    "character_annotation_path",
    "character_count",
    "character_generator_version",
    "augmentation_json",
)


@dataclass(frozen=True)
class CharacterBox:
    label: str
    box: tuple[int, int, int, int]
    role: str
    reading_index: int


@dataclass(frozen=True)
class CharacterGenerationResult:
    output_dir: Path
    groups: int
    images: int
    characters: int
    manifest_sha256: str

    def as_dict(self) -> dict[str, Any]:
        return {
            "output_dir": os.fspath(self.output_dir),
            "groups": self.groups,
            "images": self.images,
            "characters": self.characters,
            "manifest_sha256": self.manifest_sha256,
            "qualification_claim": False,
            "final_test_opened": False,
        }


Renderer = Callable[[SyntheticSample, Path, Path, Path], None]
Annotator = Callable[[SyntheticSample, Path, Path], Sequence[CharacterBox]]


def _text_character_masks(
    *,
    text: str,
    center: tuple[float, float],
    font: Any,
    role: str,
    start_index: int,
    image_size: tuple[int, int] = (520, 110),
) -> tuple[list[tuple[CharacterBox, Any]], int]:
    from PIL import Image, ImageDraw

    if not text:
        return [], start_index
    measuring = ImageDraw.Draw(Image.new("L", image_size, color=0))
    total_advance = float(measuring.textlength(text, font=font))
    left = float(center[0]) - total_advance / 2.0
    items: list[tuple[CharacterBox, Any]] = []
    for offset, character in enumerate(text):
        prefix = text[:offset]
        prefix_advance = float(measuring.textlength(prefix, font=font))
        character_advance = float(measuring.textlength(character, font=font))
        character_center = left + prefix_advance + character_advance / 2.0
        mask = Image.new("L", image_size, color=0)
        draw = ImageDraw.Draw(mask)
        draw.text(
            (character_center, float(center[1])),
            character,
            font=font,
            fill=255,
            anchor="mm",
        )
        # The final box is populated after applying the shared rotation.
        placeholder = CharacterBox(
            character,
            (0, 0, 1, 1),
            role,
            start_index + offset,
        )
        items.append((placeholder, mask))
    return items, start_index + len(text)


def build_character_boxes(
    sample: SyntheticSample,
    font_path: Path,
    latin_font_path: Path,
) -> tuple[CharacterBox, ...]:
    """Build per-character boxes matching ``render_sample`` geometry."""

    try:
        from PIL import ImageFont
    except ImportError as error:
        raise ProtocolError("Pillow est requis pour les annotations de caractères.") from error

    from scripts.intelligence.vehicle_plate.protocol import parse_plate_text

    arabic_font = ImageFont.truetype(os.fspath(font_path), size=64)
    compact_arabic_font = ImageFont.truetype(os.fspath(font_path), size=34)
    compact_latin_font = ImageFont.truetype(os.fspath(latin_font_path), size=34)
    country_font = ImageFont.truetype(os.fspath(latin_font_path), size=28)
    parsed = parse_plate_text(
        sample.recognition_text,
        bilingual_mapping=OFFICIAL_SERIES_MAPPING,
        require_verified_bilingual=(sample.format_profile == "unified_2026_arabic_latin"),
    )
    if not parsed.valid or not parsed.serial or not parsed.series_arabic or not parsed.region:
        raise ProtocolError(f"Cible synthétique non annotable: {sample.target}.")

    masked: list[tuple[CharacterBox, Any]] = []
    reading_index = 0
    if sample.format_profile == "unified_2026_arabic_latin":
        if not sample.series_latin:
            raise ProtocolError("Correspondance latine absente du profil unifié.")
        additions, reading_index = _text_character_masks(
            text="MA",
            center=(43, 56),
            font=country_font,
            role="country_marker",
            start_index=reading_index,
        )
        masked.extend(additions)
        additions, reading_index = _text_character_masks(
            text=parsed.serial,
            center=(214, 56),
            font=arabic_font,
            role="serial",
            start_index=reading_index,
        )
        masked.extend(additions)
        additions, reading_index = _text_character_masks(
            text=parsed.series_arabic,
            center=(386, 34),
            font=compact_arabic_font,
            role="series_arabic",
            start_index=reading_index,
        )
        masked.extend(additions)
        additions, reading_index = _text_character_masks(
            text=sample.series_latin,
            center=(386, 77),
            font=compact_latin_font,
            role="series_latin",
            start_index=reading_index,
        )
        masked.extend(additions)
        additions, reading_index = _text_character_masks(
            text=parsed.region,
            center=(472, 56),
            font=arabic_font,
            role="region",
            start_index=reading_index,
        )
        masked.extend(additions)
    elif sample.format_profile == "legacy_arabic":
        additions, reading_index = _text_character_masks(
            text=parsed.serial,
            center=(182, 56),
            font=arabic_font,
            role="serial",
            start_index=reading_index,
        )
        masked.extend(additions)
        additions, reading_index = _text_character_masks(
            text=parsed.series_arabic,
            center=(394, 55),
            font=arabic_font,
            role="series_arabic",
            start_index=reading_index,
        )
        masked.extend(additions)
        additions, reading_index = _text_character_masks(
            text=parsed.region,
            center=(472, 56),
            font=arabic_font,
            role="region",
            start_index=reading_index,
        )
        masked.extend(additions)
    else:
        raise ProtocolError(f"Profil de plaque inconnu: {sample.format_profile!r}.")

    result: list[CharacterBox] = []
    for placeholder, mask in masked:
        if sample.augmentation.rotation_degrees:
            from PIL import Image

            mask = mask.rotate(
                sample.augmentation.rotation_degrees,
                resample=Image.Resampling.BICUBIC,
                expand=False,
                fillcolor=0,
            )
        raw_box = mask.getbbox()
        if raw_box is None:
            raise ProtocolError(f"Masque vide pour le caractère {placeholder.label!r}.")
        x1, y1, x2, y2 = (int(value) for value in raw_box)
        # Two pixels absorb antialiasing differences while remaining well clear
        # of neighbouring glyphs in the 520x110 frozen layouts.
        padded = (
            max(0, x1 - 2),
            max(0, y1 - 2),
            min(520, x2 + 2),
            min(110, y2 + 2),
        )
        result.append(
            CharacterBox(
                placeholder.label,
                padded,
                placeholder.role,
                placeholder.reading_index,
            )
        )
    return tuple(result)


def _validate_character_boxes(boxes: Sequence[CharacterBox]) -> None:
    if not boxes:
        raise ProtocolError("Une plaque synthétique doit contenir des caractères annotés.")
    reading_indices = [box.reading_index for box in boxes]
    if reading_indices != list(range(len(boxes))):
        raise ProtocolError("Les indices de lecture des caractères ne sont pas contigus.")
    for item in boxes:
        if item.label not in CLASS_TO_ID:
            raise ProtocolError(f"Classe synthétique hors alphabet: {item.label!r}.")
        x1, y1, x2, y2 = item.box
        if not (0 <= x1 < x2 <= 520 and 0 <= y1 < y2 <= 110):
            raise ProtocolError(f"Boîte synthétique invalide: {item.box!r}.")
        if item.role not in {
            "country_marker",
            "serial",
            "series_arabic",
            "series_latin",
            "region",
        }:
            raise ProtocolError(f"Rôle de caractère inconnu: {item.role!r}.")


def _write_coco_documents(
    root: Path,
    samples: Sequence[SyntheticSample],
    annotations_by_sample: Mapping[str, Sequence[CharacterBox]],
) -> tuple[dict[str, str], int, Counter[str]]:
    annotation_dir = root / "annotations"
    annotation_dir.mkdir(parents=True, exist_ok=True)
    category_documents = [
        {"id": CLASS_TO_ID[character], "name": character, "supercategory": "character"}
        for character in CHARACTER_ALPHABET
    ]
    output_paths: dict[str, str] = {}
    total_annotations = 0
    class_counts: Counter[str] = Counter()
    next_annotation_id = 1
    for split in DEVELOPMENT_SPLITS:
        split_samples = sorted(
            (sample for sample in samples if sample.split == split),
            key=lambda item: item.sample_id,
        )
        images: list[dict[str, Any]] = []
        annotations: list[dict[str, Any]] = []
        for image_id, sample in enumerate(split_samples, 1):
            images.append(
                {
                    "id": image_id,
                    "file_name": sample.image_path,
                    "width": 520,
                    "height": 110,
                    "sample_id": sample.sample_id,
                    "group_id": sample.group_id,
                    "target": sample.target,
                    "recognition_text": sample.recognition_text,
                    "format_profile": sample.format_profile,
                    "variant_id": sample.variant_id,
                    "source_id": SOURCE_ID,
                    "holdout_role": "development",
                }
            )
            for item in annotations_by_sample[sample.sample_id]:
                x1, y1, x2, y2 = item.box
                width = x2 - x1
                height = y2 - y1
                annotations.append(
                    {
                        "id": next_annotation_id,
                        "image_id": image_id,
                        "category_id": CLASS_TO_ID[item.label],
                        "bbox": [x1, y1, width, height],
                        "area": width * height,
                        "iscrowd": 0,
                        "role": item.role,
                        "reading_index": item.reading_index,
                    }
                )
                next_annotation_id += 1
                class_counts[item.label] += 1
        document = {
            "info": {
                "description": "Synthetic Moroccan plate-crop character detection development data",
                "version": GENERATOR_VERSION,
                "character_protocol_version": CHARACTER_PROTOCOL_VERSION,
                "qualification_claim": False,
                "final_test_opened": False,
            },
            "licenses": [{"id": 1, "name": LICENSE_ID, "url": SOURCE_URL}],
            "images": images,
            "annotations": annotations,
            "categories": category_documents,
        }
        relative_path = f"annotations/instances_{split}.json"
        (root / relative_path).write_text(
            json.dumps(document, ensure_ascii=False, sort_keys=True, indent=2) + "\n",
            encoding="utf-8",
        )
        output_paths[split] = relative_path
        total_annotations += len(annotations)
    return output_paths, total_annotations, class_counts


def _materialize_character_dataset(
    *,
    output_dir: Path,
    samples: Sequence[SyntheticSample],
    series: Sequence[str],
    seed: int,
    group_counts: Mapping[str, int],
    variants_per_group: int,
    font_path: Path,
    license_path: Path,
    provenance: FontProvenance,
    latin_font_path: Path,
    latin_license_path: Path,
    latin_provenance: LatinFontProvenance,
    renderer: Renderer = render_sample,
    annotator: Annotator = build_character_boxes,
) -> CharacterGenerationResult:
    if output_dir.exists():
        raise FileExistsError(
            f"Le dossier de sortie existe déjà; aucun écrasement autorisé: {output_dir}"
        )
    output_dir.parent.mkdir(parents=True, exist_ok=True)
    with tempfile.TemporaryDirectory(
        prefix=".anpr-character-synthetic-", dir=output_dir.parent
    ) as temporary_directory:
        root = Path(temporary_directory)
        copied_font = root / "fonts" / font_path.name
        copied_latin_font = root / "fonts" / latin_font_path.name
        copied_license = root / "licenses" / "OFL.txt"
        copied_latin_license = root / "licenses" / "OFL-NotoSans.txt"
        copied_font.parent.mkdir(parents=True, exist_ok=True)
        copied_license.parent.mkdir(parents=True, exist_ok=True)
        shutil.copyfile(font_path, copied_font)
        shutil.copyfile(latin_font_path, copied_latin_font)
        shutil.copyfile(license_path, copied_license)
        shutil.copyfile(latin_license_path, copied_latin_license)

        rows: list[dict[str, str]] = []
        annotations_by_sample: dict[str, Sequence[CharacterBox]] = {}
        for sample in samples:
            destination = root / sample.image_path
            renderer(sample, destination, copied_font, copied_latin_font)
            if not destination.is_file():
                raise ProtocolError(f"Le renderer n'a pas créé {sample.image_path}.")
            boxes = tuple(annotator(sample, copied_font, copied_latin_font))
            _validate_character_boxes(boxes)
            annotations_by_sample[sample.sample_id] = boxes
            rows.append(
                {
                    "sample_id": sample.sample_id,
                    "image_path": sample.image_path,
                    "group_id": sample.group_id,
                    "task": "recognition",
                    "target": sample.target,
                    "source_id": SOURCE_ID,
                    "source_url": provenance.source_url,
                    "license_id": LICENSE_ID,
                    "license_status": "approved",
                    "license_proof": "licenses/OFL.txt",
                    "sha256": file_sha256(destination),
                    "split": sample.split,
                    "holdout_role": "development",
                    "recognition_text": sample.recognition_text,
                    "format_profile": sample.format_profile,
                    "series_latin": sample.series_latin,
                    "variant_id": sample.variant_id,
                    "character_annotation_path": f"annotations/instances_{sample.split}.json",
                    "character_count": str(len(boxes)),
                    "character_generator_version": GENERATOR_VERSION,
                    "augmentation_json": json.dumps(
                        asdict(sample.augmentation),
                        ensure_ascii=False,
                        sort_keys=True,
                        separators=(",", ":"),
                    ),
                }
            )

        validate_manifest(rows)
        verify_manifest_files(rows, root, root)
        manifest_path = root / "manifest.csv"
        with manifest_path.open("w", encoding="utf-8", newline="") as handle:
            writer = csv.DictWriter(
                handle, fieldnames=REQUIRED_COLUMNS + EXTRA_MANIFEST_COLUMNS
            )
            writer.writeheader()
            writer.writerows(rows)
        coco_paths, character_count, class_counts = _write_coco_documents(
            root, samples, annotations_by_sample
        )
        format_groups: dict[str, set[str]] = defaultdict(set)
        for sample in samples:
            format_groups[sample.format_profile].add(sample.group_id)
        report = {
            "schema_version": "1.0.0",
            "generator_version": GENERATOR_VERSION,
            "recognition_generator_version": RECOGNITION_GENERATOR_VERSION,
            "character_protocol_version": CHARACTER_PROTOCOL_VERSION,
            "protocol_version": PROTOCOL_VERSION,
            "purpose": "character_detection_development_only_not_independent_evidence",
            "qualification_claim": False,
            "final_test_opened": False,
            "contains_real_vehicle_data": False,
            "configuration": {
                "seed": int(seed),
                "group_counts": {
                    split: int(group_counts[split]) for split in DEVELOPMENT_SPLITS
                },
                "variants_per_group": int(variants_per_group),
                "series_arabic": list(series),
                "official_series_mapping": {
                    character: OFFICIAL_SERIES_MAPPING[character] for character in series
                },
                "alphabet": list(CHARACTER_ALPHABET),
                "class_to_id": CLASS_TO_ID,
                "format_group_counts": {
                    name: len(groups) for name, groups in sorted(format_groups.items())
                },
            },
            "source": {
                "source_id": SOURCE_ID,
                "license_id": LICENSE_ID,
                "font": asdict(provenance),
                "font_bundle_path": f"fonts/{font_path.name}",
                "license_proof": "licenses/OFL.txt",
                "latin_font": asdict(latin_provenance),
                "latin_font_path": f"fonts/{latin_font_path.name}",
                "latin_license_proof": "licenses/OFL-NotoSans.txt",
                "format_regulation_id": FORMAT_REGULATION_ID,
                "format_evidence_url": FORMAT_EVIDENCE_URL,
            },
            "artifacts": {
                "groups": sum(int(value) for value in group_counts.values()),
                "images": len(samples),
                "characters": character_count,
                "class_counts": dict(sorted(class_counts.items())),
                "manifest": "manifest.csv",
                "manifest_sha256": file_sha256(manifest_path),
                "coco_annotations": coco_paths,
            },
            "limits": [
                "Synthetic character boxes do not establish real-photo accuracy.",
                "No independent test image or label is present.",
                "A plate detector must produce the bounded crop before this model is called.",
                "Real Moroccan validation is required before this challenger can replace PaddleOCR or enter SaaS.",
            ],
        }
        report_path = root / "generation_report.json"
        report_path.write_text(
            json.dumps(report, ensure_ascii=False, sort_keys=True, indent=2) + "\n",
            encoding="utf-8",
        )
        checksum_candidates = [
            path for path in root.rglob("*") if path.is_file() and path.name != "SHA256SUMS"
        ]
        (root / "SHA256SUMS").write_text(
            "\n".join(sha256sum_lines(checksum_candidates, root)) + "\n",
            encoding="utf-8",
        )
        manifest_sha256 = file_sha256(manifest_path)
        root.replace(output_dir)
    return CharacterGenerationResult(
        output_dir=output_dir,
        groups=sum(int(value) for value in group_counts.values()),
        images=len(samples),
        characters=character_count,
        manifest_sha256=manifest_sha256,
    )


def generate_character_dataset(
    *,
    output_dir: str | Path,
    font_path: str | Path,
    license_path: str | Path,
    latin_font_path: str | Path,
    latin_license_path: str | Path,
    seed: int,
    group_counts: Mapping[str, int],
    variants_per_group: int,
    series: str | Sequence[str] = DEFAULT_SERIES,
    unified_fraction: float = DEFAULT_UNIFIED_FRACTION,
    source_url: str = SOURCE_URL,
) -> CharacterGenerationResult:
    normalized_series = parse_series(series)
    samples = build_sample_plan(
        seed=int(seed),
        group_counts=group_counts,
        variants_per_group=int(variants_per_group),
        series=normalized_series,
        unified_fraction=float(unified_fraction),
    )
    font_file = Path(font_path).resolve()
    license_file = Path(license_path).resolve()
    latin_font_file = Path(latin_font_path).resolve()
    latin_license_file = Path(latin_license_path).resolve()
    provenance = inspect_noto_font(
        font_file,
        license_file,
        series=normalized_series,
        source_url=source_url,
    )
    latin_provenance = inspect_noto_latin_font(latin_font_file, latin_license_file)
    return _materialize_character_dataset(
        output_dir=Path(output_dir).resolve(),
        samples=samples,
        series=normalized_series,
        seed=int(seed),
        group_counts=group_counts,
        variants_per_group=int(variants_per_group),
        font_path=font_file,
        license_path=license_file,
        provenance=provenance,
        latin_font_path=latin_font_file,
        latin_license_path=latin_license_file,
        latin_provenance=latin_provenance,
    )


def build_parser() -> argparse.ArgumentParser:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--output-dir", required=True, type=Path)
    parser.add_argument("--font", required=True, type=Path)
    parser.add_argument("--font-license", required=True, type=Path)
    parser.add_argument("--latin-font", required=True, type=Path)
    parser.add_argument("--latin-font-license", required=True, type=Path)
    parser.add_argument("--font-source-url", default=SOURCE_URL)
    parser.add_argument("--seed", type=int, default=20260825)
    parser.add_argument("--train-groups", type=int, default=DEFAULT_GROUP_COUNTS["train"])
    parser.add_argument(
        "--validation-groups", type=int, default=DEFAULT_GROUP_COUNTS["validation"]
    )
    parser.add_argument(
        "--calibration-groups", type=int, default=DEFAULT_GROUP_COUNTS["calibration"]
    )
    parser.add_argument("--variants-per-group", type=int, default=3)
    parser.add_argument("--series", default=",".join(DEFAULT_SERIES))
    parser.add_argument("--unified-fraction", type=float, default=DEFAULT_UNIFIED_FRACTION)
    return parser


def main(argv: Sequence[str] | None = None) -> int:
    parser = build_parser()
    arguments = parser.parse_args(argv)
    try:
        result = generate_character_dataset(
            output_dir=arguments.output_dir,
            font_path=arguments.font,
            license_path=arguments.font_license,
            latin_font_path=arguments.latin_font,
            latin_license_path=arguments.latin_font_license,
            seed=arguments.seed,
            group_counts={
                "train": arguments.train_groups,
                "validation": arguments.validation_groups,
                "calibration": arguments.calibration_groups,
            },
            variants_per_group=arguments.variants_per_group,
            series=arguments.series,
            unified_fraction=arguments.unified_fraction,
            source_url=arguments.font_source_url,
        )
    except (FileExistsError, FileNotFoundError, ProtocolError) as error:
        parser.exit(2, f"Erreur: {error}\n")
    print(json.dumps(result.as_dict(), ensure_ascii=False, sort_keys=True, indent=2))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
