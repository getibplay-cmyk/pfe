#!/usr/bin/env python3
"""Build the deterministic synthetic S6 consultative demonstration bundle."""

from __future__ import annotations

import argparse
import hashlib
import importlib.util
import json
from pathlib import Path
from typing import Any


ROOT = Path(__file__).resolve().parents[2]
QUALIFICATION_SCRIPT = ROOT / "scripts" / "intelligence" / "qualify_fleet_reallocation.py"
QUALIFICATION_SPEC = importlib.util.spec_from_file_location(
    "rentfleet_fleet_reallocation_qualification",
    QUALIFICATION_SCRIPT,
)
if QUALIFICATION_SPEC is None or QUALIFICATION_SPEC.loader is None:
    raise RuntimeError("Unable to load the frozen fleet reallocation qualification module")
QUALIFICATION = importlib.util.module_from_spec(QUALIFICATION_SPEC)
QUALIFICATION_SPEC.loader.exec_module(QUALIFICATION)

DEMO_VERSION = "1.0.0"
DEMO_ID = "rentfleet-s6-consultative-demo-001"
PROPOSAL_ID = "00000000-0000-4000-8000-000000000001"
IDEMPOTENCY_KEY = "00000000-0000-4000-8000-000000000002"
GENERATED_AT = "2026-08-15T00:00:00Z"
AS_OF_DATE = "2026-08-15"
TARGET_DATE = "2026-08-16"
FORECAST_HEAD = "d5355bd475d76a4377f95089b2402e5f8cf071f1"
QUALIFICATION_COMMIT = "f71a80ac657c5ed58a8147e8535bdba60dddde0d"
EVIDENCE_COMMIT = "77479105049fa183f9e032e3207017b5348f6f1b"
INTEGRATION_COMMIT = "330637445d81185975366208982bbfa92048dffc"
FROZEN_QUALIFICATION_RUNTIME_MS = "0.032959"
NODE_REFS = {
    "agency_alpha": "SYNTH-NODE-001",
    "agency_beta": "SYNTH-NODE-002",
    "agency_gamma": "SYNTH-NODE-003",
    "agency_delta": "SYNTH-NODE-004",
}


def canonical_json(value: Any, *, trailing_newline: bool = True) -> str:
    encoded = json.dumps(value, ensure_ascii=False, sort_keys=True, separators=(",", ":"))
    return encoded + ("\n" if trailing_newline else "")


def sha256_bytes(value: bytes) -> str:
    return hashlib.sha256(value).hexdigest()


def sha256_file(path: Path) -> str:
    return sha256_bytes(path.read_bytes())


def build_forecast(scenario: dict[str, Any]) -> dict[str, Any]:
    return {
        "schema_version": "1.0.0",
        "demo_id": DEMO_ID,
        "data_status": "SYNTHETIC_DEMO_NOT_RENTFLEET_HISTORY",
        "forecast_status": "SYNTHETIC_INPUT_CONFORMING_TO_HGB_CONTRACT_NOT_MODEL_INFERENCE",
        "model_reference": {
            "name": "hgb_poisson::regularized",
            "version": "j5-v1",
            "pull_request": 9,
            "head_commit": FORECAST_HEAD,
            "horizon": "D+1_TO_D+7",
            "local_holdout_status": "not_available_pending_real_history",
        },
        "planning": {
            "as_of_date": AS_OF_DATE,
            "target_date": TARGET_DATE,
            "forecast_horizon": 1,
            "nodes": [
                {
                    "node_ref": NODE_REFS[source_ref],
                    "available_vehicles": scenario["available_vehicles"][source_ref],
                    "forecast_demand": scenario["gross_demand_forecast"][source_ref],
                }
                for source_ref in QUALIFICATION.AGENCIES
            ],
        },
        "safety": {
            "synthetic_demo": True,
            "contains_real_customer_data": False,
            "contains_direct_identifiers": False,
            "contains_coordinates": False,
            "local_accuracy_claimed": False,
            "automatic_action_allowed": False,
        },
    }


def canonical_payload_digest(payload: dict[str, Any]) -> str:
    candidate = json.loads(canonical_json(payload))
    candidate["idempotency"].pop("canonical_payload_sha256", None)
    return sha256_bytes(canonical_json(candidate, trailing_newline=False).encode("utf-8"))


