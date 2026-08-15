#!/usr/bin/env python3
"""Qualify consultative fleet reallocation with a frozen synthetic benchmark."""

from __future__ import annotations

import argparse
import csv
import hashlib
import importlib.metadata
import json
import os
import platform
import random
import sys
import time
from decimal import Decimal, ROUND_HALF_UP
from pathlib import Path
from typing import Any, Iterable


PROTOCOL_VERSION = "1.0.0"
SEED = 20260814
SCENARIO_COUNT = 48
AGENCIES = ("agency_alpha", "agency_beta", "agency_gamma", "agency_delta")
DISTANCE_UNIT = "km"
CURRENCY = "MAD"
COST_SCALE = 100
RELOCATION_COST_MAD_PER_KM = Decimal("5.00")
UNSERVED_PENALTY_MAD = Decimal("10000.00")
UNSERVED_PENALTY_CENTIMES = int(UNSERVED_PENALTY_MAD * COST_SCALE)
PRESENCE_PROBABILITY = Decimal("1.000000")
PRESENCE_REASON = "CATBOOST_RESEARCH_GATE_NOT_PASSED_CONSERVATIVE_NO_DISCOUNT"
DATA_STATUS = "SYNTHETIC_NOT_RENTFLEET_NOT_LOCAL_ACCURACY"
LOCAL_STATUS = "NOT_VALIDATED_NO_REAL_HISTORY"
PASS_DECISION = "QUALIFIED_FOR_CONSULTATIVE_SAAS_INTEGRATION_REVIEW"
FAIL_DECISION = "RESEARCH_GATE_NOT_PASSED_NO_SAAS_INTEGRATION"
RUNTIME_GATE_MS = Decimal("5000.000")


def canonical_json(value: Any) -> str:
    return json.dumps(value, ensure_ascii=False, sort_keys=True, separators=(",", ":")) + "\n"


def sha256_bytes(content: bytes) -> str:
    return hashlib.sha256(content).hexdigest()


def sha256_file(path: Path) -> str:
    digest = hashlib.sha256()
    with path.open("rb") as handle:
        for chunk in iter(lambda: handle.read(1024 * 1024), b""):
            digest.update(chunk)
    return digest.hexdigest()


def decimal_string(value: Decimal, places: str = "0.000000") -> str:
    return format(value.quantize(Decimal(places), rounding=ROUND_HALF_UP), "f")


def distance_cost_centimes(distance_km: str) -> int:
    distance = Decimal(distance_km)
    if not distance.is_finite() or distance <= 0:
        raise ValueError("Every relocation distance must be a positive finite kilometre value")
    cost = distance * RELOCATION_COST_MAD_PER_KM * COST_SCALE
    return int(cost.quantize(Decimal("1"), rounding=ROUND_HALF_UP))


def build_scenarios() -> list[dict[str, Any]]:
    """Build the final suite; the committed seed and generator define the snapshot."""
    randomizer = random.Random(SEED)
    scenarios: list[dict[str, Any]] = []

    for index in range(SCENARIO_COUNT):
        transfer_units = 2 + (index % 4)
        unavoidable_shortage = 1 if index % 4 == 3 else 0
        local_demand = [1 + ((index + agency_index) % 3) for agency_index in range(4)]
        available = dict(zip(AGENCIES, local_demand, strict=True))
        gross_demand = dict(zip(AGENCIES, local_demand, strict=True))

        available["agency_alpha"] += transfer_units
        available["agency_beta"] += transfer_units
        gross_demand["agency_gamma"] += transfer_units
        gross_demand["agency_delta"] += transfer_units + unavoidable_shortage

        shortest = Decimal(18 + (index % 7) * 3) + Decimal(randomizer.randrange(0, 3))
        alternative = shortest + Decimal(8 + (index % 3))
        protected = alternative + Decimal(14 + (index % 5))
        lanes = [
            {
                "origin": "agency_alpha",
                "destination": "agency_gamma",
                "capacity": transfer_units,
                "distance_km": decimal_string(shortest, "0.000"),
            },
            {
                "origin": "agency_beta",
                "destination": "agency_gamma",
                "capacity": transfer_units,
                "distance_km": decimal_string(alternative, "0.000"),
            },
            {
                "origin": "agency_alpha",
                "destination": "agency_delta",
                "capacity": transfer_units,
                "distance_km": decimal_string(protected, "0.000"),
            },
        ]

        effective_demand = {
            agency: int(
                (Decimal(demand) * PRESENCE_PROBABILITY).to_integral_value(rounding=ROUND_HALF_UP)
            )
            for agency, demand in gross_demand.items()
        }
        scenarios.append(
            {
                "scenario_id": f"synthetic_reallocation_{index + 1:03d}",
                "horizon_day": 1 + (index % 7),
                "category_key": f"category_{1 + (index % 3):02d}",
                "distance_unit": DISTANCE_UNIT,
                "currency": CURRENCY,
                "data_status": DATA_STATUS,
                "presence_probability": decimal_string(PRESENCE_PROBABILITY),
                "presence_reason": PRESENCE_REASON,
                "available_vehicles": available,
                "gross_demand_forecast": gross_demand,
                "effective_demand": effective_demand,
                "lanes": lanes,
            }
        )

    validate_scenarios(scenarios)
    return scenarios


