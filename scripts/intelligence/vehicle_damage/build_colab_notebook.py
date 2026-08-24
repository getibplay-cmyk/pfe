#!/usr/bin/env python3
"""Generate the committed Colab notebook from auditable source cells."""

from __future__ import annotations

import json
from pathlib import Path
from typing import Any


ROOT = Path(__file__).resolve().parents[3]
CELL_SPEC = Path(__file__).with_name("colab_cells.json")
OUTPUT = ROOT / "notebooks/colab/vehicle_damage_efficientnetv2s.ipynb"


def load_cells() -> list[dict[str, Any]]:
    """Load and validate the public, output-free cell specification."""

    document = json.loads(CELL_SPEC.read_text(encoding="utf-8"))
    items = document.get("cells")
    if not isinstance(items, list) or not items:
        raise ValueError("colab_cells.json must contain a non-empty cells list")

    cells: list[dict[str, Any]] = []
    for index, item in enumerate(items):
        if not isinstance(item, dict):
            raise ValueError(f"cell {index} must be an object")
        cell_type = item.get("cell_type")
        source = item.get("source")
        if cell_type not in {"markdown", "code"}:
            raise ValueError(f"cell {index} has unsupported type {cell_type!r}")
        if not isinstance(source, list) or not all(isinstance(line, str) for line in source):
            raise ValueError(f"cell {index} source must be a list of strings")

        cell: dict[str, Any] = {
            "cell_type": cell_type,
            "metadata": {},
            "source": source,
        }
        if cell_type == "code":
            cell.update({"execution_count": None, "outputs": []})
        cells.append(cell)
    return cells


def main() -> int:
    notebook = {
        "cells": load_cells(),
        "metadata": {
            "accelerator": "GPU",
            "colab": {"name": OUTPUT.name, "provenance": []},
            "kernelspec": {"display_name": "Python 3", "language": "python", "name": "python3"},
            "language_info": {"name": "python", "version": "3.x"},
        },
        "nbformat": 4,
        "nbformat_minor": 5,
    }
    OUTPUT.parent.mkdir(parents=True, exist_ok=True)
    OUTPUT.write_text(json.dumps(notebook, ensure_ascii=False, indent=1) + "\n", encoding="utf-8")
    print(OUTPUT)
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