def build_proposal(
    scenario: dict[str, Any],
    optimized: dict[str, Any],
    forecast_sha256: str,
) -> dict[str, Any]:
    nodes = [
        {
            "node_ref": NODE_REFS[source_ref],
            "available_vehicles": scenario["available_vehicles"][source_ref],
            "forecast_demand": scenario["gross_demand_forecast"][source_ref],
            "effective_demand": scenario["effective_demand"][source_ref],
        }
        for source_ref in QUALIFICATION.AGENCIES
    ]
    moves = [
        {
            "from_node_ref": NODE_REFS[move["origin"]],
            "to_node_ref": NODE_REFS[move["destination"]],
            "vehicles": move["vehicles"],
            "distance_km": move["distance_km"],
            "unit_cost_centimes": move["unit_cost_centimes"],
            "total_cost_centimes": move["vehicles"] * move["unit_cost_centimes"],
            "reason_code": "EFFECTIVE_DEMAND_IMBALANCE",
            "operational_effect": "NO_OPERATIONAL_ACTION",
        }
        for move in optimized["relocations"]
    ]
    proposal = {
        "schema_version": "1.0.0",
        "proposal_id": PROPOSAL_ID,
        "generated_at": GENERATED_AT,
        "source": {
            "kind": "synthetic_demo",
            "solver_name": "ortools_simple_min_cost_flow",
            "solver_version": "9.15.6755",
            "solver_status": "OPTIMAL",
            "qualification_decision": "QUALIFIED_FOR_CONSULTATIVE_SAAS_INTEGRATION_REVIEW",
            "qualification_commit": QUALIFICATION_COMMIT,
            "evidence_commit": EVIDENCE_COMMIT,
        },
        "planning": {
            "as_of_date": AS_OF_DATE,
            "target_date": TARGET_DATE,
            "forecast_horizon": 1,
            "distance_unit": "km",
            "data_status": "SYNTHETIC_DEMO_NOT_RENTFLEET_HISTORY",
            "demand_source": {
                "model_name": "hgb_poisson::regularized",
                "model_version": "j5-v1",
                "forecast_reference_sha256": forecast_sha256,
                "local_holdout_status": "not_available_pending_real_history",
                "synthetic_demo": True,
            },
            "cancellation_risk": {
                "model_name": "cancellation_risk_catboost",
                "gate_decision": "RESEARCH_GATE_NOT_PASSED_NO_SAAS_INTEGRATION",
                "presence_probability": "1.000000",
                "presence_reason": "CATBOOST_RESEARCH_GATE_NOT_PASSED_CONSERVATIVE_NO_DISCOUNT",
                "demand_adjustment": "ABSTENTION_NO_DEMAND_REDUCTION",
            },
            "nodes": nodes,
            "moves": moves,
        },
        "summary": {
            "node_count": len(nodes),
            "move_line_count": len(moves),
            "relocated_vehicle_count": sum(move["vehicles"] for move in moves),
            "total_demand": optimized["total_demand"],
            "served_demand": optimized["served_demand"],
            "unserved_demand": optimized["unserved_demand"],
            "service_rate": optimized["service_rate"],
            "relocation_cost_centimes": optimized["relocation_cost_centimes"],
            "decision_cost_centimes": optimized["decision_cost_centimes"],
            "solver_runtime_ms": FROZEN_QUALIFICATION_RUNTIME_MS,
        },
        "safety": {
            "synthetic_demo": True,
            "contains_real_customer_data": False,
            "contains_direct_identifiers": False,
            "contains_coordinates": False,
            "human_decision_required": True,
            "automatic_action_allowed": False,
            "operational_table_write_allowed": False,
            "local_validation_status": "NOT_VALIDATED_NO_REAL_HISTORY",
            "operational_effect": "NO_OPERATIONAL_ACTION",
        },
        "idempotency": {
            "key": IDEMPOTENCY_KEY,
            "policy": "SAME_KEY_SAME_PAYLOAD_ONLY",
            "canonical_payload_sha256": "0" * 64,
        },
    }
    proposal["idempotency"]["canonical_payload_sha256"] = canonical_payload_digest(proposal)
    return proposal


