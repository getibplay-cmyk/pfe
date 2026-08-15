<?php

namespace App\Actions\Intelligence;

use App\Enums\FleetReallocationRunStatus;
use App\Exceptions\FleetReallocationExecutionException;
use App\Exceptions\FleetReallocationValidationException;
use App\Models\FleetReallocationRun;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use App\Support\Intelligence\FleetReallocation\FleetReallocationContract;
use App\Support\Tenancy\TenantContext;
use Illuminate\Process\Exceptions\ProcessTimedOutException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use JsonException;
use Throwable;

final class ExecuteFleetReallocationRun
{
    private const PROCESS_FAILURE_CODES = [
        'RUNTIME_REQUEST_TOO_LARGE',
        'RUNTIME_REQUEST_INVALID',
        'PYTHON_VERSION_MISMATCH',
        'ORTOOLS_DEPENDENCY_MISSING',
        'ORTOOLS_VERSION_MISMATCH',
        'SCENARIO_HORIZON_MISMATCH',
        'SOLVER_RESULT_INVALID',
        'SOLVER_RUNTIME_GATE_FAILED',
        'SOLVER_EMPTY_PROPOSAL',
        'SOLVER_INTERNAL_FAILURE',
    ];

    public function __construct(
        private readonly TenantContext $context,
        private readonly ImportFleetReallocationProposal $import,
        private readonly AuditRecorder $audit,
    ) {}

    public function handle(string $runId, int $tenantId, int $actorId): void
    {
        $this->context->run($tenantId, function () use ($runId, $tenantId, $actorId): void {
            $run = $this->markRunning($runId, $actorId);
            $actor = User::query()
                ->whereKey($actorId)
                ->where('tenant_id', $tenantId)
                ->whereNull('agency_id')
                ->where('is_active', true)
                ->first();
            if ($actor === null || ! $actor->hasPermission('prediction.demo.review')) {
                throw new FleetReallocationExecutionException('RUN_ACTOR_NOT_AUTHORIZED');
            }

            $requestJson = $this->requestJson($run);
            $output = $this->executeProcess($requestJson);

            try {
                $proposal = $this->import->handlePayload($output, $actor)->proposal;
            } catch (FleetReallocationValidationException) {
                throw new FleetReallocationExecutionException('SOLVER_OUTPUT_INVALID');
            } catch (Throwable $exception) {
                throw new FleetReallocationExecutionException('SOLVER_OUTPUT_IMPORT_FAILED');
            }

            $locked = DB::transaction(function () use ($run, $proposal): FleetReallocationRun {
                $candidate = FleetReallocationRun::query()
                    ->where('run_id', $run->run_id)
                    ->lockForUpdate()
                    ->firstOrFail();
                if ($candidate->status !== FleetReallocationRunStatus::Running) {
                    throw new FleetReallocationExecutionException('RUN_STATE_CONFLICT');
                }
                $candidate->forceFill([
                    'fleet_reallocation_proposal_id' => $proposal->id,
                    'status' => FleetReallocationRunStatus::Succeeded,
                    'finished_at' => now(),
                ])->save();

                return $candidate;
            }, 3);

            $this->audit->record('prediction.fleet_reallocation.run_succeeded', $locked, [], [
                'run_id' => $locked->run_id,
                'proposal_id' => $proposal->proposal_id,
                'solver_status' => $proposal->solver_status,
                'effect' => FleetReallocationContract::OPERATIONAL_EFFECT,
            ]);
        });
    }

    private function markRunning(string $runId, int $actorId): FleetReallocationRun
    {
        return DB::transaction(function () use ($runId, $actorId): FleetReallocationRun {
            $run = FleetReallocationRun::query()
                ->where('run_id', $runId)
                ->lockForUpdate()
                ->firstOrFail();
            if ($run->requested_by !== $actorId || $run->status !== FleetReallocationRunStatus::Queued) {
                throw new FleetReallocationExecutionException('RUN_STATE_CONFLICT');
            }

            $run->forceFill([
                'status' => FleetReallocationRunStatus::Running,
                'started_at' => now(),
            ])->save();

            return $run;
        }, 3);
    }

    private function requestJson(FleetReallocationRun $run): string
    {
        try {
            return json_encode([
                'schema_version' => FleetReallocationContract::SCHEMA_VERSION,
                'proposal_id' => $run->run_id,
                'idempotency_key' => $run->run_id,
                'generated_at' => $run->requested_at->utc()->format('Y-m-d\TH:i:s\Z'),
                'as_of_date' => $run->requested_at
                    ->setTimezone((string) config('app.timezone'))
                    ->format('Y-m-d'),
                'forecast_horizon' => $run->forecast_horizon,
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        } catch (JsonException) {
            throw new FleetReallocationExecutionException('RUNTIME_REQUEST_ENCODING_FAILED');
        }
    }

    private function executeProcess(string $requestJson): string
    {
        $binary = (string) config('intelligence.fleet_reallocation.python_binary');
        $script = (string) config('intelligence.fleet_reallocation.runtime_script');
        $timeout = (int) config('intelligence.fleet_reallocation.runtime_timeout_seconds');
        if ($binary === '' || $script === '' || ! is_file($script) || $timeout < 1 || $timeout > 120) {
            throw new FleetReallocationExecutionException('RUNTIME_CONFIGURATION_INVALID');
        }

        try {
            $result = Process::path(base_path())
                ->timeout($timeout)
                ->idleTimeout(min(10, $timeout))
                ->env([
                    'PYTHONDONTWRITEBYTECODE' => '1',
                    'PYTHONHASHSEED' => '20260814',
                    'APP_KEY' => false,
                    'DB_PASSWORD' => false,
                    'MAIL_PASSWORD' => false,
                    'AWS_SECRET_ACCESS_KEY' => false,
                    'INTELLIGENCE_EXPORT_HMAC_KEY' => false,
                    'DEMO_PASSWORD' => false,
                    'PGPASSWORD' => false,
                ])
                ->input($requestJson)
                ->run([$binary, $script]);
        } catch (ProcessTimedOutException) {
            throw new FleetReallocationExecutionException('SOLVER_PROCESS_TIMEOUT');
        } catch (Throwable) {
            throw new FleetReallocationExecutionException('SOLVER_PROCESS_START_FAILED');
        }

        if ($result->failed()) {
            throw new FleetReallocationExecutionException(
                $this->processFailureCode($result->errorOutput()),
            );
        }

        $output = $result->output();
        $maximumBytes = (int) config('intelligence.fleet_reallocation.max_upload_kilobytes') * 1024;
        if ($output === '' || strlen($output) > $maximumBytes) {
            throw new FleetReallocationExecutionException('SOLVER_OUTPUT_INVALID');
        }

        return $output;
    }

    private function processFailureCode(string $errorOutput): string
    {
        try {
            $decoded = json_decode(trim($errorOutput), true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return 'SOLVER_PROCESS_FAILED';
        }
        $code = is_array($decoded) ? ($decoded['error_code'] ?? null) : null;

        return is_string($code) && in_array($code, self::PROCESS_FAILURE_CODES, true)
            ? $code
            : 'SOLVER_PROCESS_FAILED';
    }
}