def validate_scenarios(scenarios: list[dict[str, Any]]) -> None:
    if len(scenarios) != SCENARIO_COUNT:
        raise ValueError(f"Expected {SCENARIO_COUNT} scenarios")
    if len({scenario["scenario_id"] for scenario in scenarios}) != SCENARIO_COUNT:
        raise ValueError("Scenario identifiers must be unique")

    for scenario in scenarios:
        if scenario["distance_unit"] != DISTANCE_UNIT:
            raise ValueError("Only kilometre distances are accepted")
        if Decimal(scenario["presence_probability"]) != PRESENCE_PROBABILITY:
            raise ValueError("The rejected CatBoost stage must use the conservative 1.0 fallback")
        if scenario["presence_reason"] != PRESENCE_REASON or scenario["data_status"] != DATA_STATUS:
            raise ValueError("Synthetic and abstention labels are mandatory")
        for field in ("available_vehicles", "gross_demand_forecast", "effective_demand"):
            values = scenario[field]
            if tuple(values) != AGENCIES:
                raise ValueError(f"{field} must use the frozen agency order")
            if any(not isinstance(value, int) or value < 0 for value in values.values()):
                raise ValueError(f"{field} must contain non-negative integers")
        if scenario["gross_demand_forecast"] != scenario["effective_demand"]:
            raise ValueError("The conservative fallback must not discount demand")

        seen_lanes: set[tuple[str, str]] = set()
        for lane in scenario["lanes"]:
            key = (lane["origin"], lane["destination"])
            if key in seen_lanes or key[0] == key[1]:
                raise ValueError("Relocation lanes must be unique and non-local")
            seen_lanes.add(key)
            if key[0] not in AGENCIES or key[1] not in AGENCIES:
                raise ValueError("Lane agencies must belong to the frozen scenario")
            if not isinstance(lane["capacity"], int) or lane["capacity"] <= 0:
                raise ValueError("Lane capacity must be a positive integer")
            distance_cost_centimes(lane["distance_km"])


def result_payload(
    method: str,
    total_demand: int,
    served: int,
    relocation_cost_centimes: int,
    relocations: list[dict[str, Any]],
    *,
    status: str = "BASELINE",
    invariant_valid: bool = True,
) -> dict[str, Any]:
    unserved = total_demand - served
    if total_demand < 0 or served < 0 or unserved < 0:
        raise ValueError("Invalid service totals")
    objective = relocation_cost_centimes + unserved * UNSERVED_PENALTY_CENTIMES
    service_rate = Decimal(served) / Decimal(total_demand) if total_demand else Decimal("1")
    return {
        "method": method,
        "status": status,
        "invariant_valid": invariant_valid,
        "total_demand": total_demand,
        "served_demand": served,
        "unserved_demand": unserved,
        "service_rate": decimal_string(service_rate),
        "relocation_cost_centimes": relocation_cost_centimes,
        "decision_cost_centimes": objective,
        "relocations": relocations,
    }


def no_relocation(scenario: dict[str, Any]) -> dict[str, Any]:
    served = sum(
        min(scenario["available_vehicles"][agency], scenario["effective_demand"][agency])
        for agency in AGENCIES
    )
    return result_payload(
        "no_relocation",
        sum(scenario["effective_demand"].values()),
        served,
        0,
        [],
    )


