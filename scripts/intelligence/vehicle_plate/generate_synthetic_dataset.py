#!/usr/bin/env python3
"""Build an auditable synthetic Moroccan plate OCR development dataset.

The generator deliberately excludes real photographs and the independent test
split. Pillow is imported only while rendering, so the protocol and planning
tests remain dependency-free in GitHub CI.
"""

from __future__ import annotations

import argparse
import csv
import hashlib
import json
import os
import random
import shutil
import sys
import tempfile
from collections import defaultdict
from dataclasses import asdict, dataclass
from pathlib import Path
from typing import Any, Callable, Mapping, Sequence


REPOSITORY_ROOT = Path(__file__).resolve().parents[3]
if str(REPOSITORY_ROOT) not in sys.path:
    sys.path.insert(0, str(REPOSITORY_ROOT))

from scripts.intelligence.vehicle_plate.protocol import (
    PROTOCOL_VERSION,
    REQUIRED_COLUMNS,
    ProtocolError,
    file_sha256,
    normalize_ocr_text,
    parse_plate_text,
    sha256sum_lines,
    validate_manifest,
    verify_manifest_files,
)


GENERATOR_VERSION = "1.1.0"
SOURCE_ID = "synthetic_moroccan_plate_ofl_v2"
SOURCE_URL = "https://github.com/notofonts/arabic/releases/tag/NotoSansArabic-v2.013"
SOURCE_ARCHIVE_URL = (
    "https://github.com/notofonts/arabic/releases/download/"
    "NotoSansArabic-v2.013/NotoSansArabic-v2.013.zip"
)
SOURCE_ARCHIVE_SHA256 = "1301aceaea84c501cf2e6dcfb3182e2328c8eae5725817fcb239672bda7154f1"
LATIN_FONT_REPOSITORY_COMMIT = "6a003b5eb672dc8bf5bff5937cf5863f8b175445"
LATIN_FONT_URL = (
    "https://raw.githubusercontent.com/google/fonts/"
    f"{LATIN_FONT_REPOSITORY_COMMIT}/ofl/notosans/"
    "NotoSans%5Bwdth%2Cwght%5D.ttf"
)
LATIN_FONT_SHA256 = "bfb7bb691513f12e734dc346c03a03f784912432d7e3fa8e56efcf906fe86b3d"
LATIN_LICENSE_URL = (
    "https://raw.githubusercontent.com/google/fonts/"
    f"{LATIN_FONT_REPOSITORY_COMMIT}/ofl/notosans/OFL.txt"
)
LATIN_LICENSE_SHA256 = "cee9892f9f0cc8fe882c9e9537ee6a89621d86ee7ceaf70b02e2b2b1c25c061a"
LICENSE_ID = "SYNTHETIC-OFL-1.1"
DEVELOPMENT_SPLITS = ("train", "validation", "calibration")

# Arrêté marocain n° 640.26, publié au Bulletin officiel n° 7531 le
# 3 août 2026. The mapping is redundant visual information on the unified
# plate, so a wrong Latin character must be counted as an OCR error.
OFFICIAL_SERIES_MAPPING = {
    "أ": "A",
    "ب": "B",
    "د": "D",
    "ه": "H",
    "و": "E",
    "ط": "T",
    "ي": "Y",
    "ك": "K",
    "ل": "L",
    "م": "M",
    "ن": "N",
    "ص": "C",
    "ف": "F",
    "ر": "R",
    "س": "S",
}
DEFAULT_SERIES = tuple(OFFICIAL_SERIES_MAPPING)
DEFAULT_UNIFIED_FRACTION = 0.5
FORMAT_REGULATION_ID = "Morocco arrêté 640.26; Bulletin officiel 7531; 2026-08-03"
FORMAT_EVIDENCE_URL = (
    "https://snrtnews.com/fr/article/plaques-dimmatriculation-la-narsa-annonce-"
    "lharmonisation-du-modele-utilise-au-maroc-et-a"
)
DEFAULT_GROUP_COUNTS = {"train": 256, "validation": 64, "calibration": 64}
MAXIMUM_GROUPS = 100_000
MAXIMUM_VARIANTS_PER_GROUP = 20

