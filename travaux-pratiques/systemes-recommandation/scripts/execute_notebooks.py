"""Exécute et sauvegarde les notebooks avec leurs sorties.

L'environnement de travail interdit les sockets Jupyter. L'exécution est donc
réalisée dans un interpréteur Python unique, cellule par cellule, tout en
recréant les sorties standard, HTML et PNG du format notebook.
"""

from __future__ import annotations

import argparse
import base64
import contextlib
import io
import json
import os
import traceback
from pathlib import Path


ROOT = Path(__file__).resolve().parents[1]


def execute(path: Path) -> None:
    notebook = json.loads(path.read_text(encoding="utf-8"))
    namespace = {"__name__": "__main__"}
    execution_count = 0
    previous_cwd = Path.cwd()
    os.chdir(ROOT)
    try:
        for cell in notebook["cells"]:
            if cell["cell_type"] != "code":
                continue
            execution_count += 1
            cell["execution_count"] = execution_count
            cell["outputs"] = []
            stdout = io.StringIO()
            stderr = io.StringIO()
            displayed = []

            def capture_display(*objects, **_kwargs):
                displayed.extend(objects)

            # Après la cellule d'import, les cellules suivantes utilisent ce capteur.
            namespace["display"] = capture_display
            try:
                with contextlib.redirect_stdout(stdout), contextlib.redirect_stderr(stderr):
                    exec(compile(cell["source"], f"{path.name}:cell-{execution_count}", "exec"), namespace)
            except Exception as exc:
                text = stdout.getvalue()
                if text:
                    cell["outputs"].append({"output_type": "stream", "name": "stdout", "text": text})
                err = stderr.getvalue()
                if err:
                    cell["outputs"].append({"output_type": "stream", "name": "stderr", "text": err})
                cell["outputs"].append({
                    "output_type": "error",
                    "ename": type(exc).__name__,
                    "evalue": str(exc),
                    "traceback": traceback.format_exc().splitlines(),
                })
                raise

            text = stdout.getvalue()
            if text:
                cell["outputs"].append({"output_type": "stream", "name": "stdout", "text": text})
            err = stderr.getvalue()
            if err:
                cell["outputs"].append({"output_type": "stream", "name": "stderr", "text": err})

            for obj in displayed:
                data = {"text/plain": repr(obj)}
                if hasattr(obj, "_repr_html_"):
                    html = obj._repr_html_()
                    if html:
                        data["text/html"] = html
                cell["outputs"].append({"output_type": "display_data", "data": data, "metadata": {}})

            if "plt" in namespace:
                plt = namespace["plt"]
                for number in list(plt.get_fignums()):
                    figure = plt.figure(number)
                    buffer = io.BytesIO()
                    figure.savefig(buffer, format="png", dpi=120, bbox_inches="tight")
                    encoded = base64.b64encode(buffer.getvalue()).decode("ascii")
                    cell["outputs"].append({
                        "output_type": "display_data",
                        "data": {"image/png": encoded, "text/plain": "<Figure>"},
                        "metadata": {},
                    })
                plt.close("all")
    finally:
        os.chdir(previous_cwd)
        path.write_text(json.dumps(notebook, ensure_ascii=False, indent=1), encoding="utf-8")
    print(f"Exécuté : {path.name}")


def main() -> None:
    parser = argparse.ArgumentParser()
    parser.add_argument("notebooks", nargs="*", help="Noms ou chemins des notebooks")
    args = parser.parse_args()
    paths = [Path(value) for value in args.notebooks]
    if not paths:
        paths = sorted((ROOT / "notebooks").glob("*.ipynb"))
    for path in paths:
        if not path.is_absolute():
            candidate = ROOT / "notebooks" / path
            path = candidate if candidate.exists() else ROOT / path
        execute(path.resolve())


if __name__ == "__main__":
    main()
