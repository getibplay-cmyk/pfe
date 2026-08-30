<?php

namespace App\Actions\Fleet;

use App\Enums\FleetReallocationPlanningRunStatus;
use App\Exceptions\FleetReallocationPlanningException;
use App\Models\FleetReallocationPlanningRun;
use App\Models\FleetReallocationRecommendation;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use App\Support\Fleet\OperationalFleetReallocationOutputValidator;
use App\Support\Intelligence\FleetReallocation\FleetReallocationContract;
use App\Support\Tenancy\TenantContext;
use Illuminate\Process\Exceptions\ProcessTimedOutException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use JsonException;
use Throwable;

class ExecuteOperationalFleetReallocationPlan
{
    private const PROCESS_FAILURE_CODES = [
        'RUNTIME_REQUEST_TOO_LARGE',
        'OPERATIONAL_REQUEST_INVALID',
        'PYTHON_VERSION_MISMATCH',
        'ORTOOLS_DEPENDENCY_MISSING',
        'ORTOOLS_VERSION_MISMATCH',
        'SOLVER_RESULT_INVALID',
        'SOLVER_RUNTIME_GATE_FAILED',
        'SOLVER_INTERNAL_FAILURE',
    ];

    public function __construct(
        private readonly TenantContext $context,
        private readonly OperationalFleetReallocationOutputValidator $validator,
        private readonly AuditRecorder $audit,
    ) {}

    public function handle(string $runId, int $tenantId, int $actorId): void
    {
        $this->context->run($tenantId, function () use ($runId, $tenantId, $actorId): void {
            $run = $this->markRunning($runId, $actorId);
            $actor = User::query()
                ->with('role.permissions')
                ->whereKey($actorId)
                ->where('tenant_id', $tenantId)
                ->where('is_active', true)
                ->first();
            if ($actor === null
                || ! in_array($actor->role?->slug, ['tenant-owner', 'fleet-manager'], true)
                || ! $actor->hasPermission('prediction.demo.review')) {
                throw new FleetReallocationPlanningException('RUN_ACTOR_NOT_AUTHORIZED');
            }

            $requestJson = $this->requestJson($run);
            $output = $this->executeProcess($requestJson);
            $validated = $this->validator->validate($output, $run->snapshot, $run->run_id);

            $completed = DB::transaction(function () use ($run, $validated): FleetReallocationPlanningRun {
                $locked = FleetReallocationPlanningRun::query()
                    ->where('run_id', $run->run_id)
                    ->lockForUpdate()
                    ->firstOrFail();
                if ($locked->status !== FleetReallocationPlanningRunStatus::Running) {
                    throw new FleetReallocationPlanningException('RUN_STATE_CONFLICT');
                }

                foreach ($validated['recommendations'] as $recommendation) {
                    FleetReallocationRecommendation::create([
                        'fleet_reallocation_planning_run_id' => $locked->getKey(),
                        ...$recommendation,
                        'created_at' => now(),
                    ]);
                }

                $locked->forceFill([
                    'status' => FleetReallocationPlanningRunStatus::Succeeded,
                    'outcome' => $validated['outcome'],
                    'solver_status' => FleetReallocationContract::SOLVER_STATUS,
                    'runtime_result' => $validated['payload'],
                    'finished_at' => now(),
                ])->save();

                return $locked;
            }, 3);

            $this->audit->record('fleet.reallocation_planning.run_succeeded', $completed, [], [
                'run_id' => $completed->run_id,
                'source_kind' => $completed->source_kind,
                'outcome' => $completed->outcome,
                'solver_status' => $completed->solver_status,
                'recommendation_count' => count($validated['recommendations']),
                'effect' => FleetReallocationContract::OPERATIONAL_EFFECT,
            ]);
        });
    }

