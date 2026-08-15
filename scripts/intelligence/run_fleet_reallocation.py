#!/usr/bin/env python3
"""Execute one fresh, synthetic and consultative OR-Tools proposal for RentFleet.

The process accepts a closed JSON request on stdin and writes exactly one
contract-compatible proposal to stdout. It never receives a tenant, agency,
customer, vehicle identifier or coordinate and cannot perform an operational
write.
"""

from __future__ import annotations

import hashlib
import importlib.metadata
import importlib.util
import json
import sys
import uuid
from datetime import date, datetime, timedelta, timezone
from decimal import Decimal
from pathlib import Path
from typing import Any


ROOT = Path(__file__).resolve().parents[2]
QUALIFICATION_SCRIPT = ROOT / "scripts" / "intelligence" / "qualify_fleet_reallocation.py"

REQUEST_KEYS = {
    "schema_version",
    "proposal_id",
    "idempotency_key",
    "generated_at",
    "as_of_date",
    "forecast_horizon",
}
NODE_REFS = {
    "agency_alpha": "SYNTH-NODE-001",
    "agency_beta": "SYNTH-NODE-002",
    "agency_gamma": "SYNTH-NODE-003",
    "agency_delta": "SYNTH-NODE-004",
}
QUALIFICATION_COMMIT = "f71a80ac657c5ed58a8147e8535bdba60dddde0d"
EVIDENCE_COMMIT = "77479105049fa183f9e032e3207017b5348f6f1b"


class RuntimeContractError(ValueError):
    """Raised when the closed stdin contract is invalid."""


def load_qualification() -> Any:
    """Load the frozen solver only after dependency checks can fail closed."""
    specification = importlib.util.spec_from_file_location(
        "rentfleet_fleet_reallocation_runtime_qualification",
        QUALIFICATION_SCRIPT,
    )
    if specification is None or specification.loader is None:
        raise RuntimeError("SOLVER_INTERNAL_FAILURE")
    qualification = importlib.util.module_from_spec(specification)
    specification.loader.exec_module(qualification)
    return qualification


def canonical_json(value: Any, *, trailing_newline: bool = True) -> str:
    encoded = json.dumps(value, ensure_ascii=False, sort_keys=True, separators=(",", ":"))
    return encoded + ("\n" if trailing_newline else "")


def sha256_json(value: Any) -> str:
    return hashlib.sha256(canonical_json(value).encode("utf-8")).hexdigest()


def canonical_payload_digest(payload: dict[str, Any]) -> str:
    candidate = json.loads(canonical_json(payload))
    candidate["idempotency"].pop("canonical_payload_sha256", None)
    encoded = canonical_json(candidate, trailing_newline=False).encode("utf-8")
    return hashlib.sha256(encoded).hexdigest()


def parse_uuid(value: Any, field: str) -> str:
    if not isinstance(value, str):
        raise RuntimeContractError(f"{field} must be a lowercase UUID")
    try:
        parsed = uuid.UUID(value)
    except ValueError as error:
        raise RuntimeContractError(f"{field} must be a lowercase UUID") from error
    if str(parsed) != value:
        raise RuntimeContractError(f"{field} must be a lowercase UUID")
    return value


def parse_request(raw: str) -> dict[str, Any]:
    try:
        request = json.loads(raw)
    except json.JSONDecodeError as error:
        raise RuntimeContractError("stdin must contain valid UTF-8 JSON") from error
    if not isinstance(request, dict) or set(request) != REQUEST_KEYS:
        raise RuntimeContractError("stdin request keys are missing or unknown")
    if request["schema_version"] != "1.0.0":
        raise RuntimeContractError("schema_version must be 1.0.0")
    parse_uuid(request["proposal_id"], "proposal_id")
    parse_uuid(request["idempotency_key"], "idempotency_key")

    generated_at = request["generated_at"]
    if not isinstance(generated_at, str):
        raise RuntimeContractError("generated_at must be an RFC 3339 UTC timestamp")
    try:
        parsed_generated_at = datetime.strptime(generated_at, "%Y-%m-%dT%H:%M:%SZ").replace(
            tzinfo=timezone.utc
        )
    except ValueError as error:
        raise RuntimeContractError("generated_at must be an RFC 3339 UTC timestamp") from error
    if parsed_generated_at > datetime.now(timezone.utc) + timedelta(minutes=5):
        raise RuntimeContractError("generated_at cannot be in the future")

    as_of_date = request["as_of_date"]
    if not isinstance(as_of_date, str):
        raise RuntimeContractError("as_of_date must be an ISO date")
    try:
        date.fromisoformat(as_of_date)
    except ValueError as error:
        raise RuntimeContractError("as_of_date must be an ISO date") from error

    horizon = request["forecast_horizon"]
    if not isinstance(horizon, int) or isinstance(horizon, bool) or not 1 <= horizon <= 7:
        raise RuntimeContractError("forecast_horizon must be an integer from 1 to 7")
    return request