EXTRA_MANIFEST_COLUMNS = (
    "recognition_text",
    "format_profile",
    "series_latin",
    "variant_id",
    "generator_version",
    "font_sha256",
    "latin_font_sha256",
    "augmentation_json",
)


@dataclass(frozen=True)
class Augmentation:
    rotation_degrees: float
    brightness_factor: float
    blur_radius: float
    noise_sigma: float
    noise_seed: int


@dataclass(frozen=True)
class SyntheticSample:
    sample_id: str
    image_path: str
    group_id: str
    split: str
    target: str
    recognition_text: str
    format_profile: str
    series_latin: str
    variant_id: str
    augmentation: Augmentation


@dataclass(frozen=True)
class FontProvenance:
    family: str
    style: str
    font_sha256: str
    license_sha256: str
    source_url: str
    archive_url: str
    archive_sha256: str
    pillow_version: str
    freetype_version: str | None
    raqm_available: bool


@dataclass(frozen=True)
class LatinFontProvenance:
    family: str
    style: str
    font_sha256: str
    license_sha256: str
    font_url: str
    license_url: str
    repository_commit: str


@dataclass(frozen=True)
class GenerationResult:
    output_dir: Path
    groups: int
    images: int
    split_groups: Mapping[str, int]
    manifest_sha256: str
    dataset_images_sha256: str

    def as_dict(self) -> dict[str, Any]:
        return {
            "output_dir": os.fspath(self.output_dir),
            "groups": self.groups,
            "images": self.images,
            "split_groups": dict(self.split_groups),
            "manifest_sha256": self.manifest_sha256,
            "dataset_images_sha256": self.dataset_images_sha256,
            "qualification_claim": False,
            "final_test_opened": False,
        }


Renderer = Callable[[SyntheticSample, Path, Path, Path], None]


def _stable_digest(*parts: object) -> str:
    payload = "\x1f".join(str(part) for part in parts).encode("utf-8")
    return hashlib.sha256(payload).hexdigest()


def _stable_rng(*parts: object) -> random.Random:
    return random.Random(int(_stable_digest(*parts)[:16], 16))


def parse_series(value: str | Sequence[str]) -> tuple[str, ...]:
    raw_items = value.split(",") if isinstance(value, str) else list(value)
    normalized: list[str] = []
    for raw_item in raw_items:
        item = normalize_ocr_text(str(raw_item).strip())
        if len(item) != 1 or not "\u0600" <= item <= "\u06ff":
            raise ProtocolError(
                "Chaque série synthétique doit être un unique caractère arabe."
            )
        if item not in normalized:
            normalized.append(item)
    if not normalized:
        raise ProtocolError("Au moins une série arabe synthétique est obligatoire.")
    return tuple(normalized)


def _validate_counts(
    group_counts: Mapping[str, int], variants_per_group: int, unified_fraction: float
) -> None:
    if set(group_counts) != set(DEVELOPMENT_SPLITS):
        raise ProtocolError(
            "Les comptes doivent couvrir uniquement train, validation et calibration."
        )
    if any(int(group_counts[split]) < 1 for split in DEVELOPMENT_SPLITS):
        raise ProtocolError("Chaque split de développement doit contenir au moins un groupe.")
    if sum(int(value) for value in group_counts.values()) > MAXIMUM_GROUPS:
        raise ProtocolError(f"Le générateur est limité à {MAXIMUM_GROUPS} groupes par run.")
    if not 1 <= int(variants_per_group) <= MAXIMUM_VARIANTS_PER_GROUP:
        raise ProtocolError(
            "variants_per_group doit être compris entre 1 et "
            f"{MAXIMUM_VARIANTS_PER_GROUP}."
        )
    if not 0.0 <= float(unified_fraction) <= 1.0:
        raise ProtocolError("unified_fraction doit être compris entre 0 et 1.")
    if 0.0 < float(unified_fraction) < 1.0 and any(
        int(group_counts[split]) < 2 for split in DEVELOPMENT_SPLITS
    ):
        raise ProtocolError(
            "Un corpus mixte exige au moins deux groupes dans chaque split."
        )


