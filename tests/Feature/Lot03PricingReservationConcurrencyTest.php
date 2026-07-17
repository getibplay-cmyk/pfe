<?php

namespace Tests\Feature;

use App\Actions\Customers\CreateCustomer;
use App\Actions\Customers\CreateDriver;
use App\Actions\Pricing\CalculateReservationQuote;
use App\Actions\Pricing\ResolvePricingRule;
use App\Actions\Reservations\CancelReservation;
use App\Actions\Reservations\ConfirmReservation;
use App\Actions\Reservations\CreateReservation;
use App\Actions\Reservations\GenerateReservationNumber;
use App\Actions\Reservations\SearchAvailableVehicles;
use App\Actions\Vehicles\CreateVehicle;
use App\Enums\CustomerType;
use App\Enums\ReservationStatus;
use App\Enums\VehicleBlockStatus;
use App\Enums\VehicleBlockType;
use App\Enums\VehicleOperationalStatus;
use App\Enums\VerificationStatus;
use App\Exceptions\VehicleUnavailableException;
use App\Models\Agency;
use App\Models\PricingRule;
use App\Models\Reservation;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Models\VehicleBlock;
use App\Models\VehicleCategory;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesPermissionsSeeder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class Lot03PricingReservationConcurrencyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesPermissionsSeeder::class);
    }

    public function test_pricing_rules_are_tenant_isolated_and_agency_rule_wins_before_general_rule(): void
    {
        $a = $this->fixture();
        $b = $this->fixture();
        $agencyRule = $this->inTenant($a, fn () => PricingRule::create($this->pricingData($a, ['agency_id' => $a['agency']->id, 'daily_rate' => '250.00', 'priority' => -10, 'name' => 'Agence'])));

        $resolved = $this->inTenant($a, fn () => app(ResolvePricingRule::class)->handle($a['agency']->id, $a['category']->id, now()));
        $this->assertSame($agencyRule->id, $resolved->id);
        $this->assertSame(2, $this->inTenant($a, fn () => PricingRule::count()));
        $this->assertSame(1, $this->inTenant($b, fn () => PricingRule::count()));
    }

    public function test_pricing_priority_validity_and_id_order_are_deterministic(): void
    {
        $f = $this->fixture();
        $older = $this->inTenant($f, fn () => PricingRule::create($this->pricingData($f, ['agency_id' => $f['agency']->id, 'priority' => 10, 'valid_from' => today()->subDays(10), 'name' => 'Ancienne'])));
        $newer = $this->inTenant($f, fn () => PricingRule::create($this->pricingData($f, ['agency_id' => $f['agency']->id, 'priority' => 10, 'valid_from' => today()->subDay(), 'name' => 'Nouvelle'])));
        $latestId = $this->inTenant($f, fn () => PricingRule::create($this->pricingData($f, ['agency_id' => $f['agency']->id, 'priority' => 10, 'valid_from' => today()->subDay(), 'name' => 'Dernier ID'])));

        $resolved = $this->inTenant($f, fn () => app(ResolvePricingRule::class)->handle($f['agency']->id, $f['category']->id, now()));
        $this->assertNotSame($older->id, $resolved->id);
        $this->assertNotSame($newer->id, $resolved->id);
        $this->assertSame($latestId->id, $resolved->id);
    }

    public function test_quote_uses_decimal_strings_one_day_minimum_and_ceiling_above_24_hours(): void
    {
        $f = $this->fixture();
        $calculator = app(CalculateReservationQuote::class);
        $start = CarbonImmutable::parse('2026-08-01 10:00:00+01');
        $oneDay = $calculator->handle($f['pricing'], $start, $start->addHour());
        $twoDays = $calculator->handle($f['pricing'], $start, $start->addHours(25), '10.25');

        $this->assertSame(1, $oneDay['billed_days']);
        $this->assertSame('400.00', $oneDay['total_amount']);
        $this->assertSame(2, $twoDays['billed_days']);
        $this->assertSame('810.25', $twoDays['total_amount']);
        $this->assertIsString($twoDays['daily_rate']);
        $this->assertIsString($twoDays['subtotal']);
        $this->assertFalse(is_float($twoDays['total_amount']));
    }

    public function test_database_rejects_end_before_or_equal_to_start(): void
    {
        $f = $this->fixture();
        $this->expectException(QueryException::class);
        DB::table('reservations')->insert($this->rawReservation($f, now(), now()));
    }

    public function test_confirmation_rejects_non_active_vehicle_and_expired_driver(): void
    {
        $f = $this->fixture();
        $reservation = $this->reservation($f, now()->addDays(2)->toImmutable());
        $this->inTenant($f, fn () => $f['vehicle']->forceFill(['operational_status' => VehicleOperationalStatus::Maintenance])->save());
        try {
            $this->inTenant($f, fn () => app(ConfirmReservation::class)->handle($reservation, $f['user']->id));
            $this->fail('Un véhicule non actif a été confirmé.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('vehicle_id', $exception->errors());
        }
        $this->inTenant($f, function () use ($f) {
            $f['vehicle']->forceFill(['operational_status' => VehicleOperationalStatus::Active])->save();
            $f['driver']->forceFill(['licence_expires_at' => today()->subDay()])->save();
        });
        try {
            $this->inTenant($f, fn () => app(ConfirmReservation::class)->handle($reservation, $f['user']->id));
            $this->fail('Un permis expiré a été accepté.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('driver_id', $exception->errors());
        }
    }

    public function test_foreign_agency_category_customer_and_driver_are_refused(): void
    {
        $a = $this->fixture();
        $b = $this->fixture();
        foreach ([['agency_id' => $b['agency']->id], ['vehicle_category_id' => $b['category']->id], ['customer_id' => $b['customer']->id], ['driver_id' => $b['driver']->id]] as $override) {
            try {
                $this->inTenant($a, fn () => app(CreateReservation::class)->handle([...$this->reservationData($a, now()->addDays(2)->toImmutable()), ...$override], $a['user']->id));
                $this->fail('Une relation étrangère a été acceptée.');
            } catch (ModelNotFoundException|ValidationException) {
                $this->assertTrue(true);
            }
        }
    }

    public function test_confirmation_creates_active_block_history_audit_and_frozen_snapshot(): void
    {
        $f = $this->fixture();
        $reservation = $this->reservation($f, now()->addDays(2)->toImmutable());
        $confirmed = $this->inTenant($f, fn () => app(ConfirmReservation::class)->handle($reservation, $f['user']->id));
        $snapshot = $confirmed->pricing_snapshot;
        $this->inTenant($f, fn () => $f['pricing']->forceFill(['daily_rate' => '999.00'])->save());

        $this->assertSame(ReservationStatus::Confirmed, $confirmed->status);
        $this->assertDatabaseHas('vehicle_blocks', ['reservation_id' => $confirmed->id, 'status' => 'active']);
        $this->assertDatabaseHas('reservation_status_histories', ['reservation_id' => $confirmed->id, 'to_status' => 'confirmed']);
        $this->assertDatabaseHas('audit_logs', ['auditable_id' => $confirmed->id, 'action' => 'reservation.confirmed']);
        $this->assertSame($snapshot, $this->inTenant($f, fn () => $confirmed->refresh()->pricing_snapshot));
    }

    public function test_exact_partial_contained_and_enclosing_overlaps_become_business_errors_without_partial_confirmation(): void
    {
        $f = $this->fixture();
        $baseStart = now()->addDays(5)->startOfHour()->toImmutable();
        $this->activeBlock($f, $baseStart, $baseStart->addHours(4));
        $periods = [
            [$baseStart, $baseStart->addHours(4)],
            [$baseStart->subHour(), $baseStart->addHour()],
            [$baseStart->addHours(3), $baseStart->addHours(5)],
            [$baseStart->addHour(), $baseStart->addHours(2)],
            [$baseStart->subHour(), $baseStart->addHours(5)],
        ];
        foreach ($periods as [$start, $end]) {
            $reservation = $this->reservation($f, $start, ['ends_at' => $end]);
            try {
                $this->inTenant($f, fn () => app(ConfirmReservation::class)->handle($reservation, $f['user']->id));
                $this->fail('Un chevauchement a été accepté.');
            } catch (VehicleUnavailableException $exception) {
                $this->assertSame('Véhicule déjà indisponible sur cette période.', $exception->getMessage());
                $this->assertSame(ReservationStatus::Draft, $this->inTenant($f, fn () => $reservation->refresh()->status));
            }
        }
        $this->assertSame(1, VehicleBlock::withoutGlobalScopes()->where('tenant_id', $f['tenant']->id)->where('status', 'active')->count());
    }

    public function test_consecutive_slots_and_same_period_for_different_vehicle_are_allowed(): void
    {
        $f = $this->fixture();
        $start = now()->addDays(5)->startOfHour()->toImmutable();
        $this->activeBlock($f, $start, $start->addHours(2));
        $consecutive = $this->reservation($f, $start->addHours(2), ['ends_at' => $start->addHours(4)]);
        $this->inTenant($f, fn () => app(ConfirmReservation::class)->handle($consecutive, $f['user']->id));
        $otherVehicle = $this->inTenant($f, fn () => app(CreateVehicle::class)->handle($this->vehicleData($f, 'OTHER-'.uniqid()), $f['user']->id));
        $other = $this->reservation($f, $start, ['ends_at' => $start->addHours(2), 'vehicle_id' => $otherVehicle->id]);
        $this->inTenant($f, fn () => app(ConfirmReservation::class)->handle($other, $f['user']->id));

        $this->assertSame(3, VehicleBlock::withoutGlobalScopes()->where('tenant_id', $f['tenant']->id)->where('status', 'active')->count());
    }

    public function test_same_period_for_different_tenants_is_allowed(): void
    {
        $a = $this->fixture();
        $b = $this->fixture();
        $start = now()->addDays(4)->startOfHour()->toImmutable();
        $this->activeBlock($a, $start, $start->addHours(2));
        $this->activeBlock($b, $start, $start->addHours(2));
        $this->assertSame(2, VehicleBlock::withoutGlobalScopes()->where('status', 'active')->count());
    }

    public function test_postgresql_exclusion_constraint_blocks_direct_invalid_insert_with_23p01(): void
    {
        $f = $this->fixture();
        $start = now()->addDays(4)->startOfHour()->toImmutable();
        $this->activeBlock($f, $start, $start->addHours(2));
        try {
            DB::table('vehicle_blocks')->insert($this->rawBlock($f, $start->addHour(), $start->addHours(3)));
            $this->fail('La contrainte GiST n’a pas bloqué l’insertion directe.');
        } catch (QueryException $exception) {
            $this->assertSame('23P01', $exception->getCode());
        }
    }

    public function test_gist_constraint_definition_and_extension_are_active(): void
    {
        $constraint = DB::selectOne('SELECT conname, pg_get_constraintdef(oid) AS definition FROM pg_constraint WHERE conname = ?', ['vehicle_blocks_no_active_overlap_excl']);
        $extension = DB::selectOne('SELECT extname FROM pg_extension WHERE extname = ?', ['btree_gist']);

        $this->assertSame('vehicle_blocks_no_active_overlap_excl', $constraint->conname);
        $this->assertStringContainsString('EXCLUDE USING gist', $constraint->definition);
        $this->assertStringContainsString("'[)'", $constraint->definition);
        $this->assertStringContainsString('status', $constraint->definition);
        $this->assertStringContainsString("'active'", $constraint->definition);
        $this->assertSame('btree_gist', $extension->extname);
    }

    public function test_cancellation_releases_block_and_makes_new_confirmation_possible(): void
    {
        $f = $this->fixture();
        $start = now()->addDays(5)->startOfHour()->toImmutable();
        $first = $this->reservation($f, $start);
        $this->inTenant($f, fn () => app(ConfirmReservation::class)->handle($first, $f['user']->id));
        $this->inTenant($f, fn () => app(CancelReservation::class)->handle($first, 'Changement client', $f['user']->id));
        $second = $this->reservation($f, $start);
        $this->inTenant($f, fn () => app(ConfirmReservation::class)->handle($second, $f['user']->id));

        $this->assertDatabaseHas('vehicle_blocks', ['reservation_id' => $first->id, 'status' => 'released']);
        $this->assertDatabaseHas('vehicle_blocks', ['reservation_id' => $second->id, 'status' => 'active']);
    }

    public function test_confirmed_reservation_cannot_be_physically_deleted_even_by_direct_sql(): void
    {
        $f = $this->fixture();
        $reservation = $this->reservation($f, now()->addDays(3)->toImmutable());
        $this->inTenant($f, fn () => app(ConfirmReservation::class)->handle($reservation, $f['user']->id));
        $this->expectException(QueryException::class);
        DB::table('reservations')->where('id', $reservation->id)->delete();
    }

    public function test_tenant_scoped_counter_generates_unique_numbers_and_tenant_injection_is_rejected(): void
    {
        $f = $this->fixture('agency-manager');
        $numbers = $this->inTenant($f, fn () => collect(range(1, 20))->map(fn () => app(GenerateReservationNumber::class)->handle(2026)));
        $this->assertCount(20, $numbers->unique());
        $this->assertSame('RES-2026-000001', $numbers->first());
        $this->actingAs($f['user'])->post(route('reservations.store'), [...$this->reservationData($f, now()->addDays(2)->toImmutable()), 'tenant_id' => 999])->assertSessionHasErrors('tenant_id');
    }

    public function test_agency_manager_is_limited_to_own_agency(): void
    {
        $f = $this->fixture('agency-manager');
        $otherAgency = $this->inTenant($f, fn () => Agency::factory()->create());
        [$otherVehicle, $otherCustomer, $otherDriver] = app(TenantContext::class)->run($f['tenant'], function () use ($f, $otherAgency) {
            $vehicle = app(CreateVehicle::class)->handle([...$this->vehicleData($f, 'FOREIGN-'.uniqid()), 'agency_id' => $otherAgency->id], $f['user']->id);
            $customer = app(CreateCustomer::class)->handle(['agency_id' => $otherAgency->id, 'customer_type' => CustomerType::Individual, 'first_name' => 'Client', 'last_name' => 'Autre agence', 'verification_status' => VerificationStatus::Verified]);
            $driver = app(CreateDriver::class)->handle($customer, ['first_name' => 'Conducteur', 'last_name' => 'Autre agence', 'licence_number' => 'FOREIGN-'.uniqid(), 'licence_expires_at' => today()->addYear(), 'verification_status' => VerificationStatus::Verified, 'is_primary' => true]);

            return [$vehicle, $customer, $driver];
        });
        $own = $this->reservation($f, now()->addDays(2)->toImmutable());
        $foreign = app(TenantContext::class)->run($f['tenant'], fn () => Reservation::create([...$this->rawReservation($f, now()->addDays(4), now()->addDays(5)), 'agency_id' => $otherAgency->id, 'customer_id' => $otherCustomer->id, 'driver_id' => $otherDriver->id, 'vehicle_id' => $otherVehicle->id, 'reservation_number' => 'RES-2026-999999']));

        $this->actingAs($f['user'])->get(route('reservations.index'))->assertOk()->assertSee($own->reservation_number)->assertDontSee($foreign->reservation_number);
        $this->actingAs($f['user'])->get(route('reservations.show', $foreign))->assertForbidden();
        $this->actingAs($f['user'])->get(route('availability.index'))->assertOk();
        $this->actingAs($f['user'])->get(route('pricing-rules.edit', $f['pricing']))->assertForbidden();
        $this->actingAs($f['user'])->post(route('pricing-rules.store'), $this->pricingData($f))->assertForbidden();
    }

    public function test_availability_search_excludes_active_blocks_and_keeps_operational_status_distinct(): void
    {
        $f = $this->fixture();
        $start = now()->addDays(3)->startOfHour()->toImmutable();
        $this->activeBlock($f, $start, $start->addHours(2));
        $during = $this->inTenant($f, fn () => app(SearchAvailableVehicles::class)->query($f['agency']->id, $start, $start->addHour(), $f['category']->id)->pluck('id'));
        $after = $this->inTenant($f, fn () => app(SearchAvailableVehicles::class)->query($f['agency']->id, $start->addHours(2), $start->addHours(3), $f['category']->id)->pluck('id'));

        $this->assertNotContains($f['vehicle']->id, $during);
        $this->assertContains($f['vehicle']->id, $after);
        $this->assertSame(VehicleOperationalStatus::Active, $this->inTenant($f, fn () => $f['vehicle']->refresh()->operational_status));
    }

    public function test_expiration_command_expires_due_pending_and_suite_uses_only_postgresql_test_database(): void
    {
        $f = $this->fixture();
        $reservation = $this->reservation($f, now()->addDays(2)->toImmutable(), ['status' => 'pending', 'expires_at' => now()->subMinute()]);
        $this->artisan('reservations:expire-pending')->assertSuccessful();

        $this->assertSame(ReservationStatus::Expired, $this->inTenant($f, fn () => $reservation->refresh()->status));
        $this->assertDatabaseHas('reservation_status_histories', ['reservation_id' => $reservation->id, 'to_status' => 'expired']);
        $this->assertSame('pgsql', DB::connection()->getDriverName());
        $this->assertSame('rentfleet_test', DB::connection()->getDatabaseName());
    }

    private function fixture(string $roleSlug = 'tenant-owner'): array
    {
        $tenant = Tenant::factory()->create();
        $agency = app(TenantContext::class)->run($tenant, fn () => Agency::factory()->create());
        $role = Role::where('slug', $roleSlug)->firstOrFail();
        $user = User::factory()->create(['tenant_id' => $tenant->id, 'agency_id' => $roleSlug === 'tenant-owner' ? null : $agency->id, 'role_id' => $role->id]);
        $fixture = ['tenant' => $tenant, 'agency' => $agency, 'user' => $user];

        return app(TenantContext::class)->run($tenant, function () use ($fixture) {
            $category = VehicleCategory::create(['code' => 'CAT-'.uniqid(), 'name' => 'Catégorie test', 'is_active' => true]);
            $vehicle = app(CreateVehicle::class)->handle($this->vehicleData([...$fixture, 'category' => $category], 'REG-'.uniqid()), $fixture['user']->id);
            $customer = app(CreateCustomer::class)->handle(['agency_id' => $fixture['agency']->id, 'customer_type' => CustomerType::Individual, 'first_name' => 'Client', 'last_name' => 'Test', 'verification_status' => VerificationStatus::Verified]);
            $driver = app(CreateDriver::class)->handle($customer, ['first_name' => 'Driver', 'last_name' => 'Test', 'licence_number' => 'LIC-'.uniqid(), 'licence_expires_at' => today()->addYears(2), 'verification_status' => VerificationStatus::Verified, 'is_primary' => true]);
            $pricing = PricingRule::create($this->pricingData([...$fixture, 'category' => $category]));

            return [...$fixture, 'category' => $category, 'vehicle' => $vehicle, 'customer' => $customer, 'driver' => $driver, 'pricing' => $pricing];
        });
    }

    private function pricingData(array $f, array $overrides = []): array
    {
        return [...['agency_id' => null, 'vehicle_category_id' => $f['category']->id, 'name' => 'Tarif test', 'daily_rate' => '400.00', 'deposit_amount' => '3000.00', 'minimum_days' => 1, 'maximum_days' => 30, 'valid_from' => today()->subYear(), 'valid_to' => null, 'priority' => 0, 'currency' => 'MAD', 'conditions' => [], 'is_active' => true, 'created_by' => $f['user']->id], ...$overrides];
    }

    private function vehicleData(array $f, string $registration): array
    {
        return ['agency_id' => $f['agency']->id, 'vehicle_category_id' => $f['category']->id, 'registration_number' => $registration, 'brand' => 'Dacia', 'model' => 'Logan', 'production_year' => 2025, 'fuel_type' => 'diesel', 'transmission' => 'manual', 'current_mileage' => 1000];
    }

    private function reservation(array $f, CarbonImmutable $start, array $overrides = []): Reservation
    {
        return $this->inTenant($f, fn () => app(CreateReservation::class)->handle([...$this->reservationData($f, $start), ...$overrides], $f['user']->id));
    }

    private function reservationData(array $f, CarbonImmutable $start): array
    {
        return ['agency_id' => $f['agency']->id, 'customer_id' => $f['customer']->id, 'driver_id' => $f['driver']->id, 'vehicle_category_id' => $f['category']->id, 'vehicle_id' => $f['vehicle']->id, 'starts_at' => $start, 'ends_at' => $start->addDay(), 'status' => 'draft'];
    }

    private function activeBlock(array $f, CarbonImmutable $start, CarbonImmutable $end): VehicleBlock
    {
        return $this->inTenant($f, fn () => VehicleBlock::create(['agency_id' => $f['agency']->id, 'vehicle_id' => $f['vehicle']->id, 'block_type' => VehicleBlockType::Manual, 'starts_at' => $start, 'ends_at' => $end, 'status' => VehicleBlockStatus::Active, 'reason' => 'Bloc de test', 'created_by' => $f['user']->id]));
    }

    private function rawBlock(array $f, CarbonImmutable $start, CarbonImmutable $end): array
    {
        return ['tenant_id' => $f['tenant']->id, 'agency_id' => $f['agency']->id, 'vehicle_id' => $f['vehicle']->id, 'reservation_id' => null, 'block_type' => 'manual', 'starts_at' => $start, 'ends_at' => $end, 'status' => 'active', 'reason' => 'Bloc SQL de test', 'created_by' => $f['user']->id, 'created_at' => now(), 'updated_at' => now()];
    }

    private function rawReservation(array $f, $start, $end): array
    {
        return ['tenant_id' => $f['tenant']->id, 'agency_id' => $f['agency']->id, 'customer_id' => $f['customer']->id, 'driver_id' => $f['driver']->id, 'vehicle_category_id' => $f['category']->id, 'vehicle_id' => $f['vehicle']->id, 'reservation_number' => 'RES-RAW-'.uniqid(), 'starts_at' => $start, 'ends_at' => $end, 'status' => 'draft', 'subtotal' => 0, 'options_total' => 0, 'total_amount' => 0, 'deposit_amount' => 0, 'currency' => 'MAD', 'pricing_snapshot' => '{}', 'created_by' => $f['user']->id, 'created_at' => now(), 'updated_at' => now()];
    }

    private function inTenant(array $f, callable $callback): mixed
    {
        return app(TenantContext::class)->run($f['tenant'], $callback, $f['agency']->id);
    }
}
