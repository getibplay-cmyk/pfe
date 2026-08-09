<?php

namespace App\Actions\Intelligence;

use App\Enums\J11AdvisoryModule;
use App\Exceptions\J11IdempotencyConflictException;
use App\Models\AiAdvisoryRecordDemo;
use App\Models\AiIdempotencyKeyDemo;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use App\Support\Intelligence\J11\J11ContractDemoGate;
use App\Support\Intelligence\J11\J11ImportResult;
use App\Support\Intelligence\J11\J11SyntheticFixtureRepository;
use App\Support\Tenancy\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

final class ImportJ11SyntheticAdvisory
{
    public function __construct(
        private readonly J11ContractDemoGate $gate,
        private readonly J11SyntheticFixtureRepository $fixtures,
        private readonly TenantContext $context,
        private readonly AuditRecorder $audit,
    ) {}

    public function handle(J11AdvisoryModule $module, User $actor): J11ImportResult
    {
        $this->gate->assertEnabled();
        $this->assertActor($actor);
        $fixture = $this->fixtures->get($module);

        try {
            return DB::transaction(function () use ($fixture, $actor): J11ImportResult {
                $existingKey = AiIdempotencyKeyDemo::query()
                    ->where('idempotency_key', $fixture->idempotencyKey)
                    ->lockForUpdate()
                    ->first();

                if ($existingKey !== null) {
                    return $this->replay($existingKey, $fixture->fingerprint, $actor);
                }

                $record = AiAdvisoryRecordDemo::create([
                    'agency_id' => $this->context->agencyId(),
                    'external_record_id' => $fixture->recordId,
                    'module_id' => $fixture->module,
                    'contract_version' => '1.0.0',
                    'source_kind' => 'synthetic_fixture',
                    'payload' => $fixture->payload,
                    'fingerprint' => $fixture->fingerprint,
                    'validation_status' => 'validated',
                    'operational_effect' => 'NO_OPERATIONAL_ACTION',
                    'created_by' => $actor->id,
                    'created_at' => now(),
                ]);

                AiIdempotencyKeyDemo::create([
                    'ai_advisory_record_demo_id' => $record->id,
                    'idempotency_key' => $fixture->idempotencyKey,
                    'fingerprint' => $fixture->fingerprint,
                    'first_result' => 'CREATED',
                    'created_at' => now(),
                ]);

                $this->recordAudit('prediction.demo.fixture_imported', $record, 'CREATED');

                return new J11ImportResult($record, true);
            }, 3);
        } catch (QueryException $exception) {
            if ((string) $exception->getCode() !== '23505') {
                throw $exception;
            }

            $existingKey = AiIdempotencyKeyDemo::query()
                ->where('idempotency_key', $fixture->idempotencyKey)
                ->first();

            if ($existingKey === null) {
                throw $exception;
            }

            return $this->replay($existingKey, $fixture->fingerprint, $actor);
        }
    }

    private function replay(AiIdempotencyKeyDemo $key, string $fingerprint, User $actor): J11ImportResult
    {
        if (! hash_equals($key->fingerprint, $fingerprint)) {
            throw new J11IdempotencyConflictException;
        }

        $record = AiAdvisoryRecordDemo::findOrFail($key->ai_advisory_record_demo_id);
        if ($actor->agency_id !== null && $actor->agency_id !== $record->agency_id) {
            throw new AuthorizationException;
        }
        $this->recordAudit('prediction.demo.fixture_replayed', $record, 'REPLAY_SAFE');

        return new J11ImportResult($record, false);
    }

    private function assertActor(User $actor): void
    {
        if ($actor->tenant_id !== $this->context->tenantId()
            || ! $actor->hasPermission('prediction.demo.review')) {
            throw new AuthorizationException;
        }

        if ($actor->agency_id !== null && $actor->agency_id !== $this->context->agencyId()) {
            throw new AuthorizationException;
        }
    }

    private function recordAudit(string $action, AiAdvisoryRecordDemo $record, string $outcome): void
    {
        $this->audit->record($action, $record, [], [
            'module_id' => $record->module_id->value,
            'contract_version' => $record->contract_version,
            'fingerprint' => $record->fingerprint,
            'outcome' => $outcome,
            'effect' => 'NO_OPERATIONAL_ACTION',
        ]);
    }
}