    private function markRunning(string $runId, int $actorId): FleetReallocationPlanningRun
    {
        return DB::transaction(function () use ($runId, $actorId): FleetReallocationPlanningRun {
            $run = FleetReallocationPlanningRun::query()
                ->where('run_id', $runId)
                ->lockForUpdate()
                ->firstOrFail();
            if ($run->requested_by !== $actorId
                || $run->status !== FleetReallocationPlanningRunStatus::Queued) {
                throw new FleetReallocationPlanningException('RUN_STATE_CONFLICT');
            }
            $run->forceFill([
                'status' => FleetReallocationPlanningRunStatus::Running,
                'started_at' => now(),
            ])->save();

            return $run;
        }, 3);
    }

    private function requestJson(FleetReallocationPlanningRun $run): string
    {
        $snapshot = $run->snapshot;
        $days = [];
        foreach ($snapshot['days'] as $day) {
            $surplusByNode = [];
            $nodes = [];
            foreach ($day['nodes'] as $node) {
                $surplusByNode[$node['node_ref']] = $node['transferable_surplus'];
                $nodes[] = [
                    'node_ref' => $node['node_ref'],
                    'available_vehicle_units' => $node['available_vehicle_units'],
                    'planning_vehicle_units' => $node['planning_vehicle_units'],
                    'transferable_surplus' => $node['transferable_surplus'],
                    'uncovered_need' => $node['uncovered_need'],
                ];
            }
            $lanes = [];
            foreach ($snapshot['lanes'] as $lane) {
                $lanes[] = [
                    'from_node_ref' => $lane['from_node_ref'],
                    'to_node_ref' => $lane['to_node_ref'],
                    'capacity' => $surplusByNode[$lane['from_node_ref']],
                    'distance_km' => $lane['distance_km'],
                    'unit_cost_centimes' => $lane['unit_cost_centimes'],
                ];
            }
            $days[] = [
                'horizon' => $day['horizon'],
                'date' => $day['date'],
                'nodes' => $nodes,
                'lanes' => $lanes,
            ];
        }

        try {
            return json_encode([
                'schema_version' => '1.0.0',
                'source_kind' => 'rentfleet_operational',
                'run_id' => $run->run_id,
                'generated_at' => $run->requested_at->utc()->format('Y-m-d\TH:i:s\Z'),
                'reference_date' => $run->reference_date->toDateString(),
                'days' => $days,
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        } catch (JsonException) {
            throw new FleetReallocationPlanningException('RUNTIME_REQUEST_ENCODING_FAILED');
        }
    }

    private function executeProcess(string $requestJson): string
    {
        $binary = (string) config('intelligence.fleet_reallocation.python_binary');
        $script = (string) config('intelligence.fleet_reallocation.runtime_script');
        $timeout = (int) config('intelligence.fleet_reallocation.runtime_timeout_seconds');
        if ($binary === '' || $script === '' || ! is_file($binary) || ! is_file($script)
            || $timeout < 1 || $timeout > 120) {
            throw new FleetReallocationPlanningException('RUNTIME_CONFIGURATION_INVALID');
        }

        try {
            $result = Process::path(base_path())
                ->timeout($timeout)
                ->idleTimeout(min(10, $timeout))
                ->env([
                    'PYTHONDONTWRITEBYTECODE' => '1',
                    'PYTHONHASHSEED' => '20260814',
                ])
                ->input($requestJson)
                ->run([$binary, $script]);
        } catch (ProcessTimedOutException) {
            throw new FleetReallocationPlanningException('SOLVER_PROCESS_TIMEOUT');
        } catch (Throwable) {
            throw new FleetReallocationPlanningException('SOLVER_PROCESS_FAILED');
        }

        if (! $result->successful()) {
            $code = null;
            try {
                $error = json_decode($result->errorOutput(), true, 8, JSON_THROW_ON_ERROR);
                $candidate = is_array($error) ? ($error['error_code'] ?? null) : null;
                if (is_string($candidate) && in_array($candidate, self::PROCESS_FAILURE_CODES, true)) {
                    $code = $candidate;
                }
            } catch (JsonException) {
                // The subprocess error stays private and is reduced to a bounded code.
            }
            throw new FleetReallocationPlanningException($code ?? 'SOLVER_PROCESS_FAILED');
        }

        return $result->output();
    }
}