def _target_for_group(
    *, seed: int, group_index: int, series: Sequence[str], used_targets: set[str]
) -> tuple[str, str, str, str]:
    attempt = 0
    while True:
        digest = _stable_digest("target", seed, group_index, attempt)
        serial = 1 + int(digest[0:8], 16) % 99_999
        series_character = series[int(digest[8:16], 16) % len(series)]
        region = 1 + int(digest[16:24], 16) % 99
        canonical = f"{serial}|{series_character}|{region}"
        if canonical not in used_targets:
            used_targets.add(canonical)
            return canonical, str(serial), series_character, str(region)
        attempt += 1


def _augmentation_for_variant(seed: int, group_id: str, variant_index: int) -> Augmentation:
    noise_seed = int(_stable_digest("noise", seed, group_id, variant_index)[:16], 16)
    if variant_index == 0:
        return Augmentation(0.0, 1.0, 0.0, 0.0, noise_seed)
    rng = _stable_rng("augmentation", seed, group_id, variant_index)
    rotation = round(rng.uniform(-2.2, 2.2), 3)
    if abs(rotation) < 0.35:
        rotation = 0.35 if rotation >= 0 else -0.35
    return Augmentation(
        rotation_degrees=rotation,
        brightness_factor=round(rng.uniform(0.84, 1.16), 3),
        blur_radius=round(rng.uniform(0.1, 0.75), 3),
        noise_sigma=round(rng.uniform(1.5, 5.0), 3),
        noise_seed=noise_seed,
    )


def build_sample_plan(
    *,
    seed: int,
    group_counts: Mapping[str, int],
    variants_per_group: int,
    series: str | Sequence[str] = DEFAULT_SERIES,
    unified_fraction: float = DEFAULT_UNIFIED_FRACTION,
) -> tuple[SyntheticSample, ...]:
    """Create a deterministic group-safe plan without importing Pillow."""

    normalized_counts = {split: int(group_counts[split]) for split in group_counts}
    _validate_counts(
        normalized_counts, int(variants_per_group), float(unified_fraction)
    )
    normalized_series = parse_series(series)
    unsupported_series = sorted(set(normalized_series).difference(OFFICIAL_SERIES_MAPPING))
    if unsupported_series and float(unified_fraction) > 0:
        raise ProtocolError(
            "Série sans correspondance latine officielle: "
            + ", ".join(unsupported_series)
        )
    samples: list[SyntheticSample] = []
    used_targets: set[str] = set()
    group_index = 0

    for split in DEVELOPMENT_SPLITS:
        if float(unified_fraction) <= 0.0:
            unified_groups = 0
        elif float(unified_fraction) >= 1.0:
            unified_groups = normalized_counts[split]
        else:
            unified_groups = max(
                1,
                min(
                    normalized_counts[split] - 1,
                    int(normalized_counts[split] * float(unified_fraction) + 0.5),
                ),
            )
        for split_group_index in range(normalized_counts[split]):
            group_digest = _stable_digest("group", seed, group_index)
            group_id = f"synthetic-{group_digest[:16]}"
            target, serial, series_arabic, region = _target_for_group(
                seed=int(seed),
                group_index=group_index,
                series=normalized_series,
                used_targets=used_targets,
            )
            is_unified = split_group_index < unified_groups
            series_latin = OFFICIAL_SERIES_MAPPING[series_arabic] if is_unified else ""
            format_profile = "unified_2026_arabic_latin" if is_unified else "legacy_arabic"
            recognition_text = (
                f"MA{serial}{series_arabic}{series_latin}{region}"
                if is_unified
                else f"{serial}{series_arabic}{region}"
            )
            parsed = parse_plate_text(
                recognition_text,
                bilingual_mapping=OFFICIAL_SERIES_MAPPING,
                require_verified_bilingual=is_unified,
            )
            if not parsed.valid or parsed.canonical != target:
                raise AssertionError(f"Cible synthétique interne invalide: {target}")
            for variant_index in range(int(variants_per_group)):
                sample_digest = _stable_digest("sample", group_id, variant_index)
                sample_id = f"syn-{sample_digest[:20]}"
                variant_id = f"variant-{variant_index:02d}"
                samples.append(
                    SyntheticSample(
                        sample_id=sample_id,
                        image_path=f"images/{split}/{sample_id}.png",
                        group_id=group_id,
                        split=split,
                        target=target,
                        recognition_text=recognition_text,
                        format_profile=format_profile,
                        series_latin=series_latin,
                        variant_id=variant_id,
                        augmentation=_augmentation_for_variant(
                            int(seed), group_id, variant_index
                        ),
                    )
                )
            group_index += 1
    return tuple(samples)


