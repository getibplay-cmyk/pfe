<?php

namespace App\Actions\Intelligence;

use App\Exceptions\FleetReallocationIdempotencyConflictException;
use App\Exceptions\FleetReallocationValidationException;
use App\Models\FleetReallocationMove;
use App\Models\FleetReallocationProposal;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use App\Support\Intelligence\FleetReallocation\FleetReallocationArtifactVerifier;
use App\Support\Intelligence\FleetReallocation\FleetReallocationContract;
use App\Support\Intelligence\FleetReallocation\FleetReallocationProposalImportResult;
use App\Support\Intelligence\FleetReallocation\FleetReallocationProposalValidator;
use App\Support\Intelligence\FleetReallocation\ValidatedFleetReallocationProposal;
use App\Support\Tenancy\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

final class ImportFleetReallocationProposal
{
    public function __construct(
        private readonly FleetReallocationProposalValidator $validator,
        private readonly FleetReallocationArtifactVerifier $artifactVerifier,
        private readonly TenantContext $context,
        private readonly AuditRecorder $audit,
    ) {}

    public function handle(UploadedFile $file, User $actor): FleetReallocationProposalImportResult
    {
        $maximumBytes = (int) config('intelligence.fleet_reallocation.max_upload_kilobytes') * 1024;
        $uploadedBytes = $file->getSize();
        if (! is_int($uploadedBytes) || $uploadedBytes <= 0 || $uploadedBytes > $maximumBytes) {
            throw FleetReallocationValidationException::at(
                '$',
                'taille du fichier JSON absente ou supérieure à la limite autorisée',
            );
        }

        $realPath = $file->getRealPath();
        $contents = is_string($realPath) ? file_get_contents($realPath) : false;
        if (! is_string($contents) || $contents === '') {
            throw new RuntimeException('La proposition de réallocation téléversée est vide ou illisible.');
        }

        return $this->handlePayload($contents, $actor);
    }

    public function handlePayload(string $contents, User $actor): FleetReallocationProposalImportResult
    {
        $this->assertActor($actor);
        $maximumBytes = (int) config('intelligence.fleet_reallocation.max_upload_kilobytes') * 1024;
        if ($contents === '' || strlen($contents) > $maximumBytes) {
            throw FleetReallocationValidationException::at(
                '$',
                'taille du JSON absente ou supérieure à la limite autorisée',
            );
        }

        $validated = $this->validator->validate($contents);
        $disk = Storage::disk((string) config('intelligence.fleet_reallocation.disk'));
        $candidateStoredPath = 'intelligence/fleet-reallocation/'.Str::uuid().'.json';
        $storedPath = null;

        try {
            return DB::transaction(function () use (
                $actor,
                $validated,
                $disk,
                $candidateStoredPath,
                &$storedPath,
            ): FleetReallocationProposalImportResult {
                DB::selectOne(
                    'SELECT pg_advisory_xact_lock(hashtextextended(CAST(? AS text), 0))',
                    [implode('|', [
                        'fleet-reallocation',
                        $this->context->tenantId(),
                        $validated->idempotencyKey,
                    ])],
                );

                $existing = $this->idempotencyKeyInScope($validated->idempotencyKey, true);
                if ($existing !== null) {
                    return $this->replay($existing, $validated);
                }

                $storedPath = $candidateStoredPath;
                if (! $disk->put($storedPath, $validated->canonicalJson, ['visibility' => 'private'])) {
                    throw new RuntimeException('Impossible de conserver la proposition de réallocation privée.');
                }

                $payload = $validated->payload;
                $source = $payload['source'];
                $planning = $payload['planning'];
                $demand = $planning['demand_source'];
                $cancellation = $planning['cancellation_risk'];
                $summary = $payload['summary'];
                $proposal = FleetReallocationProposal::create([
                    'proposal_id' => $validated->proposalId,
                    'idempotency_key' => $validated->idempotencyKey,
                    'schema_version' => $payload['schema_version'],
                    'source_kind' => $source['kind'],
                    'solver_name' => $source['solver_name'],
                    'solver_version' => $source['solver_version'],
                    'solver_status' => $source['solver_status'],
                    'qualification_decision' => $source['qualification_decision'],
                    'qualification_commit' => $source['qualification_commit'],
                    'evidence_commit' => $source['evidence_commit'],
                    'generated_at' => $validated->generatedAt,
                    'as_of_date' => $validated->asOfDate,
                    'target_date' => $validated->targetDate,
                    'forecast_horizon' => $planning['forecast_horizon'],
                    'distance_unit' => $planning['distance_unit'],
                    'data_status' => $planning['data_status'],
                    'forecast_model_name' => $demand['model_name'],
                    'forecast_model_version' => $demand['model_version'],
                    'forecast_reference_sha256' => $demand['forecast_reference_sha256'],
                    'forecast_local_status' => $demand['local_holdout_status'],
                    'cancellation_model_name' => $cancellation['model_name'],
                    'cancellation_gate_decision' => $cancellation['gate_decision'],
                    'presence_probability' => $cancellation['presence_probability'],
                    'presence_reason' => $cancellation['presence_reason'],
                    'node_count' => $summary['node_count'],
                    'move_line_count' => $summary['move_line_count'],
                    'relocated_vehicle_count' => $summary['relocated_vehicle_count'],
                    'total_demand' => $summary['total_demand'],
                    'served_demand' => $summary['served_demand'],
                    'unserved_demand' => $summary['unserved_demand'],
                    'service_rate' => $summary['service_rate'],
                    'relocation_cost_centimes' => $summary['relocation_cost_centimes'],
                    'decision_cost_centimes' => $summary['decision_cost_centimes'],
                    'solver_runtime_ms' => $summary['solver_runtime_ms'],
                    'canonical_payload_sha256' => $validated->canonicalPayloadSha256,
                    'content_sha256' => hash('sha256', $validated->canonicalJson),
                    'byte_size' => strlen($validated->canonicalJson),
                    'stored_path' => $storedPath,
                    'original_name' => 'rentfleet_fleet_reallocation_'.$validated->proposalId.'.json',
                    'validation_status' => 'validated',
                    'local_validation_status' => FleetReallocationContract::LOCAL_VALIDATION_STATUS,
                    'operational_effect' => FleetReallocationContract::OPERATIONAL_EFFECT,
                    'imported_by' => $actor->id,
                    'imported_at' => now(),
                ]);

                foreach ($validated->moves as $position => $move) {
                    FleetReallocationMove::create([
                        'fleet_reallocation_proposal_id' => $proposal->id,
                        'row_position' => $position,
                        'from_node_ref' => $move['from_node_ref'],
                        'to_node_ref' => $move['to_node_ref'],
                        'vehicles' => $move['vehicles'],
                        'distance_km' => $move['distance_km'],
                        'unit_cost_centimes' => $move['unit_cost_centimes'],
                        'total_cost_centimes' => $move['total_cost_centimes'],
                        'reason_code' => $move['reason_code'],
                        'operational_effect' => FleetReallocationContract::OPERATIONAL_EFFECT,
                        'created_at' => now(),
                    ]);
                }

                $this->recordAudit('prediction.fleet_reallocation.imported', $proposal, 'CREATED');

                return new FleetReallocationProposalImportResult($proposal, true);
            }, 3);
        } catch (QueryException $exception) {
            if ($storedPath !== null) {
                $disk->delete($storedPath);
            }
            if ((string) $exception->getCode() !== '23505') {
                throw $exception;
            }

            $existing = $this->idempotencyKeyInScope($validated->idempotencyKey);
            if ($existing === null) {
                throw $exception;
            }

            return $this->replay($existing, $validated);
        } catch (Throwable $exception) {
            if ($storedPath !== null) {
                $disk->delete($storedPath);
            }

            throw $exception;
        }
    }