def build_trace(
    forecast_sha256: str,
    proposal_sha256: str,
    no_relocation: dict[str, Any],
    greedy: dict[str, Any],
    optimized: dict[str, Any],
) -> dict[str, Any]:
    return {
        "schema_version": DEMO_VERSION,
        "demo_id": DEMO_ID,
        "ordered_stages": [
            {
                "position": 1,
                "stage": "demand_forecast",
                "status": "SYNTHETIC_HGB_CONTRACT_INPUT_NOT_MODEL_INFERENCE",
                "artifact": "synthetic-hgb-forecast.json",
                "sha256": forecast_sha256,
            },
            {
                "position": 2,
                "stage": "cancellation_risk",
                "status": "CATBOOST_REJECTED_ABSTENTION_APPLIED",
                "presence_probability": "1.000000",
                "effective_demand_rule": "effective_demand_equals_forecast_demand",
            },
            {
                "position": 3,
                "stage": "fleet_reallocation",
                "status": optimized["status"],
                "method": "ortools_simple_min_cost_flow",
                "distance_unit": "km",
                "proposal": "fleet-reallocation-proposal.json",
                "sha256": proposal_sha256,
            },
            {
                "position": 4,
                "stage": "saas_import",
                "status": "READY_FOR_PRIVATE_TENANT_SCOPED_IMPORT",
                "integration_commit": INTEGRATION_COMMIT,
                "operational_effect": "NO_OPERATIONAL_ACTION",
            },
            {
                "position": 5,
                "stage": "human_validation",
                "status": "EXPLICIT_ACCEPT_OR_REJECT_REQUIRED",
                "demonstrated_by": "tests/Feature/FleetReallocationConsultativeIntegrationTest.php",
                "effect_after_decision": "NO_OPERATIONAL_ACTION",
            },
        ],
        "comparison": {
            "no_relocation": {
                "unserved_demand": no_relocation["unserved_demand"],
                "decision_cost_centimes": no_relocation["decision_cost_centimes"],
            },
            "greedy": {
                "unserved_demand": greedy["unserved_demand"],
                "decision_cost_centimes": greedy["decision_cost_centimes"],
            },
            "ortools_min_cost_flow": {
                "unserved_demand": optimized["unserved_demand"],
                "decision_cost_centimes": optimized["decision_cost_centimes"],
                "service_rate": optimized["service_rate"],
            },
        },
        "runtime_measurement": {
            "proposal_value_ms": FROZEN_QUALIFICATION_RUNTIME_MS,
            "source": "FROZEN_MAXIMUM_FROM_QUALIFIED_48_SCENARIO_BENCHMARK",
            "live_reproduction_rule": "FAIL_IF_CURRENT_SOLVE_EXCEEDS_5000_MS",
            "reason": "Wall-clock duration is excluded from deterministic artifact bytes.",
        },
        "limits": [
            "All planning data and node references are synthetic.",
            "The HGB stage is a frozen contract-compatible synthetic input, not a model inference run.",
            "The rejected CatBoost model is not loaded and cannot reduce demand.",
            "The public-method evidence is not a RentFleet local-accuracy claim.",
            "A real authorized user must still accept or reject the proposal in the SaaS.",
        ],
    }