def validate_ofl_license(path: str | Path) -> bytes:
    license_path = Path(path)
    if not license_path.is_file():
        raise FileNotFoundError(f"Preuve OFL absente: {license_path}")
    payload = license_path.read_bytes()
    try:
        text = payload.decode("utf-8")
    except UnicodeDecodeError as error:
        raise ProtocolError("La preuve OFL doit être un fichier texte UTF-8.") from error
    normalized = " ".join(text.upper().split())
    if "SIL OPEN FONT LICENSE" not in normalized or "VERSION 1.1" not in normalized:
        raise ProtocolError("La preuve fournie n'atteste pas la SIL OFL version 1.1.")
    return payload


def inspect_noto_font(
    font_path: str | Path,
    license_path: str | Path,
    *,
    series: Sequence[str],
    source_url: str = SOURCE_URL,
) -> FontProvenance:
    font_file = Path(font_path)
    if not font_file.is_file():
        raise FileNotFoundError(f"Police Noto absente: {font_file}")
    if font_file.suffix.lower() not in {".ttf", ".otf"}:
        raise ProtocolError("La police Noto doit être un fichier TTF ou OTF.")
    if not source_url.startswith(
        "https://github.com/notofonts/arabic/releases/tag/"
    ):
        raise ProtocolError("La provenance de la police doit pointer vers une release Noto Arabic.")
    license_payload = validate_ofl_license(license_path)

    try:
        import PIL
        from PIL import ImageFont, features
    except ImportError as error:
        raise ProtocolError(
            "Pillow est requis au rendu; utilisez l'environnement OCR Colab isolé."
        ) from error

    try:
        font = ImageFont.truetype(os.fspath(font_file), size=64)
    except OSError as error:
        raise ProtocolError(f"Police TTF/OTF illisible: {font_file}") from error
    family, style = (str(value) for value in font.getname())
    identity = f"{family} {style}".lower()
    if "noto" not in identity or "arab" not in identity:
        raise ProtocolError(
            f"Police refusée: famille Noto Arabic attendue, reçue {family!r} {style!r}."
        )
    for character in "0123456789" + "".join(series):
        if font.getmask(character).getbbox() is None:
            raise ProtocolError(f"Glyphe absent de la police Noto: {character!r}.")

    freetype_version = features.version_module("freetype2")
    return FontProvenance(
        family=family,
        style=style,
        font_sha256=file_sha256(font_file),
        license_sha256=hashlib.sha256(license_payload).hexdigest(),
        source_url=source_url,
        archive_url=SOURCE_ARCHIVE_URL,
        archive_sha256=SOURCE_ARCHIVE_SHA256,
        pillow_version=PIL.__version__,
        freetype_version=freetype_version,
        raqm_available=bool(features.check("raqm")),
    )


