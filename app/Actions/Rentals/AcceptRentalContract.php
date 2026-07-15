<?php

namespace App\Actions\Rentals;

use App\Enums\AcceptanceMethod;
use App\Enums\RentalContractStatus;
use App\Models\ContractAcceptance;
use App\Models\ContractStatusHistory;
use App\Models\ContractVersion;
use App\Models\RentalContract;
use App\Support\Audit\AuditRecorder;
use App\Support\Contracts\CanonicalJson;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AcceptRentalContract
{
    public function __construct(
        private CanonicalJson $canonical,
        private AuditRecorder $audit,
        private EnsureRequiredContractDocuments $documents,
    ) {}

    public function handle(RentalContract $contract, array $data, ?int $actorId): RentalContract
    {
        return DB::transaction(function () use ($contract, $data, $actorId) {
            $locked = RentalContract::with(['currentVersion', 'customer', 'drivers.driver'])->whereKey($contract)->lockForUpdate()->firstOrFail();
            if ($locked->status !== RentalContractStatus::Ready || ! $locked->currentVersion) {
                throw ValidationException::withMessages(['status' => 'Le contrat doit être prêt avec une version courante.']);
            }
            $version = ContractVersion::with('document')->whereKey($locked->current_version_id)->lockForUpdate()->firstOrFail();
            $locked->setRelation('currentVersion', $version);
            if ($locked->currentVersion->locked_at) {
                throw ValidationException::withMessages(['version' => 'Cette version est déjà verrouillée.']);
            }
            $driver = $locked->drivers->firstWhere('is_primary', true)?->driver;
            if (! $driver || $driver->licence_expires_at->endOfDay()->lt($locked->expected_return_at)) {
                throw ValidationException::withMessages(['driver' => 'Le permis principal doit être valide pendant toute la location.']);
            }
            $this->documents->handle($locked, $locked->customer, $driver);

            $acceptedAt = now();
            $contentHash = $this->canonical->hash(['contract_version_hash' => $locked->currentVersion->content_hash, 'accepted_by_name' => $data['accepted_by_name'], 'acceptance_method' => $data['acceptance_method'], 'consent_text_version' => config('rentals.consent_text_version'), 'accepted_at' => $acceptedAt->toIso8601String()]);
            ContractAcceptance::create(['rental_contract_id' => $locked->id, 'contract_version_id' => $locked->current_version_id, 'accepted_by_name' => $data['accepted_by_name'], 'acceptance_method' => AcceptanceMethod::from($data['acceptance_method']), 'consent_text_version' => config('rentals.consent_text_version'), 'accepted_at' => $acceptedAt, 'ip_address' => $data['ip_address'] ?? null, 'user_agent' => Str::limit((string) ($data['user_agent'] ?? ''), 1000, ''), 'signature_document_id' => $data['signature_document_id'] ?? null, 'content_hash' => $contentHash, 'created_by' => $actorId]);
            $locked->currentVersion->forceFill(['locked_at' => $acceptedAt])->save();
            $locked->forceFill(['status' => RentalContractStatus::Accepted, 'accepted_at' => $acceptedAt])->save();
            ContractStatusHistory::create(['rental_contract_id' => $locked->id, 'from_status' => RentalContractStatus::Ready, 'to_status' => RentalContractStatus::Accepted, 'changed_by' => $actorId]);
            $this->audit->record('contract.accepted', $locked, ['status' => 'ready'], ['status' => 'accepted', 'version_id' => $locked->current_version_id, 'content_hash' => $contentHash]);

            return $locked->refresh();
        });
    }
}
