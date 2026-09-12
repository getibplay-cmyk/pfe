<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\PublicBookingProfile;
use App\Models\PublicBookingRequest;
use App\Models\PublicVehicleListing;
use App\Models\Reservation;
use App\Models\VehicleBlock;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsRentalScenario;
use Tests\TestCase;

class PublicBookingTest extends TestCase
{
    use BuildsRentalScenario, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withCredentials();
        $this->seed(RolesPermissionsSeeder::class);
        $this->travelTo(CarbonImmutable::parse('2026-09-12 12:00 UTC'));
    }

    public function test_catalog_requires_explicit_publication_and_hides_private_vehicle_data_and_other_companies(): void
    {
        $f = $this->catalog();
        $url = route('booking.catalog', $f['profile']->slug);
        $this->get($url)->assertOk()->assertSee('Duster')->assertDontSee($f['vehicle']->registration_number)->assertDontSee($f['customer']->displayName());
        $other = $this->catalog();
        $this->get(route('booking.vehicle', [$f['profile']->slug, $other['listing']->id]))->assertNotFound();
        $this->within($f, fn () => $f['profile']->update(['enabled' => false]));
        $this->get($url)->assertNotFound();
    }

    public function test_request_is_encrypted_idempotent_and_does_not_confirm_or_block_a_vehicle(): void
    {
        $f = $this->catalog();
        $proposal = $this->proposal($f);
        $url = route('booking.store', [$f['profile']->slug, $f['listing']->id]);
        $payload = ['proposal' => $proposal, ...$this->contact()];
        $response = $this->post($url, $payload)->assertRedirect();
        $this->post($url, $payload)->assertRedirect();
        $this->get($response->headers->get('Location'))->assertOk()->assertSee('Votre demande est enregistrée');
        $this->within($f, function () {
            $booking = PublicBookingRequest::sole();
            $this->assertSame('pending', $booking->status);
            $this->assertSame('Nouveau', $booking->contact['first_name']);
            $this->assertStringNotContainsString('test@example.test', DB::table('public_booking_requests')->value('contact'));
            $this->assertSame(1, Reservation::count());
            $this->assertSame(1, VehicleBlock::where('status', 'active')->count());
            $audit = AuditLog::where('action', 'public_booking.requested')->sole();
            $this->assertNull($audit->auditable_id);
            $this->assertSame($booking->id, $audit->auditable_uuid);
            $this->assertNull($audit->user_id);
            $this->assertStringNotContainsString('test@example.test', $audit->toJson());
        });
    }

    public function test_agency_conversion_creates_an_unverified_customer_and_a_draft_without_an_extra_block(): void
    {
        $f = $this->catalog();
        $this->post(route('booking.store', [$f['profile']->slug, $f['listing']->id]), ['proposal' => $this->proposal($f), ...$this->contact()])->assertRedirect();
        $booking = $this->within($f, fn () => PublicBookingRequest::sole());
        $this->actingAs($f['user'])->post(route('booking-admin.convert', $booking), ['confirmed' => true])->assertRedirect();
        $this->within($f, function () use ($booking) {
            $reservation = Reservation::findOrFail($booking->fresh()->reservation_id);
            $this->assertSame('draft', $reservation->status->value);
            $this->assertSame('pending', $reservation->customer->verification_status->value);
            $this->assertNull($reservation->driver_id);
            $this->assertSame(1, VehicleBlock::where('status', 'active')->count());
            $this->assertSame($booking->id, AuditLog::where('action', 'public_booking.converted')->sole()->auditable_uuid);
        });
        $other = $this->catalog();
        $this->actingAs($other['user'])->get(route('booking-admin.show', $booking))->assertNotFound();
    }

    public function test_tampered_prices_expired_quotes_and_new_conflicts_cannot_create_requests(): void
    {
        $f = $this->catalog();
        $proposal = $this->proposal($f);
        $url = route('booking.store', [$f['profile']->slug, $f['listing']->id]);
        $this->postJson($url, ['proposal' => $proposal, ...$this->contact(), 'total_amount' => '1.00'])->assertUnprocessable();
        $this->postJson($url, ['proposal' => 'fake', ...$this->contact()])->assertUnprocessable();
        $this->travel(21)->minutes();
        $this->postJson($url, ['proposal' => $proposal, ...$this->contact()])->assertConflict();
        $fresh = $this->proposal($f);
        $this->within($f, fn () => VehicleBlock::create([
            'agency_id' => $f['agency']->id, 'vehicle_id' => $f['vehicle']->id,
            'block_type' => 'manual', 'status' => 'active',
            'starts_at' => now()->addDays(11), 'ends_at' => now()->addDays(13),
            'reason' => 'Indisponibilité après le devis', 'created_by' => $f['user']->id,
        ]));
        $this->postJson($url, ['proposal' => $fresh, ...$this->contact()])->assertConflict();
        $this->assertSame(0, DB::table('public_booking_requests')->count());
    }

    public function test_rejection_records_the_uuid_subject_without_losing_the_request(): void
    {
        $f = $this->catalog();
        $this->post(route('booking.store', [$f['profile']->slug, $f['listing']->id]), ['proposal' => $this->proposal($f), ...$this->contact()])->assertRedirect();
        $booking = $this->within($f, fn () => PublicBookingRequest::sole());
        $this->actingAs($f['user'])->post(route('booking-admin.reject', $booking), ['review_note' => 'Dates à revoir avec le client.'])->assertRedirect();
        $this->within($f, function () use ($booking) {
            $this->assertSame('rejected', $booking->fresh()->status);
            $this->assertSame($booking->id, AuditLog::where('action', 'public_booking.rejected')->sole()->auditable_uuid);
            $this->assertSame(1, Reservation::count());
        });
    }

    public function test_action_dashboard_only_shows_requests_of_the_active_company(): void
    {
        $a = $this->catalog();
        $b = $this->catalog();
        foreach ([$a, $b] as $f) {
            $this->post(route('booking.store', [$f['profile']->slug, $f['listing']->id]), [
                'proposal' => $this->proposal($f), ...$this->contact(),
            ])->assertRedirect();
        }
        $response = $this->actingAs($a['user'])->get(route('dashboard'))->assertOk();
        $group = collect($response->viewData('actionGroups'))->firstWhere('key', 'booking-requests');
        $this->assertSame(1, $group['count']);
        $request = $this->within($a, fn () => PublicBookingRequest::firstOrFail());
        $this->assertSame(route('booking-admin.show', $request), $group['items']->first()['url']);
    }

    public function test_other_browser_cannot_use_a_quote_or_open_a_receipt(): void
    {
        $f = $this->catalog();
        $proposal = $this->proposal($f);
        $response = $this->post(route('booking.store', [$f['profile']->slug, $f['listing']->id]), ['proposal' => $proposal, ...$this->contact()])->assertRedirect();
        $this->app['session']->invalidate();
        $this->withCookie(config('session.cookie'), $this->app['session']->getId());
        $this->get($response->headers->get('Location'))->assertNotFound();
        $this->postJson(route('booking.store', [$f['profile']->slug, $f['listing']->id]), ['proposal' => $proposal, ...$this->contact()])->assertForbidden();
    }

    private function catalog(): array
    {
        $f = $this->scenario();

        return $this->within($f, function () use ($f) {
            $profile = PublicBookingProfile::create(['agency_id' => $f['agency']->id, 'slug' => 'catalogue-'.strtolower(str()->random(12)), 'enabled' => true, 'public_name' => 'Agence de test']);
            $listing = PublicVehicleListing::create(['profile_id' => $profile->id, 'agency_id' => $f['agency']->id, 'vehicle_id' => $f['vehicle']->id, 'published' => true]);

            return [...$f, 'profile' => $profile, 'listing' => $listing];
        });
    }

    private function proposal(array $f): string
    {
        $response = $this->get(route('booking.vehicle', [$f['profile']->slug, $f['listing']->id, 'starts_at' => now()->addDays(10)->format('Y-m-d\TH:i'), 'ends_at' => now()->addDays(12)->format('Y-m-d\TH:i')]))->assertOk();
        $this->withCookie(config('session.cookie'), $this->app['session']->getId());

        return $response->viewData('quote')['proposal'];
    }

    private function contact(): array
    {
        return ['first_name' => 'Nouveau', 'last_name' => 'Client', 'email' => 'test@example.test', 'consent' => true];
    }
}