def inspect_noto_latin_font(
    font_path: str | Path, license_path: str | Path
) -> LatinFontProvenance:
    font_file = Path(font_path)
    license_file = Path(license_path)
    if file_sha256(font_file) != LATIN_FONT_SHA256:
        raise ProtocolError("Empreinte de la police Noto Sans latine inattendue.")
    license_payload = validate_ofl_license(license_file)
    license_sha256 = hashlib.sha256(license_payload).hexdigest()
    if license_sha256 != LATIN_LICENSE_SHA256:
        raise ProtocolError("Empreinte de la licence Noto Sans latine inattendue.")
    try:
        from PIL import ImageFont
    except ImportError as error:
        raise ProtocolError(
            "Pillow est requis au rendu; utilisez l'environnement OCR Colab isolé."
        ) from error
    try:
        font = ImageFont.truetype(os.fspath(font_file), size=48)
    except OSError as error:
        raise ProtocolError(f"Police Noto Sans latine illisible: {font_file}") from error
    family, style = (str(value) for value in font.getname())
    if "noto sans" not in f"{family} {style}".lower():
        raise ProtocolError(
            f"Police latine refusée: Noto Sans attendue, reçue {family!r} {style!r}."
        )
    for character in "0123456789MA" + "".join(OFFICIAL_SERIES_MAPPING.values()):
        if font.getmask(character).getbbox() is None:
            raise ProtocolError(f"Glyphe latin absent de Noto Sans: {character!r}.")
    return LatinFontProvenance(
        family=family,
        style=style,
        font_sha256=LATIN_FONT_SHA256,
        license_sha256=license_sha256,
        font_url=LATIN_FONT_URL,
        license_url=LATIN_LICENSE_URL,
        repository_commit=LATIN_FONT_REPOSITORY_COMMIT,
    )


def _add_noise(image: Any, *, sigma: float, seed: int) -> Any:
    if sigma <= 0:
        return image
    from PIL import Image

    rng = random.Random(seed)
    pixels = bytearray(image.tobytes())
    for index, value in enumerate(pixels):
        noisy = int(round(value + rng.gauss(0.0, sigma)))
        pixels[index] = max(0, min(255, noisy))
    return Image.frombytes(image.mode, image.size, bytes(pixels))