def greedy_relocation(scenario: dict[str, Any]) -> dict[str, Any]:
    remaining_supply = dict(scenario["available_vehicles"])
    remaining_demand = dict(scenario["effective_demand"])
    served = 0

    for agency in AGENCIES:
        local = min(remaining_supply[agency], remaining_demand[agency])
        remaining_supply[agency] -= local
        remaining_demand[agency] -= local
        served += local

    relocation_cost = 0
    relocations: list[dict[str, Any]] = []
    lanes = sorted(
        scenario["lanes"],
        key=lambda lane: (Decimal(lane["distance_km"]), lane["origin"], lane["destination"]),
    )
    for lane in lanes:
        origin = lane["origin"]
        destination = lane["destination"]
        flow = min(remaining_supply[origin], remaining_demand[destination], lane["capacity"])
        if flow <= 0:
            continue
        unit_cost = distance_cost_centimes(lane["distance_km"])
        remaining_supply[origin] -= flow
        remaining_demand[destination] -= flow
        served += flow
        relocation_cost += flow * unit_cost
        relocations.append(
            {
                "origin": origin,
                "destination": destination,
                "vehicles": flow,
                "distance_km": lane["distance_km"],
                "unit_cost_centimes": unit_cost,
            }
        )

    return result_payload(
        "greedy",
        sum(scenario["effective_demand"].values()),
        served,
        relocation_cost,
        relocations,
    )


def solve_ortools(scenario: dict[str, Any]) -> tuple[dict[str, Any], Decimal]:
    from ortools.graph.python import min_cost_flow

    solver = min_cost_flow.SimpleMinCostFlow()
    agency_count = len(AGENCIES)
    origin_node = {agency: index for index, agency in enumerate(AGENCIES)}
    destination_node = {agency: agency_count + index for index, agency in enumerate(AGENCIES)}
    shortage_node = agency_count * 2
    idle_node = shortage_node + 1
    arc_metadata: dict[int, dict[str, Any]] = {}

    def add_arc(
        tail: int,
        head: int,
        capacity: int,
        unit_cost: int,
        metadata: dict[str, Any],
    ) -> None:
        arc = solver.add_arc_with_capacity_and_unit_cost(tail, head, capacity, unit_cost)
        arc_metadata[arc] = metadata | {"capacity": capacity, "unit_cost_centimes": unit_cost}

    for agency in AGENCIES:
        add_arc(
            origin_node[agency],
            destination_node[agency],
            scenario["available_vehicles"][agency],
            0,
            {"kind": "local", "origin": agency, "destination": agency, "distance_km": "0.000"},
        )

    for lane in scenario["lanes"]:
        add_arc(
            origin_node[lane["origin"]],
            destination_node[lane["destination"]],
            lane["capacity"],
            distance_cost_centimes(lane["distance_km"]),
            {
                "kind": "relocation",
                "origin": lane["origin"],
                "destination": lane["destination"],
                "distance_km": lane["distance_km"],
            },
        )

    total_available = sum(scenario["available_vehicles"].values())
    total_demand = sum(scenario["effective_demand"].values())
    shortage_supply = max(0, total_demand - total_available)
    idle_demand = max(0, total_available - total_demand)

    for agency in AGENCIES:
        add_arc(
            shortage_node,
            destination_node[agency],
            scenario["effective_demand"][agency],
            UNSERVED_PENALTY_CENTIMES,
            {
                "kind": "unserved",
                "origin": "synthetic_shortage",
                "destination": agency,
                "distance_km": "0.000",
            },
        )
        add_arc(
            origin_node[agency],
            idle_node,
            scenario["available_vehicles"][agency],
            0,
            {"kind": "idle", "origin": agency, "destination": "idle", "distance_km": "0.000"},
        )

    for agency in AGENCIES:
        solver.set_node_supply(origin_node[agency], scenario["available_vehicles"][agency])
        solver.set_node_supply(destination_node[agency], -scenario["effective_demand"][agency])
    solver.set_node_supply(shortage_node, shortage_supply)
    solver.set_node_supply(idle_node, -idle_demand)

    started = time.perf_counter_ns()
    status = solver.solve()
    elapsed_ms = Decimal(time.perf_counter_ns() - started) / Decimal(1_000_000)
    status_name = getattr(status, "name", str(status))
    if status != solver.OPTIMAL:
        failed = result_payload(
            "ortools_min_cost_flow",
            total_demand,
            0,
            0,
            [],
            status=status_name,
            invariant_valid=False,
        )
        return failed, elapsed_ms

    origin_outflow = {agency: 0 for agency in AGENCIES}
    destination_inflow = {agency: 0 for agency in AGENCIES}
    relocation_cost = 0
    unserved = 0
    objective_recomputed = 0
    relocations: list[dict[str, Any]] = []
    capacity_valid = True

    for arc, metadata in arc_metadata.items():
        flow = solver.flow(arc)
        capacity_valid = capacity_valid and 0 <= flow <= metadata["capacity"]
        objective_recomputed += flow * metadata["unit_cost_centimes"]
        if metadata["kind"] in {"local", "relocation", "idle"}:
            origin_outflow[metadata["origin"]] += flow
        if metadata["kind"] in {"local", "relocation", "unserved"}:
            destination_inflow[metadata["destination"]] += flow
        if metadata["kind"] == "unserved":
            unserved += flow
        if metadata["kind"] == "relocation":
            relocation_cost += flow * metadata["unit_cost_centimes"]
            if flow > 0:
                relocations.append(
                    {
                        "origin": metadata["origin"],
                        "destination": metadata["destination"],
                        "vehicles": flow,
                        "distance_km": metadata["distance_km"],
                        "unit_cost_centimes": metadata["unit_cost_centimes"],
                    }
                )

    invariant_valid = all(
        (
            capacity_valid,
            origin_outflow == scenario["available_vehicles"],
            destination_inflow == scenario["effective_demand"],
            objective_recomputed == solver.optimal_cost(),
            unserved == shortage_supply,
        )
    )
    served = total_demand - unserved
    result = result_payload(
        "ortools_min_cost_flow",
        total_demand,
        served,
        relocation_cost,
        sorted(relocations, key=lambda row: (row["origin"], row["destination"])),
        status=status_name,
        invariant_valid=invariant_valid,
    )
    if result["decision_cost_centimes"] != objective_recomputed:
        result["invariant_valid"] = False
    return result, elapsed_ms


