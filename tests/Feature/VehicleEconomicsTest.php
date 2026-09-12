<?php

namespace Tests\Feature;

use App\Models\Expense;
use App\Models\VehicleEconomicProfile;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsRentalScenario;
use Tests\TestCase;

class VehicleEconomicsTest extends TestCase
{
    use BuildsRentalScenario, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesPermissionsSeeder::class);
        $this->travelTo(CarbonImmutable::parse('2026-09-12 12:00 UTC'));
    }

    public function test_economic_versions_reject_stale_edits_and_preserve_tenant_boundaries(): void
    {
        $f = $this->scenario();
        $url = route('vehicle-economics.store', $f['vehicle']);
        $this->actingAs($f['user'])->post($url, $this->data())->assertRedirect();
        $this->post($url, $this->data())->assertConflict();
        $this->within($f, fn () => $this->assertSame(1, VehicleEconomicProfile::count()));
        $foreign = $this->scenario();
        $this->actingAs($foreign['user'])->get(route('vehicle-economics.show', $f['vehicle']))->assertNotFound();
    }

    public function test_insurance_and_linked_purchase_are_not_counted_twice_and_currencies_are_separate(): void
    {
        $f = $this->scenario();
        $purchase = $this->within($f, function () use ($f) {
            $create = fn ($category, $amount, $currency = 'MAD') => Expense::create(['agency_id' => $f['agency']->id, 'vehicle_id' => $f['vehicle']->id, 'expense_number' => 'EXP-'.str()->random(8), 'category' => $category, 'description' => 'Dépense test', 'amount' => $amount, 'tax_amount' => '0.00', 'currency' => $currency, 'expense_date' => '2026-01-01', 'status' => 'approved', 'created_by' => $f['user']->id, 'approved_by' => $f['user']->id]);
            $create('insurance', '300.00');
            $create('insurance', '999.00', 'EUR');

            return $create('other', '3650.00');
        });
        $this->actingAs($f['user'])->post(route('vehicle-economics.store', $f['vehicle']), [...$this->data(), 'acquisition_expense_id' => $purchase->id])->assertRedirect();
        $response = $this->get(route('vehicle-profitability.index', ['date_from' => '2026-01-01', 'date_to' => '2026-12-31']))->assertOk();
        $costs = $response->viewData('economics')[$f['vehicle']->id]['MAD'];
        $this->assertSame('3650.00', $costs['depreciation']);
        $this->assertSame('65.00', $costs['insurance_unrecorded']);
        $this->assertSame('3650.00', $costs['acquisition_recorded']);
        $this->assertSame('-4015.00', $costs['estimated_margin']);
        $this->assertSame('-3950.00', $response->viewData('amounts')[$f['vehicle']->id]['MAD']['margin']);
        $this->assertSame('-999.00', $response->viewData('amounts')[$f['vehicle']->id]['EUR']['margin']);
    }

    public function test_paging_history_still_edits_the_latest_revision(): void
    {
        $f = $this->scenario();
        $this->actingAs($f['user']);
        for ($revision = 0; $revision < 11; $revision++) {
            $this->post(route('vehicle-economics.store', $f['vehicle']), [
                ...$this->data(), 'revision' => $revision,
            ])->assertRedirect();
        }
        $response = $this->get(route('vehicle-economics.show', [$f['vehicle'], 'page' => 2]))->assertOk();
        $this->assertSame(11, $response->viewData('profile')->revision);
        $this->assertSame(1, $response->viewData('profiles')->first()->revision);
    }

    private function data(): array
    {
        return ['revision' => 0, 'effective_from' => '2026-01-01', 'acquired_on' => '2026-01-01', 'acquisition_cost' => '3650.00', 'residual_value' => '0.00', 'depreciation_months' => 12, 'annual_insurance' => '365.00', 'monthly_unrecorded_costs' => '0.00', 'currency' => 'MAD', 'reason' => 'Hypothèses initiales du véhicule.', 'no_duplicate_costs' => true];
    }
}