def build_forecast_reference(
    request: dict[str, Any],
    scenario: dict[str, Any],
    qualification: Any,
) -> dict[str, Any]:
    target_date = date.fromisoformat(request["as_of_date"]) + timedelta(
        days=request["forecast_horizon"]
    )
    return {
        "schema_version": "1.0.0",
        "forecast_status": "SYNTHETIC_INPUT_CONFORMING_TO_HGB_CONTRACT_NOT_MODEL_INFERENCE",
        "model_reference": {
            "name": "hgb_poisson::regularized",
            "version": "j5-v1",
            "local_holdout_status": "not_available_pending_real_history",
        },
        "planning": {
            "as_of_date": request["as_of_date"],
            "target_date": target_date.isoformat(),
            "forecast_horizon": request["forecast_horizon"],
            "nodes": [
                {
                    "node_ref": NODE_REFS[agency],
                    "available_vehicles": scenario["available_vehicles"][agency],
                    "forecast_demand": scenario["gross_demand_forecast"][agency],
                }
                for agency in qualification.AGENCIES
            ],
        },
    }


def build_proposal(
    request: dict[str, Any],
    scenario: dict[str, Any],
    optimized: dict[str, Any],
    runtime_ms: Decimal,
    qualification: Any,
) -> dict[str, Any]:
    target_date = date.fromisoformat(request["as_of_date"]) + timedelta(
        days=request["forecast_horizon"]
    )
    forecast_reference = build_forecast_reference(request, scenario, qualification)
    nodes = [
        {
            "node_ref": NODE_REFS[agency],
            "available_vehicles": scenario["available_vehicles"][agency],
            "forecast_demand": scenario["gross_demand_forecast"][agency],
            "effective_demand": scenario["effective_demand"][agency],
        }
        for agency in qualification.AGENCIES
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
        "proposal_id": request["proposal_id"],
        "generated_at": request["generated_at"],
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
            "as_of_date": request["as_of_date"],
            "target_date": target_date.isoformat(),
            "forecast_horizon": request["forecast_horizon"],
            "distance_unit": "km",
            "data_status": "SYNTHETIC_DEMO_NOT_RENTFLEET_HISTORY",
            "demand_source": {
                "model_name": "hgb_poisson::regularized",
                "model_version": "j5-v1",
                "forecast_reference_sha256": sha256_json(forecast_reference),
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
            "solver_runtime_ms": qualification.decimal_string(runtime_ms),
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
            "key": request["idempotency_key"],
            "policy": "SAME_KEY_SAME_PAYLOAD_ONLY",
            "canonical_payload_sha256": "0" * 64,
        },
    }
    proposal["idempotency"]["canonical_payload_sha256"] = canonical_payload_digest(proposal)
    return proposal


def execute(request: dict[str, Any]) -> dict[str, Any]:
    if sys.version_info[:2] != (3, 12):
        raise RuntimeError("PYTHON_VERSION_MISMATCH")
    try:
        installed_ortools = importlib.metadata.version("ortools")
    except importlib.metadata.PackageNotFoundError as error:
        raise RuntimeError("ORTOOLS_DEPENDENCY_MISSING") from error
    if installed_ortools != "9.15.6755":
        raise RuntimeError("ORTOOLS_VERSION_MISMATCH")

    qualification = load_qualification()
    scenario = qualification.build_scenarios()[request["forecast_horizon"] - 1]
    if scenario["horizon_day"] != request["forecast_horizon"]:
        raise RuntimeError("SCENARIO_HORIZON_MISMATCH")
    optimized, runtime_ms = qualification.solve_ortools(scenario)
    if optimized["status"] != "OPTIMAL" or not optimized["invariant_valid"]:
        raise RuntimeError("SOLVER_RESULT_INVALID")
    if runtime_ms > qualification.RUNTIME_GATE_MS:
        raise RuntimeError("SOLVER_RUNTIME_GATE_FAILED")
    if not optimized["relocations"]:
        raise RuntimeError("SOLVER_EMPTY_PROPOSAL")
    return build_proposal(request, scenario, optimized, runtime_ms, qualification)


def emit_error(error_code: str, exit_code: int) -> int:
    sys.stderr.write(canonical_json({"error_code": error_code}))
    return exit_code


def main() -> int:
    raw = sys.stdin.read(8193)
    if len(raw.encode("utf-8")) > 8192:
        return emit_error("RUNTIME_REQUEST_TOO_LARGE", 2)
    try:
        request = parse_request(raw)
        proposal = execute(request)
    except RuntimeContractError:
        return emit_error("RUNTIME_REQUEST_INVALID", 2)
    except RuntimeError as error:
        error_code = str(error)
        allowed = {
            "PYTHON_VERSION_MISMATCH",
            "ORTOOLS_DEPENDENCY_MISSING",
            "ORTOOLS_VERSION_MISMATCH",
            "SCENARIO_HORIZON_MISMATCH",
            "SOLVER_RESULT_INVALID",
            "SOLVER_RUNTIME_GATE_FAILED",
            "SOLVER_EMPTY_PROPOSAL",
        }
        return emit_error(error_code if error_code in allowed else "SOLVER_INTERNAL_FAILURE", 3)
    except Exception:
        return emit_error("SOLVER_INTERNAL_FAILURE", 3)

    sys.stdout.write(canonical_json(proposal))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
