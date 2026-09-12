"""Convert a reviewed CSV into a SaaS import index and a private image folder.

All paths are local. No network, database, inference or automatic annotation.
"""
import argparse
import csv
import hashlib
import io
import json
import re
import warnings
from pathlib import Path

from workbench import FAMILIES, require, sha256, write_json


def verified_image_bytes(path, extension):
    from PIL import Image

    with path.open("rb") as stream:
        payload = stream.read(8 * 1024 * 1024 + 1)
    require(0 < len(payload) <= 8 * 1024 * 1024, "Image exceeds 8 MiB or is empty")
    expected = {".jpg": "JPEG", ".jpeg": "JPEG", ".png": "PNG", ".webp": "WEBP"}[extension]
    try:
        with warnings.catch_warnings():
            warnings.simplefilter("error", Image.DecompressionBombWarning)
            with Image.open(io.BytesIO(payload)) as image:
                require(image.format == expected, "Image content does not match its extension")
                require(0 < image.width <= 8000 and 0 < image.height <= 8000, "Oversized image")
                require(not getattr(image, "is_animated", False), "Animated images are not supported")
                image.verify()
            with Image.open(io.BytesIO(payload)) as image:
                image.load()
    except (OSError, SyntaxError, Image.DecompressionBombError, Image.DecompressionBombWarning) as error:
        raise ValueError("Invalid or corrupt image content") from error
    return payload


def convert(source, family, output, image_root=None, delimiter=";"):
    require(family in FAMILIES, "Unknown model family")
    output = Path(output).resolve()
    require(not any((p / '.git').exists() for p in (output, *output.parents)), "Keep private datasets outside a Git checkout")
    output.mkdir(parents=True, exist_ok=False, mode=0o700)
    images = Path(image_root).resolve(strict=True) if image_root else None
    rows, keys, digests = [], set(), set()
    with Path(source).open(encoding="utf-8-sig", newline="") as stream:
        reader = csv.DictReader(stream, delimiter=delimiter)
        for record in reader:
            require(len(rows) < 20000, "Split the CSV into batches of at most 20000 rows")
            row = {name: record[name].strip() for name in ("key", "group")}
            require(all(re.fullmatch(r"[A-Za-z0-9_-]{1,80}", value) for value in row.values()), "Use stable, non-identifying keys and groups")
            require(row["key"] not in keys, "Duplicate sample key")
            keys.add(row["key"])
            if family == "demand":
                row.update({"date": record["date"].strip(), "value": int(record["value"])})
            elif family == "anomaly":
                row.update({"date": record["date"].strip(), "label": int(record["label"])})
                row.update({name: float(record[name]) for name in ("late_hours", "km_per_day", "fuel_drop_pct")})
            else:
                require(images is not None, "An image root is required")
                relative = Path(record["image"].strip())
                require(not relative.is_absolute() and '..' not in relative.parts, "Use image paths relative to the private image root")
                path = images / relative
                require(not path.is_symlink() and path.resolve(strict=True).is_relative_to(images), "Unsafe image path")
                extension = path.suffix.lower()
                require(extension in {".jpg", ".jpeg", ".png", ".webp"}, "Supported images: JPEG, PNG, WebP")
                require(path.stat().st_size <= 8 * 1024 * 1024, "Image exceeds 8 MiB")
                payload = verified_image_bytes(path, extension)
                image_hash = hashlib.sha256(payload).hexdigest()
                require(image_hash not in digests, "Duplicate photo: keep one verified annotation")
                digests.add(image_hash)
                row["image_sha256"] = image_hash
                if family == "damage":
                    row["boxes"] = json.loads(record["boxes"])
                else:
                    row["label"] = record["label"].strip()
                destination = output / 'images_verifiees' / (image_hash + ('.jpg' if extension == '.jpeg' else extension))
                destination.parent.mkdir(exist_ok=True, mode=0o700)
                with destination.open('xb') as image_file:
                    image_file.write(payload)
                destination.chmod(0o600)
            rows.append(row)
    require(rows, "No observations in CSV")
    payload = {"schema_version": "1.0", "family": family, "rows": rows}
    require(len(json.dumps(payload, ensure_ascii=False).encode()) <= 5 * 1024 * 1024, "Split this import into smaller batches (5 MiB limit)")
    write_json(output / 'dataset.json', payload, compact=True)
    write_json(output / 'source-receipt.json', {"csv_sha256": sha256(source), "rows": len(rows), "labels": "provided by operator; no automatic ground truth"})
    return output / 'dataset.json'


if __name__ == '__main__':
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--csv', type=Path, required=True)
    parser.add_argument('--family', choices=sorted(FAMILIES), required=True)
    parser.add_argument('--output', type=Path, required=True)
    parser.add_argument('--image-root', type=Path)
    parser.add_argument('--delimiter', default=';', choices=[';', ','])
    args = parser.parse_args()
    print(convert(args.csv, args.family, args.output, args.image_root, args.delimiter))
