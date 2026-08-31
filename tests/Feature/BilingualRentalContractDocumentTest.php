<?php

namespace Tests\Feature;

use App\Actions\Customers\CreateCustomer;
use App\Actions\Customers\CreateDriver;
use App\Actions\Documents\StorePrivateDocument;
use App\Actions\Rentals\AcceptRentalContract;
use App\Actions\Rentals\AttachContractVersionDocument;
use App\Actions\Rentals\CreateContractVersion;
use App\Actions\Rentals\CreateRentalContractFromReservation;
use App\Actions\Rentals\MarkContractReady;
use App\Actions\Reservations\ConfirmReservation;
use App\Actions\Reservations\CreateReservation;
use App\Actions\Vehicles\CreateVehicle;
use App\Enums\CustomerType;
use App\Enums\DocumentType;
use App\Enums\VerificationStatus;
use App\Models\Agency;
use App\Models\ContractDriver;
use App\Models\DamageReport;
use App\Models\InspectionItem;
use App\Models\PricingRule;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Models\VehicleCategory;
use App\Models\VehicleInspection;
use App\Support\Contracts\CanonicalJson;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class BilingualRentalContractDocumentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('rentfleet_test', $this->assertUsesAuthorizedPostgreSqlTestDatabase());
        Storage::fake(config('documents.disk'));
        $this->seed(RolesPermissionsSeeder::class);
    }

    public function test_print_requires_authentication_and_enforces_tenant_agency_and_permission_scopes(): void
    {
        $a = $this->fixture();
        $contract = $this->contract($a);

        $this->get(route('contracts.print', $contract))->assertRedirect(route('login'));

        $b = $this->fixture();
        $this->actingAs($b['user'])->get(route('contracts.print', $contract))->assertNotFound();

        $otherAgency = $this->inTenant($a, fn () => Agency::factory()->create());
        $managerRole = Role::query()->where('slug', 'agency-manager')->firstOrFail();
        $otherAgencyManager = User::factory()->create([
            'tenant_id' => $a['tenant']->id,
            'agency_id' => $otherAgency->id,
            'role_id' => $managerRole->id,
        ]);
        $this->actingAs($otherAgencyManager)->get(route('contracts.print', $contract))->assertForbidden();

        $noAccessRole = Role::query()->forceCreate([
            'tenant_id' => $a['tenant']->id,
            'name' => 'Rôle sans contrat',
            'slug' => 'sans-contrat-'.str()->random(8),
            'is_system' => false,
            'is_active' => true,
        ]);
        $noAccessUser = User::factory()->create([
            'tenant_id' => $a['tenant']->id,
            'agency_id' => $a['agency']->id,
            'role_id' => $noAccessRole->id,
        ]);
        $this->actingAs($noAccessUser)->get(route('contracts.print', $contract))->assertForbidden();

        foreach (['tenant-owner', 'agency-manager', 'rental-agent', 'fleet-manager', 'accountant', 'viewer-auditor'] as $slug) {
            $role = Role::query()->where('slug', $slug)->firstOrFail();
            $user = User::factory()->create([
                'tenant_id' => $a['tenant']->id,
                'agency_id' => $slug === 'tenant-owner' ? null : $a['agency']->id,
                'role_id' => $role->id,
            ]);
            $this->actingAs($user)->get(route('contracts.print', $contract))->assertOk();
        }
    }

    public function test_authorized_print_is_private_bilingual_two_page_and_contains_exactly_nine_aligned_articles(): void
    {
        $f = $this->fixture();
        $contract = $this->contract($f);
        $version = $this->inTenant($f, fn () => $contract->currentVersion);
        $response = $this->actingAs($f['user'])->get(route('contracts.print', $contract));

        $response->assertOk()
            ->assertHeader('Pragma', 'no-cache')
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow, noarchive')
            ->assertSee('data-contract-page="1"', false)
            ->assertSee('data-contract-page="2"', false)
            ->assertSee('Page 1 / 2')
            ->assertSee('Page 2 / 2')
            ->assertSee('dir="rtl"', false)
            ->assertSee('الشروط العامة لكراء المركبات', false)
            ->assertSee('loi n° 09-08')
            ->assertSee(e($f['tenant']->legal_name), false)
            ->assertSee(e($f['customer']->displayName()), false)
            ->assertDontSee($f['tenant']->legal_name, false)
            ->assertDontSee($f['customer']->displayName(), false);

        $html = $response->getContent();
        $cacheControl = (string) $response->headers->get('Cache-Control');
        $this->assertStringContainsString('private', $cacheControl);
        $this->assertStringContainsString('no-store', $cacheControl);
        $this->assertStringContainsString('max-age=0', $cacheControl);
        $this->assertSame(9, substr_count($html, 'data-contract-article='));
        $response->assertSeeInOrder([
            'Parties et objet du contrat',
            'État, remise et restitution du véhicule',
            'Entretien, panne et réparation',
            'Utilisation autorisée',
            'Assurance, accident, vol et incident',
            'Prix, caution, paiement, prolongation et retour',
            'Dommages et frais',
            'Clés, accessoires et documents du véhicule',
            'Infractions, responsabilités et réclamations',
        ]);
        $this->assertCount(9, data_get($version->terms_snapshot, 'document.conditions'));
        foreach (data_get($version->terms_snapshot, 'document.conditions') as $article) {
            $this->assertNotEmpty(data_get($article, 'fr.title'));
            $this->assertNotEmpty(data_get($article, 'ar.title'));
        }
    }

    public function test_print_has_no_external_or_public_sensitive_asset_and_escapes_tenant_and_customer_values(): void
    {
        $f = $this->fixture();
        $response = $this->actingAs($f['user'])->get(route('contracts.print', $this->contract($f)));
        $html = $response->getContent();

        $this->assertStringNotContainsString('http://', $html);
        $this->assertStringNotContainsString('https://', $html);
        $this->assertStringNotContainsString('/storage/', $html);
        $this->assertStringNotContainsString('<nav', $html);
        $this->assertStringNotContainsString('data-nav-key', $html);
        $this->assertStringNotContainsString('sidebar', mb_strtolower($html));
        $this->assertStringContainsString('<svg', $html);
        $this->assertStringContainsString('@page', $html);
        $this->assertStringContainsString('page-break-after:always', $html);
        $this->assertStringNotContainsString('<script>alert("tenant")</script>', $html);
        $this->assertStringNotContainsString('<img src=x onerror=alert(1)>', $html);
        $this->assertStringContainsString('&lt;script&gt;alert(&quot;tenant&quot;)&lt;/script&gt;', $html);
        $this->assertStringContainsString('&lt;img src=x onerror=alert(1)&gt;', $html);
        $this->assertStringNotContainsString('20202.jpeg', $html);
        $this->assertStringNotContainsString('10101.jpeg', $html);
    }

    public function test_additional_driver_is_absent_until_captured_in_a_new_version(): void
    {
        $f = $this->fixture();
        $contract = $this->contract($f);
        $this->actingAs($f['user'])->get(route('contracts.print', $contract))->assertDontSee('Conducteur additionnel');

        $additional = $this->inTenant($f, fn () => app(CreateDriver::class)->handle($f['customer'], [
            'first_name' => 'Nadia',
            'last_name' => 'Conductrice',
            'birth_date' => '1992-04-15',
            'licence_number' => 'PERMIS-ADDITIONNEL-'.str()->random(8),
            'licence_category' => 'B',
            'licence_issued_at' => today()->subYears(3),
            'licence_expires_at' => today()->addYears(3),
            'verification_status' => VerificationStatus::Verified,
            'is_primary' => false,
        ]));
        $this->inTenant($f, function () use ($contract, $f, $additional): void {
            ContractDriver::create([
                'rental_contract_id' => $contract->id,
                'customer_id' => $f['customer']->id,
                'driver_id' => $additional->id,
                'is_primary' => false,
                'authorization_snapshot' => ['licence_expires_at' => $additional->licence_expires_at->toDateString()],
            ]);
            app(CreateContractVersion::class)->handle($contract, $f['user']->id, 'Ajout du conducteur autorisé');
        });

        $this->actingAs($f['user'])->get(route('contracts.print', $contract))
            ->assertSee('Conducteur additionnel')
            ->assertSee('Nadia Conductrice');
    }

    public function test_completed_inspections_are_frozen_and_pending_damage_has_no_automatic_responsibility(): void
    {
        $f = $this->fixture();
        $contract = $this->contract($f);
        [$departure, $return, $newVersion] = $this->inTenant($f, function () use ($f, $contract): array {
            $departure = $this->inspection($f, $contract, 'departure', 15125, '87.50', 'Départ contradictoire');
            $return = $this->inspection($f, $contract, 'return', 15440, '62.50', 'Retour contradictoire');
            DamageReport::create([
                'agency_id' => $f['agency']->id,
                'rental_contract_id' => $contract->id,
                'vehicle_id' => $f['vehicle']->id,
                'departure_inspection_id' => $departure->id,
                'return_inspection_id' => $return->id,
                'damage_number' => 'DMG-'.str()->random(10),
                'description' => 'Rayure documentée à examiner',
                'severity' => 'minor',
                'status' => 'reported',
                'responsibility' => 'pending',
                'estimated_cost' => '0.00',
                'reported_by' => $f['user']->id,
            ]);
            $newVersion = app(CreateContractVersion::class)->handle($contract, $f['user']->id, 'Constats d’inspection figés');

            return [$departure, $return, $newVersion];
        });

        $response = $this->actingAs($f['user'])->get(route('contracts.print', $contract));
        $response->assertSee('15125 km')
            ->assertSee('15440 km')
            ->assertSee('Départ contradictoire')
            ->assertSee('Retour contradictoire')
            ->assertSee('Rayure documentée à examiner')
            ->assertDontSee('Décision humaine :');

        $snapshot = data_get($newVersion->terms_snapshot, 'document.inspection_summary');
        $this->assertCount(2, $snapshot);
        $this->assertSame(['departure', 'return'], collect($snapshot)->pluck('type')->all());
    }

    public function test_financial_summary_uses_frozen_pricing_even_if_live_draft_amounts_change(): void
    {
        $f = $this->fixture();
        $contract = $this->contract($f);
        $version = $this->inTenant($f, fn () => $contract->currentVersion);
        $expectedSubtotal = data_get($version->pricing_snapshot, 'calculation.subtotal');
        $expectedTotal = data_get($version->pricing_snapshot, 'calculation.total_amount');

        $this->inTenant($f, fn () => $contract->forceFill([
            'rental_subtotal' => '9999.00',
            'total_amount' => '9999.00',
            'deposit_required' => '9999.00',
        ])->save());

        $response = $this->actingAs($f['user'])->get(route('contracts.print', $contract));
        $response->assertSee(str_replace('.', ',', $expectedSubtotal).' MAD')
            ->assertSee(str_replace('.', ',', $expectedTotal).' MAD')
            ->assertDontSee('9999,00 MAD');
    }

    public function test_historical_version_is_not_rewritten_or_given_current_bilingual_conditions(): void
    {
        $f = $this->fixture();
        $contract = $this->contract($f);
        $version = $this->inTenant($f, fn () => $contract->currentVersion);
        $terms = [
            'schema_version' => 1,
            'contract_number' => $contract->contract_number,
            'driver' => ['name' => 'Conducteur historique'],
            'clauses' => ['Clause capturée uniquement'],
        ];
        $content = [
            'terms_snapshot' => $terms,
            'pricing_snapshot' => $version->pricing_snapshot,
            'customer_snapshot' => $version->customer_snapshot,
            'vehicle_snapshot' => $version->vehicle_snapshot,
        ];
        $hash = app(CanonicalJson::class)->hash($content);
        $this->inTenant($f, fn () => $version->forceFill(['terms_snapshot' => $terms, 'content_hash' => $hash])->save());
        $before = $this->inTenant($f, fn () => $version->fresh()->only(['terms_snapshot', 'pricing_snapshot', 'customer_snapshot', 'vehicle_snapshot', 'content_hash']));

        $response = $this->actingAs($f['user'])->get(route('contracts.print', $contract));
        $response->assertOk()
            ->assertSee('Version historique')
            ->assertSee('Clause capturée uniquement')
            ->assertDontSee('Parties et objet du contrat')
            ->assertDontSee('data-contract-article=', false);
        $this->assertSame($before, $this->inTenant($f, fn () => $version->fresh()->only(array_keys($before))));
    }

    public function test_accepted_version_hash_and_snapshots_do_not_change_on_preview_or_print(): void
    {
        $f = $this->fixture();
        $this->documents($f);
        $contract = $this->contract($f);
        $contract = $this->inTenant($f, fn () => app(MarkContractReady::class)->handle($contract, $f['user']->id));
        $this->inTenant($f, fn () => app(AttachContractVersionDocument::class)->handle(
            $contract,
            UploadedFile::fake()->createWithContent('contrat-test.pdf', "%PDF-1.4\nContrat test\n%%EOF"),
            $f['user']->id,
        ));
        $contract = $this->inTenant($f, fn () => app(AcceptRentalContract::class)->handle($contract, [
            'accepted_by_name' => 'Client Signataire',
            'acceptance_method' => 'typed_name',
            'ip_address' => '203.0.113.10',
            'user_agent' => 'SensitiveUA/1.0',
        ], $f['user']->id));
        $version = $this->inTenant($f, fn () => $contract->currentVersion);
        $before = $version->only(['terms_snapshot', 'pricing_snapshot', 'customer_snapshot', 'vehicle_snapshot', 'content_hash']);
        $lockedAt = $version->locked_at?->toIso8601String();

        $preview = $this->actingAs($f['user'])->get(route('contracts.print', $contract));
        $print = $this->actingAs($f['user'])->get(route('contracts.print', ['contract' => $contract, 'print' => 1]));
        $preview->assertOk()->assertSee('Acceptation électronique enregistrée');
        $print->assertOk()->assertSee('window.print()', false);
        foreach ([$preview->getContent(), $print->getContent()] as $html) {
            $this->assertStringNotContainsString('203.0.113.10', $html);
            $this->assertStringNotContainsString('SensitiveUA/1.0', $html);
            $this->assertStringNotContainsString($version->content_hash, $html);
        }
        $this->assertStringNotContainsString('version_id=', $preview->getContent());
        $this->assertStringNotContainsString('data-id=', $preview->getContent());
        $this->assertSame($before, $this->inTenant($f, fn () => $version->fresh()->only(array_keys($before))));
        $this->assertSame($lockedAt, $this->inTenant($f, fn () => $version->fresh()->locked_at?->toIso8601String()));
    }

    private function fixture(): array
    {
        $tenant = Tenant::factory()->create([
            'name' => 'RentFleet Test <script>alert("tenant")</script>',
            'legal_name' => 'RentFleet Test <script>alert("tenant")</script>',
            'email' => 'contact@rentfleet.test',
            'phone' => '+212500000000',
            'settings' => ['address' => '10 rue de test, Casablanca'],
        ]);
        $agency = app(TenantContext::class)->run($tenant, fn () => Agency::factory()->create([
            'name' => 'Agence Contrats',
            'email' => 'agence@rentfleet.test',
            'phone' => '+212511111111',
            'address' => '20 avenue de test, Casablanca',
        ]));
        $role = Role::query()->where('slug', 'tenant-owner')->firstOrFail();
        $user = User::factory()->create(['tenant_id' => $tenant->id, 'agency_id' => null, 'role_id' => $role->id]);
        $f = compact('tenant', 'agency', 'user');

        return $this->inTenant($f, function () use ($f): array {
            $category = VehicleCategory::create(['code' => 'DOC-'.str()->random(8), 'name' => 'SUV', 'is_active' => true]);
            $vehicle = app(CreateVehicle::class)->handle([
                'agency_id' => $f['agency']->id,
                'vehicle_category_id' => $category->id,
                'registration_number' => 'RF-'.str()->upper(str()->random(7)),
                'brand' => 'Dacia',
                'model' => 'Duster',
                'production_year' => 2025,
                'fuel_type' => 'diesel',
                'transmission' => 'manual',
                'color' => 'Bleu',
                'current_mileage' => 15000,
            ], $f['user']->id);
            $customer = app(CreateCustomer::class)->handle([
                'agency_id' => $f['agency']->id,
                'customer_type' => CustomerType::Individual,
                'first_name' => 'Client <img src=x onerror=alert(1)>',
                'last_name' => 'Sécurisé',
                'birth_date' => '1990-02-03',
                'nationality' => 'Marocaine',
                'address' => '30 boulevard de test',
                'city' => 'Casablanca',
                'phone' => '+212522222222',
                'identity_type' => 'CIN',
                'identity_number' => 'TEST-ID-'.str()->random(8),
                'verification_status' => VerificationStatus::Verified,
            ]);
            $driver = app(CreateDriver::class)->handle($customer, [
                'first_name' => 'Conducteur',
                'last_name' => 'Principal',
                'birth_date' => '1988-07-08',
                'licence_number' => 'PERMIS-'.str()->random(8),
                'licence_category' => 'B',
                'licence_issued_at' => today()->subYears(4),
                'licence_expires_at' => today()->addYears(4),
                'verification_status' => VerificationStatus::Verified,
                'is_primary' => true,
            ]);
            PricingRule::create([
                'agency_id' => null,
                'vehicle_category_id' => $category->id,
                'name' => 'Tarif document',
                'daily_rate' => '400.00',
                'deposit_amount' => '3000.00',
                'included_km_per_day' => 200,
                'extra_km_rate' => '2.50',
                'late_hour_rate' => '75.00',
                'minimum_days' => 1,
                'maximum_days' => 30,
                'valid_from' => today()->subYear(),
                'priority' => 0,
                'currency' => 'MAD',
                'conditions' => [],
                'is_active' => true,
                'created_by' => $f['user']->id,
            ]);
            $start = CarbonImmutable::now()->addDays(3)->startOfHour();
            $reservation = app(CreateReservation::class)->handle([
                'agency_id' => $f['agency']->id,
                'customer_id' => $customer->id,
                'driver_id' => $driver->id,
                'vehicle_category_id' => $category->id,
                'vehicle_id' => $vehicle->id,
                'starts_at' => $start,
                'ends_at' => $start->addDays(2),
                'status' => 'draft',
            ], $f['user']->id);
            app(ConfirmReservation::class)->handle($reservation, $f['user']->id);

            return [...$f, 'category' => $category, 'vehicle' => $vehicle, 'customer' => $customer, 'driver' => $driver, 'reservation' => $reservation->refresh()];
        });
    }

    private function contract(array $f)
    {
        return $this->inTenant($f, fn () => app(CreateRentalContractFromReservation::class)->handle($f['reservation'], $f['user']->id));
    }

    private function inspection(array $f, $contract, string $type, int $mileage, string $fuel, string $notes): VehicleInspection
    {
        $inspection = VehicleInspection::create([
            'agency_id' => $f['agency']->id,
            'rental_contract_id' => $contract->id,
            'vehicle_id' => $f['vehicle']->id,
            'inspection_type' => $type,
            'status' => 'draft',
            'inspected_at' => now(),
            'mileage' => $mileage,
            'fuel_level' => $fuel,
            'notes' => $notes,
            'created_by' => $f['user']->id,
        ]);
        InspectionItem::create(['vehicle_inspection_id' => $inspection->id, 'item_code' => 'body', 'label' => 'Carrosserie', 'condition' => $type === 'return' ? 'damaged' : 'good', 'notes' => $type === 'return' ? 'Rayure observée' : null]);
        InspectionItem::create(['vehicle_inspection_id' => $inspection->id, 'item_code' => 'keys', 'label' => 'Clés', 'condition' => 'good']);
        $inspection->forceFill(['status' => 'completed', 'completed_by' => $f['user']->id, 'completed_at' => now()])->save();

        return $inspection->refresh();
    }

    private function documents(array $f): void
    {
        $this->inTenant($f, function () use ($f): void {
            $pdf = fn (string $name) => UploadedFile::fake()->createWithContent($name, "%PDF-1.4\nDocument test\n%%EOF");
            app(StorePrivateDocument::class)->handle($f['customer'], ['document_type' => DocumentType::CustomerIdentity, 'title' => 'Identité test', 'is_sensitive' => true], $pdf('identite.pdf'), $f['user']->id);
            app(StorePrivateDocument::class)->handle($f['driver'], ['document_type' => DocumentType::DrivingLicence, 'title' => 'Permis test', 'is_sensitive' => true], $pdf('permis.pdf'), $f['user']->id);
        });
    }

    private function inTenant(array $f, callable $callback): mixed
    {
        return app(TenantContext::class)->run($f['tenant'], $callback, $f['agency']->id);
    }
}
