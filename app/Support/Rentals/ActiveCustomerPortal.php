<?php

namespace App\Support\Rentals;

use App\Actions\Documents\StorePrivateDocument;
use App\Actions\Rentals\AcceptRentalContract;
use App\Actions\Rentals\EnsureRequiredContractDocuments;
use App\Actions\Rentals\RecordContractAcceptance;
use App\Enums\RentalContractStatus;
use App\Models\ContractExtension;
use App\Models\ContractVersion;
use App\Models\CustomerPortalAccess;
use App\Models\Document;
use App\Models\RentalContract;
use App\Models\User;
use App\Models\VehicleBlock;
use App\Support\Audit\AuditRecorder;
use App\Support\Contracts\CanonicalJson;
use App\Support\Pricing\DecimalMoney;
use App\Support\Tenancy\CustomerPortalContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

final class ActiveCustomerPortal
{
    public function contract(CustomerPortalAccess $access, int $id): RentalContract
    {
        $customer = app(CustomerPortalContext::class)->customer($access);
        abort_unless($access->session_expires_at?->gt(now()), 403);

        return RentalContract::where('customer_id', $customer->id)->where('agency_id', $customer->agency_id)->whereIn('status', ['ready', 'accepted', 'active', 'return_pending', 'returned', 'closed'])->findOrFail($id);
    }

    public function acceptanceProposal(CustomerPortalAccess $access, RentalContract $contract): string
    {
        abort_unless($contract->status === RentalContractStatus::Ready, 409);
        $version = $contract->currentVersion()->with('document.currentVersion')->firstOrFail();
        $file = $version->document?->currentVersion;
        abort_unless($file && ! $version->locked_at, 409, __('Le document est en préparation.'));

        return Crypt::encryptString(json_encode(['access' => $access->id, 'contract' => $contract->id, 'version' => $version->id, 'content_hash' => $version->content_hash, 'document_version' => $file->id, 'document_hash' => $file->sha256, 'expires' => now()->addMinutes(20)->timestamp], JSON_THROW_ON_ERROR));
    }