def render_sample(
    sample: SyntheticSample,
    destination: Path,
    font_path: Path,
    latin_font_path: Path,
) -> None:
    """Render one one-line research plate; no real identifier is consumed."""

    try:
        from PIL import Image, ImageDraw, ImageEnhance, ImageFilter, ImageFont
    except ImportError as error:
        raise ProtocolError("Pillow est requis pour produire les images synthétiques.") from error

    font = ImageFont.truetype(os.fspath(font_path), size=64)
    compact_arabic_font = ImageFont.truetype(os.fspath(font_path), size=34)
    compact_latin_font = ImageFont.truetype(os.fspath(latin_font_path), size=34)
    country_font = ImageFont.truetype(os.fspath(latin_font_path), size=28)
    image = Image.new("L", (520, 110), color=248)
    draw = ImageDraw.Draw(image)
    draw.rounded_rectangle((2, 2, 517, 107), radius=7, outline=18, width=3)

    parsed = parse_plate_text(
        sample.recognition_text,
        bilingual_mapping=OFFICIAL_SERIES_MAPPING,
        require_verified_bilingual=(sample.format_profile == "unified_2026_arabic_latin"),
    )
    if not parsed.valid or not parsed.serial or not parsed.series_arabic or not parsed.region:
        raise ProtocolError(f"Cible synthétique non rendable: {sample.target}")
    if sample.format_profile == "unified_2026_arabic_latin":
        if not sample.series_latin:
            raise ProtocolError("Correspondance latine absente du profil unifié.")
        draw.line((84, 12, 84, 98), fill=20, width=3)
        draw.line((344, 12, 344, 98), fill=20, width=3)
        draw.line((426, 12, 426, 98), fill=20, width=3)
        draw.line((356, 55, 416, 55), fill=20, width=2)
        draw.text((43, 56), "MA", font=country_font, fill=16, anchor="mm")
        draw.text((214, 56), parsed.serial, font=font, fill=16, anchor="mm")
        draw.text((386, 34), parsed.series_arabic, font=compact_arabic_font, fill=16, anchor="mm")
        draw.text((386, 77), sample.series_latin, font=compact_latin_font, fill=16, anchor="mm")
        draw.text((472, 56), parsed.region, font=font, fill=16, anchor="mm")
    elif sample.format_profile == "legacy_arabic":
        draw.line((362, 12, 362, 98), fill=20, width=3)
        draw.line((426, 12, 426, 98), fill=20, width=3)
        draw.text((182, 56), parsed.serial, font=font, fill=16, anchor="mm")
        draw.text((394, 55), parsed.series_arabic, font=font, fill=16, anchor="mm")
        draw.text((472, 56), parsed.region, font=font, fill=16, anchor="mm")
    else:
        raise ProtocolError(f"Profil de rendu inconnu: {sample.format_profile!r}.")

    augmentation = sample.augmentation
    if augmentation.brightness_factor != 1.0:
        image = ImageEnhance.Brightness(image).enhance(augmentation.brightness_factor)
    if augmentation.blur_radius > 0:
        image = image.filter(ImageFilter.GaussianBlur(augmentation.blur_radius))
    if augmentation.rotation_degrees != 0:
        image = image.rotate(
            augmentation.rotation_degrees,
            resample=Image.Resampling.BICUBIC,
            expand=False,
            fillcolor=235,
        )
    image = _add_noise(
        image,
        sigma=augmentation.noise_sigma,
        seed=augmentation.noise_seed,
    )
    destination.parent.mkdir(parents=True, exist_ok=True)
    image.save(destination, format="PNG", compress_level=9, optimize=False)


def _write_paddle_labels(root: Path, samples: Sequence[SyntheticSample]) -> None:
    grouped: dict[tuple[str, str], list[SyntheticSample]] = defaultdict(list)
    for sample in samples:
        grouped[(sample.split, sample.group_id)].append(sample)

    labels_dir = root / "labels"
    labels_dir.mkdir(parents=True, exist_ok=True)
    for split in DEVELOPMENT_SPLITS:
        lines: list[str] = []
        groups = sorted(
            (
                sorted(group_samples, key=lambda item: item.variant_id)
                for (group_split, _), group_samples in grouped.items()
                if group_split == split
            ),
            key=lambda group_samples: group_samples[0].group_id,
        )
        for group_samples in groups:
            paths = [sample.image_path for sample in group_samples]
            image_reference = (
                paths[0]
                if len(paths) == 1
                else json.dumps(paths, ensure_ascii=False, separators=(",", ":"))
            )
            lines.append(f"{image_reference}\t{group_samples[0].recognition_text}\n")
        (labels_dir / f"rec_gt_{split}.txt").write_text(
            "".join(lines), encoding="utf-8"
        )


def _write_dictionary(root: Path, series: Sequence[str]) -> None:
    latin = tuple(
        dict.fromkeys(
            OFFICIAL_SERIES_MAPPING[character]
            for character in series
            if character in OFFICIAL_SERIES_MAPPING
        )
    )
    characters = tuple(
        dict.fromkeys(tuple("0123456789") + tuple(series) + latin + tuple("MA"))
    )
    (root / "paddleocr_dict.txt").write_text(
        "".join(f"{character}\n" for character in characters), encoding="utf-8"
    )


def _dataset_images_digest(rows: Sequence[Mapping[str, str]]) -> str:
    payload = "".join(
        f"{row['sha256']}  {row['image_path']}\n"
        for row in sorted(rows, key=lambda item: str(item["image_path"]))
    )
    return hashlib.sha256(payload.encode("utf-8")).hexdigest()