def aggregate_results(rows: list[dict[str, Any]]) -> dict[str, dict[str, Any]]:
    aggregates: dict[str, dict[str, Any]] = {}
    for method in ("no_relocation", "greedy", "ortools_min_cost_flow"):
        selected = [row for row in rows if row["method"] == method]
        total_demand = sum(row["total_demand"] for row in selected)
        served = sum(row["served_demand"] for row in selected)
        service_rate = Decimal(served) / Decimal(total_demand) if total_demand else Decimal("1")
        aggregates[method] = {
            "scenario_count": len(selected),
            "total_demand": total_demand,
            "served_demand": served,
            "unserved_demand": sum(row["unserved_demand"] for row in selected),
            "service_rate": decimal_string(service_rate),
            "relocation_cost_centimes": sum(row["relocation_cost_centimes"] for row in selected),
            "decision_cost_centimes": sum(row["decision_cost_centimes"] for row in selected),
        }
    return aggregates


def evaluate_gates(
    rows: list[dict[str, Any]],
    aggregates: dict[str, dict[str, Any]],
    runtimes_ms: list[Decimal],
) -> dict[str, Any]:
    ortools_rows = [row for row in rows if row["method"] == "ortools_min_cost_flow"]
    optimal_rate = Decimal(sum(row["status"] == "OPTIMAL" for row in ortools_rows)) / Decimal(
        len(ortools_rows)
    )
    invariant_rate = Decimal(sum(row["invariant_valid"] for row in ortools_rows)) / Decimal(
        len(ortools_rows)
    )
    service_rate = Decimal(aggregates["ortools_min_cost_flow"]["service_rate"])
    maximum_runtime = max(runtimes_ms, default=Decimal("0"))
    checks = {
        "optimal_solution_rate": optimal_rate == Decimal("1"),
        "invariant_valid_solution_rate": invariant_rate == Decimal("1"),
        "aggregate_service_rate": service_rate >= Decimal("0.80"),
        "unserved_better_than_no_relocation": (
            aggregates["ortools_min_cost_flow"]["unserved_demand"]
            < aggregates["no_relocation"]["unserved_demand"]
        ),
        "unserved_better_than_greedy": (
            aggregates["ortools_min_cost_flow"]["unserved_demand"]
            < aggregates["greedy"]["unserved_demand"]
        ),
        "decision_cost_better_than_no_relocation": (
            aggregates["ortools_min_cost_flow"]["decision_cost_centimes"]
            < aggregates["no_relocation"]["decision_cost_centimes"]
        ),
        "decision_cost_better_than_greedy": (
            aggregates["ortools_min_cost_flow"]["decision_cost_centimes"]
            < aggregates["greedy"]["decision_cost_centimes"]
        ),
        "runtime_under_5000_ms": maximum_runtime <= RUNTIME_GATE_MS,
    }
    return {
        "passed": all(checks.values()),
        "checks": checks,
        "observed": {
            "optimal_solution_rate": decimal_string(optimal_rate),
            "invariant_valid_solution_rate": decimal_string(invariant_rate),
            "aggregate_service_rate": decimal_string(service_rate),
            "maximum_single_solve_runtime_gate_passed": maximum_runtime <= RUNTIME_GATE_MS,
        },
    }


