#!/usr/bin/env python3
"""Select one S7 colour v8 checkpoint using validation-only reports.

The selected checkpoint is copied into an immutable-style bundle so the next
qualification command cannot silently switch architecture after calibration.
"""

from __future__ import annotations

import argparse
import hashlib
import json
import os
import shutil
import tempfile
from datetime import datetime, timezone
from pathlib import Path


def utc_now() -> str:
    return datetime.now(timezone.utc).replace(microsecond=0).isoformat().replace("+00:00", "Z")


def sha256_file(path: Path, chunk_size: int = 8 * 1024 * 1024) -> str:
    digest = hashlib.sha256()
    with path.open("rb") as stream:
        for chunk in iter(lambda: stream.read(chunk_size), b""):
            digest.update(chunk)
    return digest.hexdigest()


def atomic_json(path: Path, payload: dict) -> None:
    descriptor, temporary = tempfile.mkstemp(prefix=f".{path.name}.", dir=path.parent)
    try:
        with os.fdopen(descriptor, "w", encoding="utf-8") as stream:
            json.dump(payload, stream, indent=2, sort_keys=True)
            stream.write("\n")
            stream.flush()
            os.fsync(stream.fileno())
        os.replace(temporary, path)
    except Exception:
        try:
            os.unlink(temporary)
        except FileNotFoundError:
            pass
        raise


def validation_key(report: dict, state_bytes: int) -> tuple:
    metrics = report["metrics"]["validation"]
    return (
        bool(report["decisions"]["candidate_validation_gate_passed"]),
        float(metrics["min_class_recall"]),
        float(metrics["macro_f1"]),
        float(metrics["balanced_accuracy"]),
        float(metrics["accepted_precision_at_0_90"]),
        float(metrics["coverage_at_0_90"]),
        -float(metrics["ece"]),
        -int(state_bytes),
        str(report["candidate"]),
    )


def locate_state(report_path: Path, report: dict) -> tuple[Path, dict]:
    states = [
        (name, metadata)
        for name, metadata in report.get("artifacts", {}).items()
        if name.endswith("_CANDIDATE_STATE.pt")
    ]
    if len(states) != 1:
        raise ValueError(f"Expected exactly one candidate state in {report_path}")
    name, metadata = states[0]
    state = report_path.parent / name
    if not state.is_file() or sha256_file(state) != metadata["sha256"]:
        raise ValueError(f"Candidate state hash mismatch: {state}")
    return state, metadata


def main() -> None:
    parser = argparse.ArgumentParser()
    parser.add_argument("--reports", type=Path, nargs="+", required=True)
    parser.add_argument("--output-dir", type=Path, required=True)
    args = parser.parse_args()

    output = args.output_dir.resolve()
    output.mkdir(parents=True, exist_ok=True)
    if any(output.iterdir()):
        raise FileExistsError(f"Output directory must be empty: {output}")

    candidates = []
    for supplied in args.reports:
        report_path = supplied.resolve()
        report = json.loads(report_path.read_text(encoding="utf-8"))
        if report.get("stage") != "candidate_selection_validation_only":
            raise ValueError(f"Not a validation-only candidate report: {report_path}")
        protocol = report.get("selection_protocol", {})
        if protocol.get("calibration_images_loaded") is not False or protocol.get("final_images_loaded") is not False:
            raise ValueError(f"Candidate touched a forbidden split: {report_path}")
        state, state_metadata = locate_state(report_path, report)
        candidates.append(
            {
                "report_path": report_path,
                "report": report,
                "state_path": state,
                "state_metadata": state_metadata,
                "key": validation_key(report, int(state_metadata["bytes"])),
            }
        )
    if not candidates:
        raise ValueError("No candidate reports supplied")

    manifest_hashes = {candidate["report"]["manifest"]["sha256"] for candidate in candidates}
    ontologies = {tuple(candidate["report"]["ontology"]) for candidate in candidates}
    if len(manifest_hashes) != 1 or len(ontologies) != 1:
        raise ValueError("Candidates must use the same manifest and ontology")

    selected = max(candidates, key=lambda candidate: candidate["key"])
    selected_state = output / "S7_COLOR_V8_SELECTED_CANDIDATE_STATE.pt"
    selected_report = output / "S7_COLOR_V8_SELECTED_CANDIDATE_REPORT.json"
    shutil.copy2(selected["state_path"], selected_state)
    shutil.copy2(selected["report_path"], selected_report)

    ledger_path = output / "S7_COLOR_V8_SELECTION_LEDGER.json"
    ledger = {
        "schema_version": "8.0.0",
        "created_at_utc": utc_now(),
        "stage": "architecture_selected_validation_only",
        "manifest_sha256": next(iter(manifest_hashes)),
        "ontology": list(next(iter(ontologies))),
        "selection_rule": [
            "candidate_validation_gate_passed",
            "min_class_recall",
            "macro_f1",
            "balanced_accuracy",
            "accepted_precision_at_0_90",
            "coverage_at_0_90",
            "negative_ece",
            "smaller_state_on_exact_metric_tie",
            "architecture_name_on_exact_tie",
        ],
        "candidates": [
            {
                "candidate": candidate["report"]["candidate"],
                "report_sha256": sha256_file(candidate["report_path"]),
                "state_sha256": candidate["state_metadata"]["sha256"],
                "state_bytes": candidate["state_metadata"]["bytes"],
                "validation_selection_key": list(candidate["key"][:-1]),
                "candidate_validation_gate_passed": candidate["report"]["decisions"]["candidate_validation_gate_passed"],
            }
            for candidate in sorted(candidates, key=lambda item: item["report"]["candidate"])
        ],
        "selected": {
            "candidate": selected["report"]["candidate"],
            "copied_state": selected_state.name,
            "copied_state_sha256": sha256_file(selected_state),
            "copied_report": selected_report.name,
            "copied_report_sha256": sha256_file(selected_report),
        },
        "calibration_images_loaded": False,
        "final_images_loaded": False,
        "external_final_authorized": False,
        "saas_integration_authorized": False,
    }
    atomic_json(ledger_path, ledger)
    print(
        json.dumps(
            {
                "status": "CANDIDATE_SELECTED_VALIDATION_ONLY",
                "selected": ledger["selected"]["candidate"],
                "ledger": str(ledger_path),
                "external_final_authorized": False,
            },
            indent=2,
        )
    )


if __name__ == "__main__":
    main()
