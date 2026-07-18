<?php

namespace App\Support\Insurance;

use App\Models\InsuranceCompany;
use App\Models\Vehicle;
use App\Support\Pricing\DecimalMoney;
use App\Support\Tenancy\AgencyAccess;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

class InsurancePolicyData
{
    public function __construct(private readonly AgencyAccess $agencies) {}

    public function normalize(array $data, ?int $fixedAgencyId = null): array
    {
        $agencyId = $fixedAgencyId ?? $this->agencies->required($data['agency_id'] ?? null);
        if ($fixedAgencyId !== null) {
            $this->agencies->required($fixedAgencyId);
            if (isset($data['agency_id']) && (int) $data['agency_id'] !== $fixedAgencyId) {
                $this->fail('agency_id', 'L’agence d’une police existante est immuable.');
            }
        }

        $vehicle = Vehicle::query()->whereKey($data['vehicle_id'] ?? null)->where('agency_id', $agencyId)->first();
        if (! $vehicle) {
            $this->fail('vehicle_id', 'Le véhicule doit appartenir à l’agence de la police.');
        }
        $company = InsuranceCompany::query()->whereKey($data['insurance_company_id'] ?? null)->where('is_active', true)->first();
        if (! $company) {
            $this->fail('insurance_company_id', 'La compagnie doit être active dans le tenant courant.');
        }
        if (! in_array($data['policy_type'] ?? null, ['mandatory_liability', 'comprehensive', 'third_party', 'other'], true)) {
            $this->fail('policy_type', 'Le type de police est invalide.');
        }

        try {
            $startsAt = CarbonImmutable::parse($data['starts_at'])->startOfDay();
            $endsAt = CarbonImmutable::parse($data['ends_at'])->startOfDay();
            $premium = DecimalMoney::fromMinorUnits(DecimalMoney::toMinorUnits($data['premium_amount']));
            $deductible = DecimalMoney::fromMinorUnits(DecimalMoney::toMinorUnits($data['deductible_amount']));
        } catch (\Throwable) {
            $this->fail('policy', 'La période ou les montants de la police sont invalides.');
        }
        if ($endsAt->lessThan($startsAt)) {
            $this->fail('ends_at', 'La fin de couverture doit être postérieure ou égale au début.');
        }
        $currency = strtoupper((string) ($data['currency'] ?? 'MAD'));
        if (preg_match('/^[A-Z]{3}$/', $currency) !== 1) {
            $this->fail('currency', 'La devise doit contenir trois lettres majuscules.');
        }

        return [
            'agency_id' => $agencyId,
            'vehicle_id' => $vehicle->id,
            'insurance_company_id' => $company->id,
            'policy_type' => $data['policy_type'],
            'starts_at' => $startsAt->toDateString(),
            'ends_at' => $endsAt->toDateString(),
            'premium_amount' => $premium,
            'deductible_amount' => $deductible,
            'currency' => $currency,
        ];
    }

    private function fail(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}
