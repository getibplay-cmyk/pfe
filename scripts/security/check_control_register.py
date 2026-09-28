"""Check traceability completeness without turning documentation into a compliance score."""
from __future__ import annotations

import argparse
import hashlib
import json
import re
from collections import Counter
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
REGISTER = ROOT / "docs/security/reference-hardening/control-register.json"


def check(data: dict, attachments: Path | None = None) -> dict:
    sources = {item["id"]: item for item in data["sources"]}
    controls = {item["id"]: item for item in data["controls"]}
    assert len(sources) == 4
    assert len(controls) == len(data["controls"]) == 236
    assert len(data["source_sections"]) == 332
    assert len({item["id"] for item in data["source_sections"]}) == 332
    assert len(data["verification_scenarios"]) == 50
    assert {item["id"] for item in data["verification_scenarios"]} == {f"T-{n:02}" for n in range(1, 51)}
    states = {"CONFORME", "PARTIEL", "NON_CONFORME", "NON_VERIFIE", "NON_APPLICABLE_JUSTIFIE"}
    for source in sources.values():
        assert source["read_in_full"] is True
        assert re.fullmatch(r"[a-f0-9]{64}", source["sha256"])
        if attachments:
            assert hashlib.sha256((attachments / source["file"]).read_bytes()).hexdigest() == source["sha256"]
    for section in data["source_sections"]:
        assert section["source"] in sources
        assert 1 <= section["from_line"] <= section["to_line"] <= sources[section["source"]]["lines"]
        assert section["disposition"] in {"RATTACHE_AUX_CONTROLES", "SOURCE_DOCUMENTAIRE", "CADRAGE_ET_METHODE", "ADAPTE_A_L_ARCHITECTURE"}
        assert section["control_ids"] and set(section["control_ids"]) <= controls.keys()
        assert section["decision_note"]
    referenced = {identifier for section in data["source_sections"] for identifier in section["control_ids"]}
    assert referenced == controls.keys(), "Every control must remain attached to a source section"
    for control in controls.values():
        assert control["status"] in states
        for field in ["requirement", "scope", "responsible_role", "initial_observation", "residual_risk", "next_action", "evidence"]:
            assert control[field], (control["id"], field)
        for path in control["implementation"] + control["tests"]:
            assert (ROOT / path).exists(), (control["id"], path)
        if control["status"] == "CONFORME":
            assert any(item.get("kind") == "test_run" and item.get("result") == "PASS"
                       and re.fullmatch(r"[a-f0-9]{40}", item.get("commit", "")) for item in control["evidence"]), control["id"]
        acceptance = control["risk_acceptance"]
        if acceptance["approved"]:
            assert acceptance["decider"] and acceptance["expires"]
    for scenario in data["verification_scenarios"]:
        assert set(scenario["control_ids"]) <= controls.keys()
        assert scenario["remaining"] and scenario["proof_required"]
    return {"sources": len(sources), "sections": 332, "controls": 236, "scenarios": 50,
            "states": dict(Counter(item["status"] for item in controls.values())),
            "meaning": "traceability completeness, not security certification"}


if __name__ == "__main__":
    parser = argparse.ArgumentParser()
    parser.add_argument("--attachments", type=Path)
    args = parser.parse_args()
    print(json.dumps(check(json.loads(REGISTER.read_text()), args.attachments), ensure_ascii=False))
