<?php

namespace Database\Seeders;

use App\Enums\RentalContractStatus;
use App\Models\ContractAcceptance;
use App\Models\ContractDriver;
use App\Models\ContractStatusHistory;
use App\Models\ContractVersion;
use App\Models\Customer;
use App\Models\InspectionItem;
use App\Models\Invoice;
use App\Models\InvoiceLine;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\RentalContract;
use App\Models\Reservation;
use App\Models\ReservationStatusHistory;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleBlock;
use App\Models\VehicleInspection;
use App\Support\Contracts\CanonicalJson;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Database\Seeders\Concerns\PreventsDemoSeedingInProduction;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class RentFleetDemoV1HistoricalSeeder extends Seeder
{
    use PreventsDemoSeedingInProduction;

    public const DATASET_VERSION = 'rentfleet_demo_v1';

    public const HISTORICAL_CONTRACTS = 240;

    public const PAID_CONTRACTS = 120;

    public const PARTIALLY_PAID_CONTRACTS = 60;

    public const SOURCE_URL = 'https://www.data.gouv.fr/datasets/suivi-de-la-location-des-automobiles-mouvauto';

    public const SOURCE_LICENSE = 'Licence Ouverte 2.0';

    private const MARKER = 'rentfleet_demo_v1|fictional=true|profile=mouvauto-open-data';

    private const FIRST_START_AT = '2025-02-01 08:00:00+00';

    private const SPAN_DAYS = 560;

    /**
     * Vingt-quatre profils représentatifs, ordonnés par distance, extraits des
     * 379 lignes MouvAuto où durée et distance sont toutes deux renseignées.
     * Les entiers évitent les flottants : dixièmes d'heure et centièmes de km.
     *
     * @var list<array{duration_tenths: int, distance_hundredths: int}>
     */
    private const SOURCE_PROFILES = [
        ['duration_tenths' => 10, 'distance_hundredths' => 100],
        ['duration_tenths' => 20, 'distance_hundredths' => 200],
        ['duration_tenths' => 30, 'distance_hundredths' => 300],
        ['duration_tenths' => 45, 'distance_hundredths' => 450],
        ['duration_tenths' => 60, 'distance_hundredths' => 600],
        ['duration_tenths' => 10, 'distance_hundredths' => 1347],
        ['duration_tenths' => 10, 'distance_hundredths' => 1543],
        ['duration_tenths' => 15, 'distance_hundredths' => 3169],
        ['duration_tenths' => 20, 'distance_hundredths' => 3574],
        ['duration_tenths' => 15, 'distance_hundredths' => 4298],
        ['duration_tenths' => 15, 'distance_hundredths' => 4475],
        ['duration_tenths' => 20, 'distance_hundredths' => 4778],
        ['duration_tenths' => 35, 'distance_hundredths' => 5062],
        ['duration_tenths' => 15, 'distance_hundredths' => 5498],
        ['duration_tenths' => 15, 'distance_hundredths' => 5531],
        ['duration_tenths' => 20, 'distance_hundredths' => 5771],
        ['duration_tenths' => 40, 'distance_hundredths' => 6210],
        ['duration_tenths' => 20, 'distance_hundredths' => 7021],
        ['duration_tenths' => 95, 'distance_hundredths' => 8061],
        ['duration_tenths' => 45, 'distance_hundredths' => 9222],
        ['duration_tenths' => 45, 'distance_hundredths' => 10376],
        ['duration_tenths' => 75, 'distance_hundredths' => 11324],
        ['duration_tenths' => 60, 'distance_hundredths' => 13176],
        ['duration_tenths' => 115, 'distance_hundredths' => 29520],
    ];

    public function run(): void
    {
        $this->ensureDemoSeedingIsAllowed();

        $tenant = Tenant::where('slug', 'atlas-location-demo')->firstOrFail();

        app(TenantContext::class)->run($tenant, function () use ($tenant): void {
            if (Reservation::where('reservation_number', 'like', 'RES-DEMO-V1-%')->exists()) {
                return;
            }

            DB::transaction(fn () => $this->seedHistoricalSnapshot($tenant));
        });
    }

    private function seedHistoricalSnapshot(Tenant $tenant): void
    {
        $owner = User::where('tenant_id', $tenant->id)
            ->whereHas('role', fn ($query) => $query->where('slug', 'tenant-owner'))
            ->firstOrFail();
        $vehicles = Vehicle::with('category')->orderBy('id')->get();
        $customersByAgency = Customer::with('drivers')
            ->orderBy('id')
            ->get()
            ->filter(fn (Customer $customer): bool => $customer->drivers->isNotEmpty())
            ->groupBy(fn (Customer $customer): int => (int) $customer->agency_id);

        if ($vehicles->isEmpty()) {
            throw new RuntimeException('rentfleet_demo_v1 exige la flotte fictive du Lot 02.');
        }

        foreach ($vehicles as $vehicle) {
            if (! $customersByAgency->has((int) $vehicle->agency_id)) {
                throw new RuntimeException('rentfleet_demo_v1 exige un client-conducteur fictif par agence de la flotte.');
            }
        }

        $mileageByVehicle = $vehicles->values()->mapWithKeys(
            fn (Vehicle $vehicle, int $index): array => [$vehicle->id => 2000 + ($index * 350)]
        )->all();
        $firstStart = CarbonImmutable::parse(self::FIRST_START_AT);

        for ($index = 1; $index <= self::HISTORICAL_CONTRACTS; $index++) {
            $sequence = str_pad((string) $index, 4, '0', STR_PAD_LEFT);
            $vehicle = $vehicles[($index - 1) % $vehicles->count()];
            $agencyCustomers = $customersByAgency->get((int) $vehicle->agency_id)->values();
            $rotation = intdiv($index - 1, $vehicles->count());
            $profile = self::SOURCE_PROFILES[($index * 7) % count(self::SOURCE_PROFILES)];
            $isAnomaly = $index % 48 === 0;

            $startsAt = $firstStart
                ->addDays(intdiv(($index - 1) * self::SPAN_DAYS, self::HISTORICAL_CONTRACTS - 1))
                ->addHours(($index * 3) % 8);
            $actualStartAt = $startsAt->addMinutes(($index * 7) % 45);
            $billedDays = max(1, min(3, intdiv($profile['duration_tenths'] + 39, 40)));
            $expectedReturnAt = $startsAt->addDays($billedDays);
            $actualReturnAt = $expectedReturnAt->addHours($this->returnDeltaHours($index, $isAnomaly));
            $createdAt = $startsAt->subDays(7 + (($index * 11) % 24));
            $confirmedAt = $createdAt->addDay();
            $convertedAt = $confirmedAt->addHours(2);
            $acceptedAt = $startsAt->subDay();
            $returnedAt = $actualReturnAt->addMinutes(30);
            $eligibleCustomers = $agencyCustomers->filter(
                fn (Customer $candidate): bool => $candidate->drivers->contains(
                    fn ($candidateDriver): bool => $candidateDriver->licence_expires_at->endOfDay()->gte($expectedReturnAt)
                )
            )->values();
            if ($eligibleCustomers->isEmpty()) {
                throw new RuntimeException('rentfleet_demo_v1 exige un permis fictif valide pendant chaque cycle.');
            }
            $customer = $eligibleCustomers[$rotation % $eligibleCustomers->count()];
            $eligibleDrivers = $customer->drivers->filter(
                fn ($candidateDriver): bool => $candidateDriver->licence_expires_at->endOfDay()->gte($expectedReturnAt)
            )->sortBy('id')->values();
            $driver = $eligibleDrivers[$rotation % $eligibleDrivers->count()];

            $distanceKm = $this->distanceKm($profile['distance_hundredths'], $index, $isAnomaly);
            $startMileage = $mileageByVehicle[$vehicle->id];
            $returnMileage = $startMileage + $distanceKm;
            $mileageByVehicle[$vehicle->id] = $returnMileage + 25;
            $startFuel = 80 + (($index % 3) * 10);
            $fuelDrop = $isAnomaly ? min(70, $startFuel - 5) : min(45, intdiv($distanceKm + 11, 12) + ($index % 4));
            $returnFuel = max(5, $startFuel - $fuelDrop);
            $dailyRateMinor = $this->dailyRateMinor((string) $vehicle->category?->code);
            $totalMinor = $dailyRateMinor * $billedDays;

            $reservation = Reservation::forceCreate([
                'agency_id' => $vehicle->agency_id,
                'customer_id' => $customer->id,
                'driver_id' => $driver->id,
                'vehicle_category_id' => $vehicle->vehicle_category_id,
                'vehicle_id' => $vehicle->id,
                'reservation_number' => 'RES-DEMO-V1-'.$sequence,
                'starts_at' => $startsAt,
                'ends_at' => $expectedReturnAt,
                'status' => 'converted',
                'pricing_rule_id' => null,
                'billed_days' => $billedDays,
                'daily_rate' => $this->money($dailyRateMinor),
                'subtotal' => $this->money($totalMinor),
                'options_total' => '0.00',
                'total_amount' => $this->money($totalMinor),
                'deposit_amount' => '0.00',
                'currency' => 'MAD',
                'pricing_snapshot' => [
                    'dataset_version' => self::DATASET_VERSION,
                    'fictional' => true,
                    'source_profile' => [
                        'url' => self::SOURCE_URL,
                        'license' => self::SOURCE_LICENSE,
                        'duration_tenths' => $profile['duration_tenths'],
                        'distance_hundredths' => $profile['distance_hundredths'],
                    ],
                    'daily_rate' => $this->money($dailyRateMinor),
                    'billed_days' => $billedDays,
                ],
                'notes' => self::MARKER,
                'confirmed_at' => $confirmedAt,
                'created_by' => $owner->id,
                'created_at' => $createdAt,
                'updated_at' => $convertedAt,
            ]);
            $this->seedReservationHistory($reservation, $owner, $createdAt, $confirmedAt, $convertedAt);

            $contract = RentalContract::forceCreate([
                'agency_id' => $vehicle->agency_id,
                'reservation_id' => $reservation->id,
                'customer_id' => $customer->id,
                'vehicle_id' => $vehicle->id,
                'contract_number' => 'CTR-DEMO-V1-'.$sequence,
                'status' => RentalContractStatus::Returned,
                'expected_start_at' => $startsAt,
                'expected_return_at' => $expectedReturnAt,
                'actual_start_at' => $actualStartAt,
                'actual_return_at' => $actualReturnAt,
                'start_mileage' => $startMileage,
                'return_mileage' => $returnMileage,
                'start_fuel_level' => $this->percentage($startFuel),
                'return_fuel_level' => $this->percentage($returnFuel),
                'rental_subtotal' => $this->money($totalMinor),
                'additional_charges_total' => '0.00',
                'total_amount' => $this->money($totalMinor),
                'deposit_required' => '0.00',
                'currency' => 'MAD',
                'accepted_at' => $acceptedAt,
                'activated_at' => $actualStartAt,
                'returned_at' => $returnedAt,
                'created_by' => $owner->id,
                'created_at' => $convertedAt,
                'updated_at' => $returnedAt,
            ]);

            ContractDriver::forceCreate([
                'rental_contract_id' => $contract->id,
                'customer_id' => $customer->id,
                'driver_id' => $driver->id,
                'is_primary' => true,
                'authorization_snapshot' => [
                    'driver_id' => $driver->id,
                    'licence_expires_at' => $driver->licence_expires_at->toDateString(),
                    'dataset_version' => self::DATASET_VERSION,
                ],
                'created_at' => $convertedAt,
                'updated_at' => $convertedAt,
            ]);
            $this->seedContractVersion($contract, $customer, $vehicle, $owner, $acceptedAt, $billedDays, $dailyRateMinor);
            $this->seedContractHistory($contract, $owner, $convertedAt, $acceptedAt, $actualStartAt, $actualReturnAt, $returnedAt);

            VehicleBlock::forceCreate([
                'agency_id' => $vehicle->agency_id,
                'vehicle_id' => $vehicle->id,
                'reservation_id' => $reservation->id,
                'rental_contract_id' => $contract->id,
                'block_type' => 'contract',
                'starts_at' => $startsAt,
                'ends_at' => $actualReturnAt->greaterThan($expectedReturnAt) ? $actualReturnAt : $expectedReturnAt,
                'status' => 'released',
                'reason' => self::MARKER,
                'created_by' => $owner->id,
                'released_at' => $returnedAt,
                'created_at' => $confirmedAt,
                'updated_at' => $returnedAt,
            ]);

            $this->seedInspection($contract, $vehicle, $owner, 'departure', $actualStartAt, $startMileage, $startFuel);
            $this->seedInspection($contract, $vehicle, $owner, 'return', $actualReturnAt, $returnMileage, $returnFuel);

            if ($index <= self::PAID_CONTRACTS + self::PARTIALLY_PAID_CONTRACTS) {
                $this->seedFinance($contract, $customer, $owner, $index, $totalMinor, $returnedAt);
            }
        }
    }

    private function seedReservationHistory(
        Reservation $reservation,
        User $owner,
        CarbonImmutable $createdAt,
        CarbonImmutable $confirmedAt,
        CarbonImmutable $convertedAt,
    ): void {
        foreach ([
            [null, 'draft', $createdAt, 'Import historique fictif'],
            ['draft', 'confirmed', $confirmedAt, 'Confirmation historique fictive'],
            ['confirmed', 'converted', $convertedAt, 'Conversion historique fictive'],
        ] as [$from, $to, $at, $reason]) {
            ReservationStatusHistory::forceCreate([
                'reservation_id' => $reservation->id,
                'from_status' => $from,
                'to_status' => $to,
                'reason' => $reason,
                'changed_by' => $owner->id,
                'created_at' => $at,
            ]);
        }
    }

    private function seedContractVersion(
        RentalContract $contract,
        Customer $customer,
        Vehicle $vehicle,
        User $owner,
        CarbonImmutable $acceptedAt,
        int $billedDays,
        int $dailyRateMinor,
    ): void {
        $snapshot = [
            'terms_snapshot' => ['dataset_version' => self::DATASET_VERSION, 'fictional' => true, 'currency' => 'MAD'],
            'pricing_snapshot' => ['billed_days' => $billedDays, 'daily_rate' => $this->money($dailyRateMinor)],
            'customer_snapshot' => ['id' => $customer->id, 'display_name' => $customer->displayName()],
            'vehicle_snapshot' => ['id' => $vehicle->id, 'registration_number' => $vehicle->registration_number, 'brand' => $vehicle->brand, 'model' => $vehicle->model],
        ];
        $contentHash = $this->hash($snapshot);
        $version = ContractVersion::forceCreate([
            'agency_id' => $contract->agency_id,
            'rental_contract_id' => $contract->id,
            'document_id' => null,
            'version_number' => 1,
            'terms_snapshot' => $snapshot['terms_snapshot'],
            'pricing_snapshot' => $snapshot['pricing_snapshot'],
            'customer_snapshot' => $snapshot['customer_snapshot'],
            'vehicle_snapshot' => $snapshot['vehicle_snapshot'],
            'content_hash' => $contentHash,
            'change_reason' => 'Version initiale importée — '.self::DATASET_VERSION,
            'created_by' => $owner->id,
            'locked_at' => $acceptedAt,
            'created_at' => $contract->created_at,
        ]);
        $contract->forceFill(['current_version_id' => $version->id])->saveQuietly();

        $consentVersion = (string) config('rentals.consent_text_version');
        $acceptance = [
            'contract_version_hash' => $contentHash,
            'accepted_by_name' => $customer->displayName(),
            'acceptance_method' => 'typed_name',
            'consent_text_version' => $consentVersion,
            'accepted_at' => $acceptedAt->toIso8601String(),
        ];
        ContractAcceptance::forceCreate([
            'rental_contract_id' => $contract->id,
            'contract_version_id' => $version->id,
            'accepted_by_name' => $acceptance['accepted_by_name'],
            'acceptance_method' => $acceptance['acceptance_method'],
            'consent_text_version' => $consentVersion,
            'accepted_at' => $acceptedAt,
            'ip_address' => null,
            'user_agent' => 'RentFleet Demo Seeder v1',
            'signature_document_id' => null,
            'content_hash' => $this->hash($acceptance),
            'created_by' => $owner->id,
            'created_at' => $acceptedAt,
        ]);
    }

    private function seedContractHistory(
        RentalContract $contract,
        User $owner,
        CarbonImmutable $createdAt,
        CarbonImmutable $acceptedAt,
        CarbonImmutable $actualStartAt,
        CarbonImmutable $actualReturnAt,
        CarbonImmutable $returnedAt,
    ): void {
        foreach ([
            [null, 'draft', $createdAt, 'Import historique fictif'],
            ['draft', 'ready', $acceptedAt->subHour(), 'Prérequis historiques validés'],
            ['ready', 'accepted', $acceptedAt, 'Acceptation historique fictive'],
            ['accepted', 'active', $actualStartAt, 'Départ historique fictif'],
            ['active', 'return_pending', $actualReturnAt, 'Inspection de retour historique'],
            ['return_pending', 'returned', $returnedAt, 'Retour historique finalisé'],
        ] as [$from, $to, $at, $reason]) {
            ContractStatusHistory::forceCreate([
                'rental_contract_id' => $contract->id,
                'from_status' => $from,
                'to_status' => $to,
                'reason' => $reason,
                'changed_by' => $owner->id,
                'created_at' => $at,
            ]);
        }
    }

    private function seedInspection(
        RentalContract $contract,
        Vehicle $vehicle,
        User $owner,
        string $type,
        CarbonImmutable $inspectedAt,
        int $mileage,
        int $fuel,
    ): void {
        $inspection = VehicleInspection::forceCreate([
            'agency_id' => $contract->agency_id,
            'rental_contract_id' => $contract->id,
            'vehicle_id' => $vehicle->id,
            'inspection_type' => $type,
            'status' => 'draft',
            'inspected_at' => $inspectedAt,
            'mileage' => $mileage,
            'fuel_level' => $this->percentage($fuel),
            'notes' => self::MARKER,
            'created_by' => $owner->id,
            'created_at' => $inspectedAt,
            'updated_at' => $inspectedAt,
        ]);

        foreach ([
            ['body', 'Carrosserie'],
            ['interior', 'Habitacle'],
            ['tyres', 'Pneus'],
        ] as [$code, $label]) {
            InspectionItem::forceCreate([
                'vehicle_inspection_id' => $inspection->id,
                'item_code' => $code,
                'label' => $label,
                'condition' => 'good',
                'notes' => null,
                'created_at' => $inspectedAt,
                'updated_at' => $inspectedAt,
            ]);
        }

        $inspection->forceFill([
            'status' => 'completed',
            'completed_by' => $owner->id,
            'completed_at' => $inspectedAt,
            'updated_at' => $inspectedAt,
        ])->save();
    }

    private function seedFinance(
        RentalContract $contract,
        Customer $customer,
        User $owner,
        int $index,
        int $totalMinor,
        CarbonImmutable $returnedAt,
    ): void {
        $sequence = str_pad((string) $index, 4, '0', STR_PAD_LEFT);
        $issuedAt = $returnedAt->addHour();
        $postedAt = $issuedAt->addHours(2);
        $isPaid = $index <= self::PAID_CONTRACTS;
        $paidMinor = $isPaid ? $totalMinor : intdiv($totalMinor, 2);
        $balanceMinor = $totalMinor - $paidMinor;
        $invoiceSnapshot = [
            'customer' => ['id' => $customer->id, 'name' => $customer->displayName(), 'city' => $customer->city],
            'contract' => ['id' => $contract->id, 'number' => $contract->contract_number, 'returned_at' => $returnedAt->toIso8601String(), 'currency' => 'MAD'],
            'total' => $this->money($totalMinor),
            'dataset_version' => self::DATASET_VERSION,
        ];
        $invoice = Invoice::forceCreate([
            'agency_id' => $contract->agency_id,
            'rental_contract_id' => $contract->id,
            'customer_id' => $customer->id,
            'invoice_number' => 'INV-DEMO-V1-'.$sequence,
            'status' => 'draft',
            'currency' => 'MAD',
            'tax_mode' => 'none',
            'tax_rate' => '0.0000',
            'subtotal' => $this->money($totalMinor),
            'tax_amount' => '0.00',
            'total_amount' => $this->money($totalMinor),
            'paid_amount' => '0.00',
            'balance_due' => $this->money($totalMinor),
            'customer_snapshot' => $invoiceSnapshot['customer'],
            'contract_snapshot' => $invoiceSnapshot['contract'],
            'content_hash' => $this->hash($invoiceSnapshot),
            'created_by' => $owner->id,
            'created_at' => $issuedAt->subHour(),
            'updated_at' => $issuedAt->subHour(),
        ]);
        InvoiceLine::forceCreate([
            'invoice_id' => $invoice->id,
            'source_type' => 'contract',
            'source_id' => $contract->id,
            'line_type' => 'rental',
            'description' => 'Location historique fictive '.$contract->contract_number,
            'quantity' => '1.00',
            'unit_amount' => $this->money($totalMinor),
            'subtotal' => $this->money($totalMinor),
            'tax_rate' => '0.0000',
            'tax_amount' => '0.00',
            'total_amount' => $this->money($totalMinor),
            'sort_order' => 1,
            'created_at' => $issuedAt,
        ]);
        $invoice->forceFill([
            'status' => $isPaid ? 'paid' : 'partially_paid',
            'issued_at' => $issuedAt,
            'due_at' => $issuedAt->addDays(7),
            'issued_by' => $owner->id,
            'paid_amount' => $this->money($paidMinor),
            'balance_due' => $this->money($balanceMinor),
            'updated_at' => $postedAt,
        ])->save();

        $payment = Payment::forceCreate([
            'agency_id' => $contract->agency_id,
            'rental_contract_id' => $contract->id,
            'customer_id' => $customer->id,
            'payment_number' => 'PAY-DEMO-V1-'.$sequence,
            'direction' => 'incoming',
            'payment_method' => ['cash', 'card', 'bank_transfer'][$index % 3],
            'status' => 'posted',
            'amount' => $this->money($paidMinor),
            'currency' => 'MAD',
            'external_reference' => 'DEMO-V1-'.$sequence,
            'idempotency_key' => 'rentfleet-demo-v1-payment-'.$sequence,
            'paid_at' => $issuedAt->addHour(),
            'posted_at' => $postedAt,
            'notes' => self::MARKER,
            'created_by' => $owner->id,
            'posted_by' => $owner->id,
            'created_at' => $issuedAt->addHour(),
            'updated_at' => $postedAt,
        ]);
        PaymentAllocation::forceCreate([
            'agency_id' => $contract->agency_id,
            'customer_id' => $customer->id,
            'currency' => 'MAD',
            'payment_id' => $payment->id,
            'invoice_id' => $invoice->id,
            'amount' => $this->money($paidMinor),
            'created_at' => $postedAt,
        ]);

        $contractFields = [
            'invoice_id' => $invoice->id,
            'amount_paid' => $this->money($paidMinor),
            'balance_due' => $this->money($balanceMinor),
            'updated_at' => $postedAt,
        ];
        if ($isPaid) {
            $closedAt = $postedAt->addHour();
            $contractFields = [
                ...$contractFields,
                'status' => RentalContractStatus::Closed,
                'financially_settled_at' => $closedAt,
                'closed_at' => $closedAt,
                'closed_by' => $owner->id,
                'updated_at' => $closedAt,
            ];
        }
        $contract->forceFill($contractFields)->save();

        if ($isPaid) {
            ContractStatusHistory::forceCreate([
                'rental_contract_id' => $contract->id,
                'from_status' => 'returned',
                'to_status' => 'closed',
                'reason' => 'Clôture financière historique fictive',
                'changed_by' => $owner->id,
                'created_at' => $contract->closed_at,
            ]);
        }
    }

    private function distanceKm(int $distanceHundredths, int $index, bool $isAnomaly): int
    {
        $scalePercent = 85 + (($index * 17) % 31);
        $scaledHundredths = intdiv(($distanceHundredths * $scalePercent) + 50, 100);
        $distance = max(1, intdiv($scaledHundredths + 50, 100));

        return $isAnomaly ? $distance + 450 : $distance;
    }

    private function returnDeltaHours(int $index, bool $isAnomaly): int
    {
        if ($isAnomaly) {
            return 36 + ((intdiv($index, 48) % 4) * 12);
        }

        return [-4, -1, 0, 1, 3][$index % 5];
    }

    private function dailyRateMinor(string $categoryCode): int
    {
        return match ($categoryCode) {
            'COM' => 47_500,
            'SUV' => 65_000,
            'PRE' => 90_000,
            default => 32_500,
        };
    }

    private function money(int $minor): string
    {
        return intdiv($minor, 100).'.'.str_pad((string) ($minor % 100), 2, '0', STR_PAD_LEFT);
    }

    private function percentage(int $value): string
    {
        return $value.'.00';
    }

    /** @param array<string, mixed> $value */
    private function hash(array $value): string
    {
        return app(CanonicalJson::class)->hash($value);
    }
}
