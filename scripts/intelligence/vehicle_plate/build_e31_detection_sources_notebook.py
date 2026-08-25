#!/usr/bin/env python3
"""Generate the output-free ANPR E3.1 detection-source notebook."""

from __future__ import annotations

import json
from pathlib import Path
from typing import Any


ROOT = Path(__file__).resolve().parents[3]
CELL_SPEC = Path(__file__).with_name("e31_detection_sources_cells.json")
OUTPUT = ROOT / "notebooks/colab/moroccan_vehicle_plate_anpr_v2_e31_detection_sources.ipynb"


def load_cells() -> list[dict[str, Any]]:
    document = json.loads(CELL_SPEC.read_text(encoding="utf-8"))
    items = document.get("cells")
    if not isinstance(items, list) or not items:
        raise ValueError("e31_detection_sources_cells.json doit contenir cells")
    cells: list[dict[str, Any]] = []
    for index, item in enumerate(items):
        if not isinstance(item, dict):
            raise ValueError(f"cellule {index}: objet attendu")
        cell_type = item.get("cell_type")
        source = item.get("source")
        if cell_type not in {"markdown", "code"}:
            raise ValueError(f"cellule {index}: type non pris en charge")
        if not isinstance(source, list) or not all(isinstance(line, str) for line in source):
            raise ValueError(f"cellule {index}: source invalide")
        cell: dict[str, Any] = {"cell_type": cell_type, "metadata": {}, "source": source}
        if cell_type == "code":
            cell.update({"execution_count": None, "outputs": []})
        cells.append(cell)
    return cells


def main() -> int:
    notebook = {
        "cells": load_cells(),
        "metadata": {
            "colab": {"name": OUTPUT.name, "provenance": []},
            "kernelspec": {
                "display_name": "Python 3",
                "language": "python",
                "name": "python3",
            },
            "language_info": {"name": "python", "version": "3.x"},
        },
        "nbformat": 4,
        "nbformat_minor": 5,
    }
    OUTPUT.parent.mkdir(parents=True, exist_ok=True)
    OUTPUT.write_text(
        json.dumps(notebook, ensure_ascii=False, indent=1) + "\n", encoding="utf-8"
    )
    print(OUTPUT)
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