def installed_environment() -> dict[str, Any]:
    packages: dict[str, str] = {}
    for name in (
        "ortools",
        "absl-py",
        "immutabledict",
        "numpy",
        "pandas",
        "protobuf",
        "python-dateutil",
        "pytz",
        "six",
        "typing_extensions",
        "tzdata",
    ):
        try:
            packages[name] = importlib.metadata.version(name)
        except importlib.metadata.PackageNotFoundError:
            continue
    return {
        "python": platform.python_version(),
        "implementation": platform.python_implementation(),
        "platform": platform.platform(),
        "architecture": platform.machine(),
        "packages": packages,
        "seed": SEED,
        "pythonhashseed": os.environ.get("PYTHONHASHSEED", "not-set"),
    }


def write_csv(path: Path, fieldnames: list[str], rows: Iterable[dict[str, Any]]) -> None:
    with path.open("w", encoding="utf-8", newline="") as handle:
        writer = csv.DictWriter(handle, fieldnames=fieldnames, lineterminator="\n")
        writer.writeheader()
        writer.writerows(rows)


def write_comparison_svg(path: Path, aggregates: dict[str, dict[str, Any]]) -> None:
    methods = ("no_relocation", "greedy", "ortools_min_cost_flow")
    labels = ("No relocation", "Greedy", "OR-Tools")
    colors = ("#94a3b8", "#f59e0b", "#0f766e")
    max_unserved = max(aggregates[method]["unserved_demand"] for method in methods) or 1
    chart_width = 820
    bar_max = 470
    rows: list[str] = []
    for index, (method, label, color) in enumerate(zip(methods, labels, colors, strict=True)):
        y = 88 + index * 92
        unserved = aggregates[method]["unserved_demand"]
        width = int(Decimal(unserved) / Decimal(max_unserved) * bar_max)
        rate = Decimal(aggregates[method]["service_rate"]) * Decimal(100)
        rows.extend(
            [
                f'<text x="24" y="{y}" font-size="16" fill="#0f172a">{label}</text>',
                f'<rect x="190" y="{y - 22}" width="{width}" height="28" rx="4" fill="{color}"/>',
                f'<text x="{200 + width}" y="{y}" font-size="15" fill="#0f172a">{unserved} non servis · {decimal_string(rate, "0.00")}% servis</text>',
            ]
        )
    svg = (
        '<svg xmlns="http://www.w3.org/2000/svg" width="820" height="390" viewBox="0 0 820 390">'
        '<rect width="820" height="390" fill="#ffffff"/>'
        '<text x="24" y="38" font-size="22" font-weight="700" fill="#0f172a">'
        'Benchmark synthétique — demande non servie</text>'
        + "".join(rows)
        + '<text x="24" y="360" font-size="13" fill="#475569">Distances en km · données synthétiques · aucune performance RentFleet locale</text>'
        + "</svg>\n"
    )
    path.write_text(svg, encoding="utf-8")


def write_checksums(output: Path) -> None:
    artifacts = sorted(path for path in output.iterdir() if path.is_file() and path.name != "SHA256SUMS")
    content = "\n".join(f"{sha256_file(path)}  {path.name}" for path in artifacts) + "\n"
    (output / "SHA256SUMS").write_text(content, encoding="utf-8")


