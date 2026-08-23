#!/usr/bin/env python3
"""Validate the frozen vehicle-damage manifest before any GPU training."""

from __future__ import annotations

import argparse
import json

from protocol import load_manifest, validate_manifest


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("manifest", help="Frozen CSV manifest")
    parser.add_argument("--json", action="store_true", dest="as_json")
    args = parser.parse_args()

    report = validate_manifest(load_manifest(args.manifest))
    if args.as_json:
        print(json.dumps(report.as_dict(), ensure_ascii=False, indent=2, sort_keys=True))
    else:
        print(f"OK — {report.rows} images; splits={dict(report.split_counts)}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