def readme() -> str:
    return """# Démonstration consultative S6 — données synthétiques

Ce paquet exécutable hors ligne relie, dans cet ordre, une entrée de prévision
synthétique conforme au contrat HGB D+1, l'abstention du CatBoost refusé, la
demande effective inchangée, une résolution OR-Tools Min-Cost Flow et l'import
privé soumis à une décision humaine explicite.

La prévision n'est pas présentée comme une inférence HGB réellement exécutée :
elle est un scénario synthétique gelé qui référence la PR #9. CatBoost n'est ni
chargé ni intégré. Toutes les distances sont en kilomètres. Les références de
nœuds sont synthétiques et le paquet ne contient ni tenant, ni agence réelle,
ni identité, ni coordonnée.

## Reproduction

Depuis l'environnement Python figé OR-Tools :

```bash
python scripts/intelligence/build_consultative_demo.py \\
  --output docs/evidence/intelligence/consultative-demo
sha256sum --check docs/evidence/intelligence/consultative-demo/SHA256SUMS
```

Importer ensuite `fleet-reallocation-proposal.json` dans l'écran
« Propositions de réallocation OR-Tools ». Un Tenant Owner autorisé doit choisir
« accepter pour la démo » ou « rejeter ». Dans les deux cas, l'effet enregistré
reste `NO_OPERATIONAL_ACTION` : aucune réservation, aucun contrat, tarif,
facture, paiement, véhicule, bloc ou maintenance n'est modifié.

Le test fonctionnel PostgreSQL reproduit l'import, la décision append-only et
l'absence d'écriture dans les tables opérationnelles. Il ne remplace pas une
signature humaine réelle et ne valide aucune performance locale RentFleet.
La valeur de temps contenue dans la proposition est le maximum gelé de la
qualification à 48 scénarios ; chaque reproduction mesure aussi son solveur et
échoue si l'appel courant dépasse 5 secondes, sans intégrer ce temps variable
dans les octets déterministes.

## Sources techniques primaires vérifiées

- scikit-learn 1.6.1, `HistGradientBoostingRegressor` et perte Poisson :
  https://scikit-learn.org/1.6/modules/generated/sklearn.ensemble.HistGradientBoostingRegressor.html
- CatBoost, contrat officiel `predict_proba` (non appelé dans cette démo) :
  https://catboost.ai/docs/en/concepts/python-reference_catboostclassifier_predict_proba
- Google OR-Tools, Minimum Cost Flow :
  https://developers.google.com/optimization/flow/mincostflow
- NIST AI RMF 1.0 :
  https://www.nist.gov/publications/artificial-intelligence-risk-management-framework-ai-rmf-10
"""


def write_checksums(output: Path) -> None:
    files = sorted(path for path in output.iterdir() if path.is_file() and path.name != "SHA256SUMS")
    (output / "SHA256SUMS").write_text(
        "\n".join(f"{sha256_file(path)}  {path.name}" for path in files) + "\n",
        encoding="utf-8",
    )


def build_bundle(output: Path) -> dict[str, Any]:
    output.mkdir(parents=True, exist_ok=True)
    scenario = QUALIFICATION.build_scenarios()[0]
    forecast = build_forecast(scenario)
    forecast_bytes = canonical_json(forecast).encode("utf-8")
    forecast_sha256 = sha256_bytes(forecast_bytes)

    no_relocation = QUALIFICATION.no_relocation(scenario)
    greedy = QUALIFICATION.greedy_relocation(scenario)
    optimized, runtime_ms = QUALIFICATION.solve_ortools(scenario)
    if optimized["status"] != "OPTIMAL" or not optimized["invariant_valid"]:
        raise RuntimeError("The frozen synthetic demo scenario is not an invariant-valid OPTIMAL solution")
    if runtime_ms > QUALIFICATION.RUNTIME_GATE_MS:
        raise RuntimeError("The live demo solve exceeded the preregistered five-second gate")
    if optimized["unserved_demand"] >= no_relocation["unserved_demand"]:
        raise RuntimeError("OR-Tools must improve unserved demand over no relocation")
    if optimized["unserved_demand"] >= greedy["unserved_demand"]:
        raise RuntimeError("OR-Tools must improve unserved demand over greedy")
    if optimized["decision_cost_centimes"] >= no_relocation["decision_cost_centimes"]:
        raise RuntimeError("OR-Tools must improve decision cost over no relocation")
    if optimized["decision_cost_centimes"] >= greedy["decision_cost_centimes"]:
        raise RuntimeError("OR-Tools must improve decision cost over greedy")

    proposal = build_proposal(scenario, optimized, forecast_sha256)
    proposal_bytes = canonical_json(proposal).encode("utf-8")
    proposal_sha256 = sha256_bytes(proposal_bytes)
    trace = build_trace(forecast_sha256, proposal_sha256, no_relocation, greedy, optimized)

    (output / "synthetic-hgb-forecast.json").write_bytes(forecast_bytes)
    (output / "fleet-reallocation-proposal.json").write_bytes(proposal_bytes)
    (output / "pipeline-trace.json").write_text(canonical_json(trace), encoding="utf-8")
    (output / "README.md").write_text(readme(), encoding="utf-8")
    write_checksums(output)
    return trace


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--output", type=Path, required=True)
    args = parser.parse_args()
    trace = build_bundle(args.output)
    print(canonical_json(trace), end="")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