def _materialize_dataset(
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
    renderer: Renderer,
) -> GenerationResult:
    if output_dir.exists():
        raise FileExistsError(
            f"Le dossier de sortie existe déjà; aucun écrasement autorisé: {output_dir}"
        )
    output_dir.parent.mkdir(parents=True, exist_ok=True)

    with tempfile.TemporaryDirectory(
        prefix=".anpr-synthetic-", dir=output_dir.parent
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
        for sample in samples:
            destination = root / sample.image_path
            renderer(sample, destination, copied_font, copied_latin_font)
            if not destination.is_file():
                raise ProtocolError(f"Le renderer n'a pas créé {sample.image_path}.")
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
                    "generator_version": GENERATOR_VERSION,
                    "font_sha256": provenance.font_sha256,
                    "latin_font_sha256": latin_provenance.font_sha256,
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
        _write_paddle_labels(root, samples)
        _write_dictionary(root, series)

        manifest_path = root / "manifest.csv"
        with manifest_path.open("w", encoding="utf-8", newline="") as handle:
            writer = csv.DictWriter(
                handle, fieldnames=REQUIRED_COLUMNS + EXTRA_MANIFEST_COLUMNS
            )
            writer.writeheader()
            writer.writerows(rows)

        images_digest = _dataset_images_digest(rows)
        format_groups: dict[str, set[str]] = defaultdict(set)
        for row in rows:
            format_groups[row["format_profile"]].add(row["group_id"])
        report = {
            "schema_version": "1.0.0",
            "generator_version": GENERATOR_VERSION,
            "protocol_version": PROTOCOL_VERSION,
            "purpose": "development_only_not_independent_evidence",
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
                    character: OFFICIAL_SERIES_MAPPING[character]
                    for character in series
                },
                "format_group_counts": {
                    name: len(groups) for name, groups in sorted(format_groups.items())
                },
                "rendered_layouts": [
                    "legacy_arabic_one_line_520x110",
                    "unified_2026_arabic_latin_ma_one_line_520x110",
                ],
                "paddle_label_uses_space_character": False,
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
                "images": len(rows),
                "manifest": "manifest.csv",
                "manifest_sha256": file_sha256(manifest_path),
                "dataset_images_sha256": images_digest,
                "paddle_dictionary": "paddleocr_dict.txt",
                "paddle_labels": {
                    split: f"labels/rec_gt_{split}.txt" for split in DEVELOPMENT_SPLITS
                },
            },
            "limits": [
                "Synthetic OCR development data is not real-domain accuracy evidence.",
                "No independent test sample or label is present.",
                "The unified layout is an approximate synthetic rendering of the regulatory components, not a photograph of an official plate.",
                "Detection and end-to-end real-photo accuracy are evaluated separately.",
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

        manifest_digest = file_sha256(manifest_path)
        root.replace(output_dir)

    return GenerationResult(
        output_dir=output_dir,
        groups=sum(int(value) for value in group_counts.values()),
        images=len(samples),
        split_groups={split: int(group_counts[split]) for split in DEVELOPMENT_SPLITS},
        manifest_sha256=manifest_digest,
        dataset_images_sha256=images_digest,
    )


def generate_dataset(
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
) -> GenerationResult:
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
    latin_provenance = inspect_noto_latin_font(
        latin_font_file,
        latin_license_file,
    )
    return _materialize_dataset(
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
        renderer=render_sample,
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
    group_counts = {
        "train": arguments.train_groups,
        "validation": arguments.validation_groups,
        "calibration": arguments.calibration_groups,
    }
    try:
        result = generate_dataset(
            output_dir=arguments.output_dir,
            font_path=arguments.font,
            license_path=arguments.font_license,
            latin_font_path=arguments.latin_font,
            latin_license_path=arguments.latin_font_license,
            seed=arguments.seed,
            group_counts=group_counts,
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
