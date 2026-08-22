#!/usr/bin/env python3
"""Split a large immutable archive into Drive-friendly verified parts."""

from __future__ import annotations

import argparse
import hashlib
import json
from datetime import datetime, timezone
from pathlib import Path


def sha256_file(path: Path, chunk_size: int = 8 * 1024 * 1024) -> str:
    digest = hashlib.sha256()
    with path.open("rb") as stream:
        for chunk in iter(lambda: stream.read(chunk_size), b""):
            digest.update(chunk)
    return digest.hexdigest()


def main() -> None:
    parser = argparse.ArgumentParser()
    parser.add_argument("--archive", type=Path, required=True)
    parser.add_argument("--output-dir", type=Path, required=True)
    parser.add_argument("--part-bytes", type=int, default=95_000_000)
    args = parser.parse_args()

    archive = args.archive.resolve()
    output = args.output_dir.resolve()
    if args.part_bytes < 1_000_000:
        raise ValueError("part-bytes must be >= 1,000,000")
    output.mkdir(parents=True, exist_ok=True)
    if any(output.iterdir()):
        raise FileExistsError(f"Output directory must be empty: {output}")

    parts = []
    combined = hashlib.sha256()
    with archive.open("rb") as source:
        index = 0
        while True:
            payload = source.read(args.part_bytes)
            if not payload:
                break
            combined.update(payload)
            part = output / f"{archive.name}.part{index:03d}"
            part.write_bytes(payload)
            parts.append({"name": part.name, "bytes": len(payload), "sha256": hashlib.sha256(payload).hexdigest()})
            index += 1
    archive_sha256 = sha256_file(archive)
    if combined.hexdigest() != archive_sha256:
        raise RuntimeError("Multipart stream SHA-256 mismatch")

    registry = {
        "schema_version": "1.0.0",
        "created_at_utc": datetime.now(timezone.utc).replace(microsecond=0).isoformat().replace("+00:00", "Z"),
        "status": "VERIFIED_MULTIPART_TRANSPORT_ONLY",
        "archive": {"name": archive.name, "bytes": archive.stat().st_size, "sha256": archive_sha256},
        "part_bytes": args.part_bytes,
        "parts": parts,
        "reassembly": "concatenate parts in listed order without separators",
    }
    registry_path = output / f"{archive.name}.multipart.json"
    registry_path.write_text(json.dumps(registry, indent=2, sort_keys=True) + "\n", encoding="utf-8")
    print(json.dumps({"status": registry["status"], "parts": len(parts), "registry": str(registry_path)}, indent=2))


if __name__ == "__main__":
    main()
