<?php

namespace Tests\Feature;

use App\Actions\Rentals\ActivateRentalContract;
use App\Models\ContractExtension;
use App\Models\CustomerPortalAccess;
use App\Models\VehicleBlock;
use App\Support\Rentals\ActiveCustomerPortal;
use App\Support\Rentals\GuidedInspection;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsRentalScenario;
use Tests\TestCase;

class ActiveCustomerPortalTest extends TestCase
{
    use BuildsRentalScenario, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesPermissionsSeeder::class);
        $this->travelTo(CarbonImmutable::parse('2026-09-12 12:00 UTC'));
        Storage::fake('local');
    }

    public function test_remote_acceptance_uses_customer_proof_without_impersonating_issuer_and_replays_safely(): void
    {
        $f = $this->scenario();
        $contract = $this->readyScenarioContract($f);
        $access = $this->enter($f);
        $proposal = $this->within($f, fn () => app(ActiveCustomerPortal::class)->acceptanceProposal($access, $contract));
        $this->get(route('portal.contract.show', $contract->id))->assertOk()->assertSee('Accepter le contrat');
        $url = route('portal.contract.accept', $contract->id);
        $payload = ['proposal' => $proposal, 'accepted_by_name' => 'Client Test', 'consent' => true];
        $this->post($url, $payload)->assertRedirect();
        $this->post($url, $payload)->assertRedirect();
        $this->within($f, function () use ($contract) {
            $this->assertSame('accepted', $contract->fresh()->status->value);
            $this->assertNull($contract->acceptances()->sole()->created_by);
            $this->assertSame(1, DB::table('portal_contract_acceptances')->count());
        });
    }

    public function test_active_extension_is_applied_only_after_consent_preserving_prior_version_and_reservation(): void
    {
        $f = $this->scenario();
        $contract = $this->acceptedScenarioContract($f);
        $originalVersion = $contract->current_version_id;
        $originalPrice = $f['reservation']->pricing_snapshot;
        $this->within($f, function () use ($f, $contract) {
            app(GuidedInspection::class)->complete($f['user'], $contract, 'departure', 0, ['mileage' => 1010, 'fuel_level' => '75.00', 'conditions' => array_fill_keys(array_keys(GuidedInspection::ITEMS), 'good'), 'photo_omission_reason' => 'Appareil indisponible pour ce test.']);
            app(ActivateRentalContract::class)->handle($contract->fresh(), $f['user']->id);
        });
        $this->enter($f);
        $this->post(route('portal.extension.request', $contract->id), ['requested_return_at' => $contract->expected_return_at->addDay()->toIso8601String()])->assertRedirect();
        $extension = $this->within($f, fn () => ContractExtension::sole());
        $this->within($f, fn () => app(ActiveCustomerPortal::class)->offer($f['user'], $extension, ['additional_amount' => '350.00', 'included_km' => 200], $this->pdf()));
        $this->assertSame($originalVersion, $this->within($f, fn () => $contract->fresh()->current_version_id));
        $url = route('portal.extension.accept', [$contract->id, $extension->id]);
        $this->postJson($url, ['accepted_by_name' => 'Client Test'])->assertUnprocessable();
        $this->post($url, ['accepted_by_name' => 'Client Test', 'consent' => true])->assertRedirect();
        $this->post($url, ['accepted_by_name' => 'Client Test', 'consent' => true])->assertRedirect();
        $this->within($f, function () use ($contract, $extension, $originalVersion, $originalPrice, $f) {
            $contract->refresh();
            $this->assertSame('active', $contract->status->value);
            $this->assertSame('750.00', $contract->rental_subtotal);
            $this->assertSame(2, $contract->versions()->count());
            $this->assertNotNull($contract->versions()->findOrFail($originalVersion)->locked_at);
            $this->assertSame($extension->requested_return_at->timestamp, $contract->vehicleBlock->ends_at->timestamp);
            $this->assertSame($originalPrice, $f['reservation']->fresh()->pricing_snapshot);
            $this->assertSame('accepted', $extension->fresh()->status);
            $this->assertSame(1, DB::table('portal_contract_acceptances')->count());
        });
    }

    public function test_conflicting_extension_preserves_contract_and_can_be_withdrawn(): void
    {
        $f = $this->scenario();
        $contract = $this->acceptedScenarioContract($f);
        $access = $this->enter($f);
        $extension = $this->within($f, function () use ($f, $contract, $access) {
            $portal = app(ActiveCustomerPortal::class);
            $extension = $portal->requestExtension($access, $contract->id, ['requested_return_at' => $contract->expected_return_at->addDay()->toIso8601String()]);
            $portal->offer($f['user'], $extension, ['additional_amount' => '350.00', 'included_km' => 0], $this->pdf());
            VehicleBlock::create(['agency_id' => $f['agency']->id, 'vehicle_id' => $f['vehicle']->id, 'block_type' => 'manual', 'status' => 'active', 'starts_at' => $contract->expected_return_at, 'ends_at' => $contract->expected_return_at->addDay(), 'reason' => 'Bloc test', 'created_by' => $f['user']->id]);

            return $extension;
        });
        $this->postJson(route('portal.extension.accept', [$contract->id, $extension->id]), ['accepted_by_name' => 'Client Test', 'consent' => true])->assertUnprocessable();
        $this->within($f, function () use ($contract) {
            $this->assertSame('400.00', $contract->fresh()->rental_subtotal);
            $this->assertSame(1, $contract->versions()->count());
        });
        $this->post(route('portal.extension.withdraw', [$contract->id, $extension->id]))->assertRedirect();
    }

    public function test_foreign_customer_and_revoked_access_cannot_read_or_accept(): void
    {
        $a = $this->scenario();
        $b = $this->scenario();
        $contract = $this->readyScenarioContract($b);
        $access = $this->enter($a);
        $this->get(route('portal.contract.show', $contract->id))->assertNotFound();
        $this->within($a, fn () => $access->update(['revoked_at' => now()]));
        $this->get(route('portal.home'))->assertForbidden();
    }

    private function enter(array $f): CustomerPortalAccess
    {
        $proof = bin2hex(random_bytes(24));
        $access = $this->within($f, fn () => CustomerPortalAccess::create(['agency_id' => $f['agency']->id, 'customer_id' => $f['customer']->id, 'issued_by' => $f['user']->id, 'expires_at' => now()->addDays(2), 'consumed_at' => now(), 'session_expires_at' => now()->addHours(2), 'session_hash' => hash('sha256', $proof)]));
        $this->withSession(['customer_portal' => ['id' => $access->id, 'proof' => $proof]]);

        return $access;
    }

    private function pdf(): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('avenant-fictif.pdf', "%PDF-1.4\nAvenant de test\n%%EOF");
    }
}