def run_qualification(output: Path) -> dict[str, Any]:
    output.mkdir(parents=True, exist_ok=True)
    scenarios = build_scenarios()
    benchmark_bytes = canonical_json(scenarios).encode("utf-8")
    benchmark_sha = sha256_bytes(benchmark_bytes)
    (output / "benchmark-scenarios.json").write_bytes(benchmark_bytes)

    rows: list[dict[str, Any]] = []
    runtimes: list[Decimal] = []
    for scenario in scenarios:
        for result in (no_relocation(scenario), greedy_relocation(scenario)):
            rows.append({"scenario_id": scenario["scenario_id"], **result})
        optimized, runtime_ms = solve_ortools(scenario)
        rows.append({"scenario_id": scenario["scenario_id"], **optimized})
        runtimes.append(runtime_ms)

    aggregates = aggregate_results(rows)
    gates = evaluate_gates(rows, aggregates, runtimes)
    decision = PASS_DECISION if gates["passed"] else FAIL_DECISION
    deterministic_rows = [
        {
            "scenario_id": row["scenario_id"],
            "method": row["method"],
            "status": row["status"],
            "invariant_valid": row["invariant_valid"],
            "total_demand": row["total_demand"],
            "served_demand": row["served_demand"],
            "unserved_demand": row["unserved_demand"],
            "service_rate": row["service_rate"],
            "relocation_cost_centimes": row["relocation_cost_centimes"],
            "decision_cost_centimes": row["decision_cost_centimes"],
        }
        for row in rows
    ]
    write_csv(
        output / "scenario-results.csv",
        list(deterministic_rows[0]),
        deterministic_rows,
    )
    write_csv(
        output / "method-summary.csv",
        [
            "method",
            "scenario_count",
            "total_demand",
            "served_demand",
            "unserved_demand",
            "service_rate",
            "relocation_cost_centimes",
            "decision_cost_centimes",
        ],
        [{"method": method, **aggregates[method]} for method in aggregates],
    )
    sample = next(
        row
        for row in rows
        if row["method"] == "ortools_min_cost_flow" and row["relocations"]
    )
    write_csv(
        output / "sample-human-review-plan.csv",
        ["scenario_id", "origin", "destination", "vehicles", "distance_km", "unit_cost_centimes"],
        [
            {"scenario_id": sample["scenario_id"], **relocation}
            for relocation in sample["relocations"]
        ],
    )
    runtime_observation = {
        "target_maximum_single_solve_ms": decimal_string(RUNTIME_GATE_MS, "0.000"),
        "observed_maximum_single_solve_ms": decimal_string(max(runtimes), "0.000000"),
        "observed_median_single_solve_ms": decimal_string(
            sorted(runtimes)[len(runtimes) // 2], "0.000000"
        ),
        "scenario_count": len(runtimes),
        "gate_passed": max(runtimes) <= RUNTIME_GATE_MS,
        "warning": "Machine-dependent observation; excluded from bit-for-bit result comparison.",
    }
    (output / "runtime-observation.json").write_text(
        canonical_json(runtime_observation), encoding="utf-8"
    )
    environment = installed_environment()
    (output / "environment.json").write_text(canonical_json(environment), encoding="utf-8")
    manifest = {
        "protocol_version": PROTOCOL_VERSION,
        "decision": decision,
        "gate_passed": gates["passed"],
        "benchmark": {
            "kind": "DETERMINISTIC_SYNTHETIC_STRESS_SUITE",
            "scenario_count": len(scenarios),
            "seed": SEED,
            "snapshot_sha256": benchmark_sha,
            "distance_unit": DISTANCE_UNIT,
            "currency": CURRENCY,
            "presence_probability": decimal_string(PRESENCE_PROBABILITY),
            "presence_reason": PRESENCE_REASON,
            "data_status": DATA_STATUS,
        },
        "solver": {
            "name": "Google OR-Tools SimpleMinCostFlow",
            "version": environment["packages"].get("ortools", "unknown"),
            "required_status": "OPTIMAL",
            "cost_integrality": "centimes",
        },
        "aggregates": aggregates,
        "gates": gates,
        "safety": {
            "saas_integration_allowed": gates["passed"],
            "automatic_business_write_allowed": False,
            "human_validation_required": True,
            "local_rentfleet_status": LOCAL_STATUS,
            "catboost_output_consumed": False,
        },
    }
    (output / "qualification-manifest.json").write_text(canonical_json(manifest), encoding="utf-8")
    write_comparison_svg(output / "benchmark-comparison.svg", aggregates)
    write_checksums(output)
    return manifest


def parse_args(argv: Iterable[str] | None = None) -> argparse.Namespace:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--output", type=Path, required=True)
    parser.add_argument("--require-gate", action="store_true")
    return parser.parse_args(argv)


def main(argv: Iterable[str] | None = None) -> int:
    args = parse_args(argv)
    os.environ.setdefault("PYTHONHASHSEED", str(SEED))
    manifest = run_qualification(args.output)
    print(
        canonical_json(
            {
                "decision": manifest["decision"],
                "gate_passed": manifest["gate_passed"],
                "aggregates": manifest["aggregates"],
            }
        ),
        end="",
    )
    return 1 if args.require_gate and not manifest["gate_passed"] else 0


if __name__ == "__main__":
    raise SystemExit(main())
