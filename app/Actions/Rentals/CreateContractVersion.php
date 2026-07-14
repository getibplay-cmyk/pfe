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

class CreateContractVersion
{
    public function __construct(private CanonicalJson $canonical, private AuditRecorder $audit) {}

    public function handle(RentalContract $contract, int $actorId, ?string $reason = null, array $termsOverride = []): ContractVersion
    {
        return DB::transaction(function () use ($contract, $actorId, $reason, $termsOverride) {
            $locked = RentalContract::with(['reservation.pricingRule', 'customer', 'vehicle', 'drivers.driver', 'currentVersion'])->whereKey($contract)->lockForUpdate()->firstOrFail();
            if (! in_array($locked->status, [RentalContractStatus::Draft, RentalContractStatus::Ready, RentalContractStatus::Accepted], true)) {
                throw ValidationException::withMessages(['version' => 'Une nouvelle version est autorisée uniquement pendant la préparation ou comme avenant après acceptation.']);
            }
            $number = (int) $locked->versions()->max('version_number') + 1;
            $reservation = $locked->reservation;
            $rule = $reservation->pricingRule;
            $primary = $locked->drivers->firstWhere('is_primary', true)?->driver;
            $terms = [...[
                'schema_version' => 1,
                'contract_number' => $locked->contract_number,
                'expected_start_at' => $locked->expected_start_at->toIso8601String(),
                'expected_return_at' => $locked->expected_return_at->toIso8601String(),
                'driver' => $primary ? ['id' => $primary->id, 'name' => trim($primary->first_name.' '.$primary->last_name), 'licence_category' => $primary->licence_category, 'licence_expires_at' => $primary->licence_expires_at->toDateString()] : null,
                'fuel_policy' => ['mode' => 'same_level', 'missing_unit_rate' => config('rentals.missing_fuel_unit_rate')],
                'included_km_per_day' => $rule?->included_km_per_day,
                'extra_km_rate' => $rule?->extra_km_rate,
                'late_hour_rate' => $rule?->late_hour_rate,
                'consent_text_version' => config('rentals.consent_text_version'),
                'clauses' => $rule?->conditions ?? [],
            ], ...$termsOverride];
            $pricing = $reservation->pricing_snapshot;
            $customer = ['id' => $locked->customer->id, 'display_name' => $locked->customer->displayName(), 'type' => $locked->customer->customer_type->value, 'identity' => 'masked'];
            $vehicle = ['id' => $locked->vehicle->id, 'registration_number' => $locked->vehicle->registration_number, 'brand' => $locked->vehicle->brand, 'model' => $locked->vehicle->model, 'vin' => $locked->vehicle->vin ? 'masked' : null];
            $content = ['terms_snapshot' => $terms, 'pricing_snapshot' => $pricing, 'customer_snapshot' => $customer, 'vehicle_snapshot' => $vehicle];
            $version = ContractVersion::create([...$content, 'rental_contract_id' => $locked->id, 'version_number' => $number, 'content_hash' => $this->canonical->hash($content), 'change_reason' => $reason, 'created_by' => $actorId]);
            $locked->forceFill(['current_version_id' => $version->id])->save();
            if ($locked->status === RentalContractStatus::Accepted) {
                $locked->forceFill(['status' => RentalContractStatus::Ready, 'accepted_at' => null])->save();
                ContractStatusHistory::create(['rental_contract_id' => $locked->id, 'from_status' => RentalContractStatus::Accepted, 'to_status' => RentalContractStatus::Ready, 'reason' => 'Avenant à accepter : '.$reason, 'changed_by' => $actorId]);
            }
            $this->audit->record('contract.version.created', $locked, [], ['version_id' => $version->id, 'version_number' => $number, 'content_hash' => $version->content_hash]);

            return $version;
        });
    }
}
