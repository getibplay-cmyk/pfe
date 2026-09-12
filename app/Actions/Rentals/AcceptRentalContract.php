<?php

namespace App\Actions\Rentals;

use App\Enums\RentalContractStatus;
use App\Models\ContractStatusHistory;
use App\Models\ContractVersion;
use App\Models\RentalContract;
use App\Support\Audit\AuditRecorder;
use App\Support\Contracts\CanonicalJson;
use Illuminate\Support\Facades\DB;
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
                throw ValidationException::withMessages(['status' => __('Le contrat doit être prêt avec une version courante.')]);
            }
            $version = ContractVersion::with('document')->whereKey($locked->current_version_id)->lockForUpdate()->firstOrFail();
            $locked->setRelation('currentVersion', $version);
            if ($locked->currentVersion->locked_at) {
                throw ValidationException::withMessages(['version' => __('Cette version est déjà verrouillée.')]);
            }
            $driver = $locked->drivers->firstWhere('is_primary', true)?->driver;
            if (! $driver || $driver->licence_expires_at->endOfDay()->lt($locked->expected_return_at)) {
                throw ValidationException::withMessages(['driver' => __('Le permis principal doit être valide pendant toute la location.')]);
            }
            $this->documents->handle($locked, $locked->customer, $driver);

            $acceptance = app(RecordContractAcceptance::class)->handle($version, $data, $actorId);
            $acceptedAt = $acceptance->accepted_at;
            $contentHash = $acceptance->content_hash;
            $locked->forceFill(['status' => RentalContractStatus::Accepted, 'accepted_at' => $acceptedAt])->save();
            ContractStatusHistory::create(['rental_contract_id' => $locked->id, 'from_status' => RentalContractStatus::Ready, 'to_status' => RentalContractStatus::Accepted, 'changed_by' => $actorId]);
            $this->audit->record('contract.accepted', $locked, ['status' => 'ready'], ['status' => 'accepted', 'version_id' => $locked->current_version_id, 'content_hash' => $contentHash]);

            return $locked->refresh();
        });
    }
}