    private function idempotencyKeyInScope(string $key, bool $lock = false): ?FleetReallocationProposal
    {
        $query = FleetReallocationProposal::query()->where('idempotency_key', $key);
        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->first();
    }

    private function replay(
        FleetReallocationProposal $existing,
        ValidatedFleetReallocationProposal $validated,
    ): FleetReallocationProposalImportResult {
        if ($existing->proposal_id !== $validated->proposalId
            || ! hash_equals($existing->canonical_payload_sha256, $validated->canonicalPayloadSha256)
            || ! $this->artifactVerifier->valid($existing)) {
            throw new FleetReallocationIdempotencyConflictException;
        }

        $this->recordAudit('prediction.fleet_reallocation.replayed', $existing, 'REPLAY_SAFE');

        return new FleetReallocationProposalImportResult($existing, false);
    }

    private function assertActor(User $actor): void
    {
        if ($actor->tenant_id !== $this->context->tenantId()
            || $actor->agency_id !== null
            || $this->context->agencyId() !== null
            || ! $actor->is_active
            || ! $actor->hasPermission('prediction.demo.review')) {
            throw new AuthorizationException;
        }
    }

    private function recordAudit(
        string $action,
        FleetReallocationProposal $proposal,
        string $outcome,
    ): void {
        $this->audit->record($action, $proposal, [], [
            'proposal_id' => $proposal->proposal_id,
            'solver_name' => $proposal->solver_name,
            'solver_version' => $proposal->solver_version,
            'solver_status' => $proposal->solver_status,
            'forecast_model' => $proposal->forecast_model_name,
            'cancellation_gate_decision' => $proposal->cancellation_gate_decision,
            'move_line_count' => $proposal->move_line_count,
            'outcome' => $outcome,
            'local_validation_status' => FleetReallocationContract::LOCAL_VALIDATION_STATUS,
            'effect' => FleetReallocationContract::OPERATIONAL_EFFECT,
        ]);
    }
}
