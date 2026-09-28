"""Offline CycloneDX 1.6 inventory of committed locks, not a claim about deployment."""
from __future__ import annotations

import argparse
import hashlib
import json
import re
from pathlib import Path
from urllib.parse import quote

ROOT = Path(__file__).resolve().parents[2]


def inventory(root: Path, commit: str) -> dict:
    if not re.fullmatch(r"[0-9a-f]{40}", commit):
        raise ValueError("A complete reviewed commit SHA is required")
    components: dict[str, dict] = {}
    files: list[dict] = []

    def add(ecosystem: str, name: str, version: str, source: str) -> None:
        purl = f"pkg:{ecosystem}/{quote(name, safe='/')}@{quote(version, safe='')}"
        component = components.setdefault(purl, {"type": "library", "bom-ref": purl,
            "name": name, "version": version, "purl": purl, "properties": []})
        prop = {"name": "rentfleet:lockfile", "value": source}
        if prop not in component["properties"]:
            component["properties"].append(prop)

    def remember(path: Path) -> str:
        relative = path.relative_to(root).as_posix()
        files.append({"name": "rentfleet:lockfile-sha256:" + relative,
                      "value": hashlib.sha256(path.read_bytes()).hexdigest()})
        return relative

    composer_path = root / "composer.lock"
    source = remember(composer_path)
    composer = json.loads(composer_path.read_text())
    for package in composer.get("packages", []) + composer.get("packages-dev", []):
        add("composer", package["name"], package["version"], source)

    npm_path = root / "package-lock.json"
    source = remember(npm_path)
    npm = json.loads(npm_path.read_text())
    for path, package in npm["packages"].items():
        if path and "version" in package:
            name = package.get("name") or path.rsplit("node_modules/", 1)[-1]
            add("npm", name, package["version"], source)

    python_locks = sorted((root / "scripts/intelligence").glob("requirements-*.txt"))
    python_locks += sorted((root / "requirements").glob("*.lock"))
    unresolved = []
    for path in python_locks:
        source = remember(path)
        for line in path.read_text().splitlines():
            line = line.split("#", 1)[0].strip()
            if not line:
                continue
            match = re.fullmatch(r"([A-Za-z0-9_.-]+)(?:\[[^]]+\])?==([^\s;]+)(?:\s*;.*)?", line)
            if match:
                add("pypi", re.sub(r"[-_.]+", "-", match[1]).lower(), match[2], source)
            else:
                unresolved.append(source)
    return {"bomFormat": "CycloneDX", "specVersion": "1.6", "version": 1,
            "metadata": {"component": {"type": "application", "name": "RentFleet", "version": commit},
                         "properties": [{"name": "rentfleet:scope", "value": "committed PHP/npm/Python lock entries; not resolved deployment graph, images or model weights"},
                                        {"name": "rentfleet:unresolved-lockfiles", "value": ",".join(sorted(set(unresolved)))}] + files},
            "components": sorted(components.values(), key=lambda item: item["purl"])}


if __name__ == "__main__":
    parser = argparse.ArgumentParser()
    parser.add_argument("--commit", required=True)
    parser.add_argument("--output", type=Path, required=True)
    args = parser.parse_args()
    result = inventory(ROOT, args.commit)
    args.output.write_text(json.dumps(result, ensure_ascii=False, indent=2) + "\n")
    print(f"SBOM: {len(result['components'])} locked components; scope recorded in metadata")