    public function accept(CustomerPortalAccess $access, int $id, string $proposal, array $data): void
    {
        try {
            $payload = json_decode(Crypt::decryptString($proposal), true, 8, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            abort(422, __('Rechargez le contrat avant de l’accepter.'));
        }
        abort_unless(($payload['access'] ?? null) === $access->id && ($payload['contract'] ?? null) === $id, 403);
        abort_if(($payload['expires'] ?? 0) < now()->timestamp, 409, __('La proposition a expiré.'));
        DB::transaction(function () use ($access, $id, $payload, $data) {
            $access = $this->lockAccess($access);
            $contract = $this->contract($access, $id);
            $contract = RentalContract::whereKey($contract)->lockForUpdate()->firstOrFail();
            $version = $contract->currentVersion()->with('document.currentVersion')->lockForUpdate()->firstOrFail();
            $file = $version->document?->currentVersion;
            abort_unless($version->id === $payload['version'] && hash_equals($version->content_hash, $payload['content_hash']) && $file?->id === $payload['document_version'] && hash_equals($file->sha256, $payload['document_hash']), 409, __('Le contrat a changé. Relisez sa nouvelle version.'));
            if (DB::table('portal_contract_acceptances')->where('tenant_id', $contract->tenant_id)->where('contract_version_id', $version->id)->where('customer_id', $access->customer_id)->exists()) {
                return;
            }
            $contract = app(AcceptRentalContract::class)->handle($contract, [...$data, 'acceptance_method' => 'typed_name'], null);
            $this->proof($access, $contract, $version->id);
        });
    }

    public function requestExtension(CustomerPortalAccess $access, int $id, array $data): ContractExtension
    {
        return DB::transaction(function () use ($access, $id, $data) {
            $access = $this->lockAccess($access);
            $contract = $this->contract($access, $id);
            $contract = RentalContract::whereKey($contract)->lockForUpdate()->firstOrFail();
            $this->extendable($contract);
            $end = CarbonImmutable::parse($data['requested_return_at'], config('app.timezone'));
            abort_unless($end->gt($contract->expected_return_at) && $end->gt(now()) && $end->lte($contract->expected_return_at->addDays(30)), 422, __('Choisissez un retour ultérieur, dans les 30 jours suivant le retour prévu.'));
            abort_if(ContractExtension::where('rental_contract_id', $contract->id)->whereIn('status', ['requested', 'offered'])->exists(), 409, __('Une demande est déjà en cours. Retirez-la avant d’en déposer une autre.'));
            $extension = ContractExtension::create(['agency_id' => $contract->agency_id, 'customer_id' => $contract->customer_id, 'rental_contract_id' => $contract->id, 'requested_access_id' => $access->id, 'requested_return_at' => $end, 'customer_note' => $data['customer_note'] ?? null, 'status' => 'requested']);
            app(AuditRecorder::class)->record('portal.extension.requested', $extension, [], ['contract_id' => $contract->id]);

            return $extension;
        });
    }

    public function authorizeReview(User $user, RentalContract $contract): void
    {
        Gate::forUser($user)->authorize('view', $contract);
        abort_unless($user->hasPermission('contract.version') && $user->hasPermission('contract.accept') && $user->hasPermission('document.upload'), 403);
    }

    public function offer(User $user, ContractExtension $extension, array $data, UploadedFile $pdf): void
    {
        $this->authorizeReview($user, $extension->rentalContract);
        $storedPath = null;
        try {
            DB::transaction(function () use ($user, $extension, $data, $pdf, &$storedPath) {
                $contract = RentalContract::whereKey($extension->rental_contract_id)->lockForUpdate()->firstOrFail();
                $extension = ContractExtension::whereKey($extension)->lockForUpdate()->firstOrFail();
                abort_unless($extension->status === 'requested', 409);
                $this->extendable($contract);
                $this->availability($contract, $extension->requested_return_at);
                $base = $contract->currentVersion()->firstOrFail();
                abort_unless($base->locked_at && $extension->requested_return_at->gt($contract->expected_return_at) && $extension->requested_return_at->gt(now()), 409);
                $amount = DecimalMoney::toMinorUnits($data['additional_amount']);
                $total = DecimalMoney::toMinorUnits($contract->rental_subtotal) + $amount;
                $terms = $base->terms_snapshot;
                $terms['expected_return_at'] = $extension->requested_return_at->toIso8601String();
                $terms['extension'] = ['request_id' => $extension->id, 'base_version_id' => $base->id, 'original_return_at' => $contract->expected_return_at->toIso8601String(), 'additional_amount' => DecimalMoney::fromMinorUnits($amount), 'rental_subtotal' => DecimalMoney::fromMinorUnits($total), 'currency' => $contract->currency, 'included_km' => (int) $data['included_km']];
                $terms['document']['rental']['expected_return_at'] = $extension->requested_return_at->toIso8601String();
                $pricing = $base->pricing_snapshot;
                $pricing['extensions'] = [...($pricing['extensions'] ?? []), $terms['extension']];
                $pricing['period']['ends_at'] = $extension->requested_return_at->toIso8601String();
                $pricing['calculation']['total_amount'] = DecimalMoney::fromMinorUnits($total);
                $pricing['calculation']['subtotal'] = DecimalMoney::fromMinorUnits($total - DecimalMoney::toMinorUnits($pricing['calculation']['options_total'] ?? '0.00'));
                $pricing['calculation']['billed_days'] = (int) ($pricing['calculation']['billed_days'] ?? 1) + max(1, (int) ceil($contract->expected_return_at->diffInSeconds($extension->requested_return_at) / 86400));
                $terms['document']['rental']['billed_days'] = $pricing['calculation']['billed_days'];
                $snapshot = ['terms_snapshot' => $terms, 'pricing_snapshot' => $pricing, 'customer_snapshot' => $base->customer_snapshot, 'vehicle_snapshot' => $base->vehicle_snapshot];
                $document = app(StorePrivateDocument::class)->handle($contract, ['document_type' => 'contract_acceptance', 'title' => 'Avenant de prolongation — '.$contract->contract_number, 'is_sensitive' => true], $pdf, $user->id);
                $file = $document->currentVersion;
                $storedPath = $file->stored_path;
                $extension->forceFill(['status' => 'offered', 'base_version_id' => $base->id, 'original_return_at' => $contract->expected_return_at, 'additional_amount' => DecimalMoney::fromMinorUnits($amount), 'currency' => $contract->currency, 'offer_snapshot' => $snapshot, 'offer_hash' => app(CanonicalJson::class)->hash($snapshot), 'document_id' => $document->id, 'document_version_id' => $file->id, 'document_hash' => $file->sha256, 'reviewed_by' => $user->id, 'agency_note' => $data['agency_note'] ?? null, 'offered_at' => now(), 'expires_at' => now()->addHours(24)->min($extension->requested_return_at)])->save();
                app(AuditRecorder::class)->record('contract.extension.offered', $extension, [], ['additional_amount' => $extension->additional_amount, 'currency' => $extension->currency]);
            });
        } catch (\Throwable $exception) {
            if ($storedPath !== null) {
                Storage::disk(config('documents.disk'))->delete($storedPath);
            }
            throw $exception;
        }
    }

    public function acceptExtension(CustomerPortalAccess $access, int $id, int $extensionId, array $data): void
    {
        DB::transaction(function () use ($access, $id, $extensionId, $data) {
            $access = $this->lockAccess($access);
            $contract = $this->contract($access, $id);
            $contract = RentalContract::whereKey($contract)->lockForUpdate()->firstOrFail();
            $extension = ContractExtension::where('rental_contract_id', $contract->id)->whereKey($extensionId)->lockForUpdate()->firstOrFail();
            if ($extension->status === 'accepted') {
                return;
            }
            $this->extendable($contract);
            abort_unless($extension->status === 'offered' && $extension->expires_at->gt(now()), 409, __('La proposition n’est plus disponible.'));
            abort_unless($contract->current_version_id === $extension->base_version_id && $contract->expected_return_at->eq($extension->original_return_at), 409, __('Le contrat a changé. Votre agence doit préparer une nouvelle proposition.'));
            $reviewer = User::where('tenant_id', $contract->tenant_id)->where('is_active', true)->findOrFail($extension->reviewed_by);
            $this->authorizeReview($reviewer, $contract);
            $this->availability($contract, $extension->requested_return_at);
            $document = $this->extensionDocument($extension);
            $driver = $contract->drivers()->with('driver')->where('is_primary', true)->first()?->driver;
            abort_unless($driver && $driver->licence_expires_at->endOfDay()->gte($extension->requested_return_at), 422, __('Le permis doit rester valide jusqu’au nouveau retour.'));
            $snapshot = $extension->offer_snapshot;
            abort_unless(hash_equals($extension->offer_hash, app(CanonicalJson::class)->hash($snapshot)), 409);
            $version = ContractVersion::create([...$snapshot, 'agency_id' => $contract->agency_id, 'rental_contract_id' => $contract->id, 'version_number' => (int) $contract->versions()->max('version_number') + 1, 'document_id' => $document->id, 'content_hash' => $extension->offer_hash, 'change_reason' => 'Prolongation demandée et acceptée dans le portail', 'created_by' => $extension->reviewed_by]);
            $contract->setRelation('currentVersion', $version);
            app(EnsureRequiredContractDocuments::class)->handle($contract, $contract->customer, $driver);
            app(RecordContractAcceptance::class)->handle($version, [...$data, 'acceptance_method' => 'typed_name'], null);
            $block = $contract->vehicleBlock()->where('status', 'active')->lockForUpdate()->firstOrFail();
            $block->update(['ends_at' => $extension->requested_return_at]);
            $subtotal = DecimalMoney::toMinorUnits($contract->rental_subtotal) + DecimalMoney::toMinorUnits($extension->additional_amount);
            $total = $subtotal + DecimalMoney::toMinorUnits($contract->additional_charges_total);
            $contract->forceFill(['current_version_id' => $version->id, 'expected_return_at' => $extension->requested_return_at, 'rental_subtotal' => DecimalMoney::fromMinorUnits($subtotal), 'total_amount' => DecimalMoney::fromMinorUnits($total)])->save();
            $extension->forceFill(['status' => 'accepted', 'resolved_at' => now(), 'accepted_version_id' => $version->id])->save();
            $this->proof($access, $contract, $version->id);
            app(AuditRecorder::class)->record('contract.extension.applied', $contract, [], ['extension_id' => $extension->id, 'version_id' => $version->id]);
        }, 3);
    }

    public function resolve(ContractExtension $extension, string $status, ?User $reviewer = null, ?CustomerPortalAccess $access = null, ?string $note = null): void
    {
        DB::transaction(function () use ($extension, $status, $reviewer, $access, $note) {
            if ($access) {
                $access = $this->lockAccess($access);
            }
            $contract = RentalContract::whereKey($extension->rental_contract_id)->lockForUpdate()->firstOrFail();
            $extension = ContractExtension::whereKey($extension)->lockForUpdate()->firstOrFail();
            if ($reviewer) {
                $this->authorizeReview($reviewer, $contract);
                abort_unless($status === 'rejected', 422);
            } else {
                abort_unless($access && $status === 'cancelled', 403);
                $this->contract($access, $contract->id);
            }
            abort_unless(in_array($extension->status, ['requested', 'offered'], true), 409);
            $values = ['status' => $status, 'resolved_at' => now(), 'resolution_note' => $reviewer ? $note : null];
            if ($extension->status === 'requested' && $reviewer) {
                $values += ['reviewed_by' => $reviewer->id, 'agency_note' => $note];
            }
            $extension->forceFill($values)->save();
            app(AuditRecorder::class)->record('contract.extension.'.$status, $extension);
        });
    }

    public function extensionDocument(ContractExtension $extension): Document
    {
        $document = $extension->document()->with('currentVersion')->firstOrFail();
        $file = $document->currentVersion;
        abort_unless($file && $file->id === $extension->document_version_id && hash_equals($file->sha256, $extension->document_hash), 409, __('Le document a changé. Contactez votre agence.'));
        $disk = Storage::disk(config('documents.disk'));
        abort_unless($disk->exists($file->stored_path) && hash_equals($extension->document_hash, hash('sha256', $disk->get($file->stored_path))), 409, __('Le document n’est plus disponible.'));

        return $document;
    }

    private function lockAccess(CustomerPortalAccess $access): CustomerPortalAccess
    {
        $locked = CustomerPortalAccess::whereKey($access)->lockForUpdate()->firstOrFail();
        app(CustomerPortalContext::class)->customer($locked);
        abort_unless($locked->consumed_at && $locked->session_expires_at?->gt(now()) && hash_equals((string) $locked->session_hash, (string) $access->session_hash), 403);

        return $locked;
    }

    private function extendable(RentalContract $contract): void
    {
        abort_unless(in_array($contract->status, [RentalContractStatus::Accepted, RentalContractStatus::Active], true) && ! $contract->invoice_id, 409, __('Seuls les contrats acceptés ou en cours peuvent être prolongés.'));
    }

    private function availability(RentalContract $contract, CarbonImmutable $end): void
    {
        $block = $contract->vehicleBlock()->where('status', 'active')->firstOrFail();
        $conflict = VehicleBlock::where('vehicle_id', $contract->vehicle_id)->where('status', 'active')->where('id', '!=', $block->id)->where('starts_at', '<', $end)->where('ends_at', '>', $block->starts_at)->exists();
        if ($conflict) {
            throw ValidationException::withMessages(['requested_return_at' => __('Le véhicule est déjà occupé pendant la prolongation.')]);
        }
    }

    private function proof(CustomerPortalAccess $access, RentalContract $contract, int $versionId): void
    {
        DB::table('portal_contract_acceptances')->insert(['tenant_id' => $contract->tenant_id, 'customer_id' => $contract->customer_id, 'rental_contract_id' => $contract->id, 'contract_version_id' => $versionId, 'access_id' => $access->id, 'created_at' => now()]);
    }
}
