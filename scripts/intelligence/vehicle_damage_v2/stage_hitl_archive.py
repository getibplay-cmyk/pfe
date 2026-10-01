#!/usr/bin/env python3
"""Stage the approved HITL archive from private Drive into ephemeral Colab disk."""

from __future__ import annotations

import argparse
import hashlib
import json
import os
import zipfile
from pathlib import Path


EXPECTED_BYTES = 3_073_458_211
EXPECTED_SHA256 = "7f109cdd9ab4850d47bed3a737536e6b7e6a9253733e9c761b13617259c5a93e"
CHUNK_BYTES = 8 * 1024 * 1024


def sha256(path: Path) -> str:
    digest = hashlib.sha256()
    with path.open("rb") as handle:
        while chunk := handle.read(CHUNK_BYTES):
            digest.update(chunk)
    return digest.hexdigest()


def copy_verified(source: Path, destination: Path) -> str:
    if source.stat().st_size != EXPECTED_BYTES:
        raise ValueError("Taille de l'archive HITL différente de l'inventaire privé.")
    digest = hashlib.sha256()
    copied = 0
    destination.parent.mkdir(parents=True, exist_ok=True)
    with source.open("rb") as reader, destination.open("wb") as writer:
        while chunk := reader.read(CHUNK_BYTES):
            writer.write(chunk)
            digest.update(chunk)
            copied += len(chunk)
        writer.flush()
        os.fsync(writer.fileno())
    value = digest.hexdigest()
    if copied != EXPECTED_BYTES or value != EXPECTED_SHA256:
        raise ValueError("Copie HITL incomplète ou empreinte SHA-256 inattendue.")
    return value


def safe_extract(archive_path: Path, output: Path, digest: str) -> None:
    marker = output / ".archive_sha256"
    if marker.is_file() and marker.read_text(encoding="utf-8").strip() == digest:
        return
    if output.exists() and any(output.iterdir()):
        raise ValueError("Le dossier d'extraction non vide ne porte pas le bon marqueur.")
    output.mkdir(parents=True, exist_ok=True)
    resolved_root = output.resolve()
    with zipfile.ZipFile(archive_path) as archive:
        if bad_member := archive.testzip():
            raise ValueError(f"Membre ZIP corrompu: {bad_member}")
        for member in archive.infolist():
            candidate = (output / member.filename).resolve()
            if candidate != resolved_root and resolved_root not in candidate.parents:
                raise ValueError(f"Chemin ZIP dangereux: {member.filename}")
        archive.extractall(output)
    marker.write_text(digest + "\n", encoding="utf-8")


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--source", type=Path, required=True)
    parser.add_argument("--local-archive", type=Path, required=True)
    parser.add_argument("--extract-root", type=Path, required=True)
    args = parser.parse_args()

    if not args.source.is_file():
        raise FileNotFoundError(f"Archive HITL privée absente: {args.source}")
    if (
        args.local_archive.is_file()
        and args.local_archive.stat().st_size == EXPECTED_BYTES
        and sha256(args.local_archive) == EXPECTED_SHA256
    ):
        digest = EXPECTED_SHA256
    else:
        digest = copy_verified(args.source, args.local_archive)
    safe_extract(args.local_archive, args.extract_root, digest)
    report = {
        "archive_bytes": EXPECTED_BYTES,
        "archive_sha256": digest,
        "extract_marker": str(args.extract_root / ".archive_sha256"),
        "status": "ready",
    }
    print(json.dumps(report, sort_keys=True))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())

