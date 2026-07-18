<?php

namespace Tests\Feature;

use App\Actions\Vehicles\CreateVehicle;
use App\Models\Agency;
use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\DepositTransaction;
use App\Models\Expense;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\RentalContract;
use App\Models\Reservation;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleBlock;
use App\Models\VehicleCategory;
use App\Support\Export\SpreadsheetSafeCsv;
use App\Support\Reporting\BuildMinimalReport;
use App\Support\Reporting\ReportCriteria;
use App\Support\Tenancy\TenantContext;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesPermissionsSeeder;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class Lot06FD1ReportingDiagnosticsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->freezeAt('2026-08-15 12:00:00');
        $this->seed(RolesPermissionsSeeder::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_interval_ending_exactly_at_report_start_is_excluded(): void
    {
        $fixture = $this->fixture();
        $this->reservation($fixture, '2026-07-31 12:00:00', '2026-08-01 00:00:00');

        $criteria = $this->criteria($fixture, '2026-08-01', '2026-08-01');
        $rows = app(TenantContext::class)->run($fixture['tenant'], fn () => app(BuildMinimalReport::class)->reservationRows($criteria), null);

        $this->assertSame(0, $rows->total());
    }

    public function test_interval_starting_exactly_at_report_end_is_excluded(): void
    {
        $fixture = $this->fixture();
        $this->reservation($fixture, '2026-08-02 00:00:00', '2026-08-02 12:00:00');

        $criteria = $this->criteria($fixture, '2026-08-01', '2026-08-01');
        $rows = app(TenantContext::class)->run($fixture['tenant'], fn () => app(BuildMinimalReport::class)->reservationRows($criteria), null);

        $this->assertSame(0, $rows->total());
    }

    public function test_consecutive_period_does_not_count_end_boundary_twice(): void
    {
        $this->freezeAt('2026-08-01 00:00:00');
        $fixture = $this->fixture();
        $this->block($fixture, '2026-08-01 12:00:00', '2026-08-02 00:00:00');
        $this->freezeAt('2026-08-03 00:00:00');

        $first = $this->report($fixture, '2026-08-01', '2026-08-01');
        $second = $this->report($fixture, '2026-08-02', '2026-08-02');

        $this->assertSame(43200, $first['operational']['utilization']['occupied_seconds']);
        $this->assertSame(0, $second['operational']['utilization']['occupied_seconds']);
    }

    public function test_partially_intersecting_block_is_clipped_to_report_period(): void
    {
        $this->freezeAt('2026-08-01 00:00:00');
        $fixture = $this->fixture();
        $this->block($fixture, '2026-07-31 22:00:00', '2026-08-01 06:00:00');
        $this->freezeAt('2026-08-02 00:00:00');

        $report = $this->report($fixture, '2026-08-01', '2026-08-01');

        $this->assertSame(21600, $report['operational']['utilization']['occupied_seconds']);
        $this->assertSame('6 h 00 min', $report['operational']['utilization']['occupied_duration']);
    }

    public function test_utilization_rate_is_exact_for_active_vehicle_capacity(): void
    {
        $this->freezeAt('2026-08-01 00:00:00');
        $fixture = $this->fixture();
        $this->block($fixture, '2026-08-01 00:00:00', '2026-08-01 06:00:00', 'contract');
        $this->freezeAt('2026-08-02 00:00:00');

        $utilization = $this->report($fixture, '2026-08-01', '2026-08-01')['operational']['utilization'];

        $this->assertSame(86400, $utilization['capacity_seconds']);
        $this->assertSame(21600, $utilization['contract_seconds'] ?? $utilization['block_types']['contract']);
        $this->assertSame('25.00', $utilization['rate']);
    }

    public function test_utilization_with_zero_denominator_returns_zero(): void
    {
        $fixture = $this->fixture(createVehicle: false);

        $utilization = $this->report($fixture, '2026-08-01', '2026-08-31')['operational']['utilization'];

        $this->assertSame(0, $utilization['capacity_seconds']);
        $this->assertSame('0.00', $utilization['rate']);
    }

    public function test_released_and_cancelled_blocks_are_excluded(): void
    {
        $this->freezeAt('2026-08-01 00:00:00');
        $fixture = $this->fixture();
        $this->block($fixture, '2026-08-01 01:00:00', '2026-08-01 03:00:00', status: 'released');
        $this->block($fixture, '2026-08-01 04:00:00', '2026-08-01 06:00:00', status: 'cancelled');
        $this->freezeAt('2026-08-02 00:00:00');

        $report = $this->report($fixture, '2026-08-01', '2026-08-01');

        $this->assertSame(0, $report['operational']['utilization']['occupied_seconds']);
    }

    public function test_report_is_scoped_to_selected_authorized_agency(): void
    {
        $fixture = $this->fixture();
        $otherAgency = app(TenantContext::class)->run($fixture['tenant'], fn () => Agency::factory()->create());
        $other = $this->vehicleForAgency($fixture, $otherAgency);
        $this->block($fixture, '2026-08-15 13:00:00', '2026-08-15 14:00:00');
        $this->block([...$fixture, 'agency' => $otherAgency, 'vehicle' => $other], '2026-08-15 15:00:00', '2026-08-15 17:00:00');

        $own = $this->report($fixture, '2026-08-01', '2026-08-31', agencyIds: [$fixture['agency']->id]);
        $all = $this->report($fixture, '2026-08-01', '2026-08-31', agencyIds: [$fixture['agency']->id, $otherAgency->id]);

        $this->assertSame(3600, $own['operational']['utilization']['occupied_seconds']);
        $this->assertSame(10800, $all['operational']['utilization']['occupied_seconds']);
    }

    public function test_forged_cross_agency_filter_and_export_are_forbidden(): void
    {
        $fixture = $this->fixture('agency-manager');
        $otherAgency = app(TenantContext::class)->run($fixture['tenant'], fn () => Agency::factory()->create());
        $filters = ['date_from' => '2026-08-01', 'date_to' => '2026-08-31', 'agency_id' => $otherAgency->id];

        $this->actingAs($fixture['user'])->get(route('reports.index', $filters))->assertForbidden();
        $this->actingAs($fixture['user'])->get(route('reports.export', $filters))->assertForbidden();
    }

    public function test_void_invoices_are_excluded_from_billed_amount(): void
    {
        $fixture = $this->fixture();
        $this->invoice($fixture, '100.00', 'MAD', 'void');
        $this->invoice($fixture, '250.00', 'MAD', 'issued');

        $mad = $this->report($fixture, '2026-08-01', '2026-08-31')['financial']['currencies']['MAD'];

        $this->assertSame(1, $mad['issued_invoices']);
        $this->assertSame('250.00', $mad['invoiced_amount']);
    }

    public function test_pending_payments_are_excluded_from_collections(): void
    {
        $fixture = $this->fixture();
        $invoice = $this->invoice($fixture, '100.00');
        $this->payment($fixture, $invoice, '100.00', 'pending');

        $mad = $this->report($fixture, '2026-08-01', '2026-08-31')['financial']['currencies']['MAD'];

        $this->assertSame('0.00', $mad['collected_net']);
        $this->assertSame('100.00', $mad['outstanding_balance']);
    }

    public function test_payment_reversal_is_deducted_from_net_collections(): void
    {
        $fixture = $this->fixture();
        $invoice = $this->invoice($fixture, '100.00');
        $original = $this->payment($fixture, $invoice, '100.00', 'reversed');
        $this->payment($fixture, $invoice, '100.00', 'posted', 'outgoing', $original);

        $mad = $this->report($fixture, '2026-08-01', '2026-08-31')['financial']['currencies']['MAD'];

        $this->assertSame('0.00', $mad['collected_net']);
        $this->assertSame('100.00', $mad['outstanding_balance']);
    }

    public function test_deposits_are_calculated_from_append_only_ledger(): void
    {
        $fixture = $this->fixture();
        $contract = $this->contract($fixture);
        $received = $this->deposit($fixture, $contract, 'received', '100.00');
        $this->deposit($fixture, $contract, 'retained', '20.00');
        $refunded = $this->deposit($fixture, $contract, 'refunded', '30.00');
        $this->deposit($fixture, $contract, 'reversal', '30.00', $refunded);

        $mad = $this->report($fixture, '2026-08-01', '2026-08-31')['financial']['currencies']['MAD'];

        $this->assertNotNull($received);
        $this->assertSame('80.00', $mad['held_deposits']);
        $this->assertSame('20.00', $mad['retained_deposits']);
        $this->assertSame('0.00', $mad['refunded_deposits']);
    }

    public function test_expenses_are_counted_separately_by_business_status(): void
    {
        $fixture = $this->fixture();
        $this->expense($fixture, 'draft', '10.00');
        $this->expense($fixture, 'approved', '20.00');
        $this->expense($fixture, 'rejected', '30.00');

        $mad = $this->report($fixture, '2026-08-01', '2026-08-31')['financial']['currencies']['MAD'];

        $this->assertSame(['draft' => 1, 'approved' => 1, 'rejected' => 1], $mad['expenses']);
        $this->assertSame('20.00', $mad['approved_expenses']);
    }

    public function test_financial_amounts_are_never_consolidated_across_currencies(): void
    {
        $fixture = $this->fixture();
        $this->invoice($fixture, '100.00', 'MAD');
        $this->invoice($fixture, '50.00', 'EUR');

        $currencies = $this->report($fixture, '2026-08-01', '2026-08-31')['financial']['currencies'];

        $this->assertSame(['EUR', 'MAD'], array_keys($currencies));
        $this->assertSame('50.00', $currencies['EUR']['invoiced_amount']);
        $this->assertSame('100.00', $currencies['MAD']['invoiced_amount']);
    }

    public function test_csv_cells_neutralize_all_spreadsheet_formula_prefixes(): void
    {
        foreach (['=SUM(A1)', '+1', '-1', '@cmd', "\tvalue", "\rvalue", "\nvalue"] as $value) {
            $this->assertStringStartsWith("'", SpreadsheetSafeCsv::cell($value));
        }
        $this->assertSame('Valeur sûre', SpreadsheetSafeCsv::cell('Valeur sûre'));
    }

    public function test_report_export_contains_no_private_or_sensitive_fields(): void
    {
        $fixture = $this->fixture();
        $response = $this->actingAs($fixture['user'])->get(route('reports.export', $this->filters()));
        $csv = $response->streamedContent();

        $response->assertOk()->assertHeader('content-type', 'text/csv; charset=UTF-8');
        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv);
        foreach (['identity_number', 'licence_number', 'password', 'token', 'storage/app/private'] as $sensitive) {
            $this->assertStringNotContainsString($sensitive, $csv);
        }
    }

    public function test_report_export_is_audited_without_exported_content(): void
    {
        $fixture = $this->fixture();
        $this->actingAs($fixture['user'])->get(route('reports.export', $this->filters()))->assertOk();

        $audit = AuditLog::withoutGlobalScopes()->where('action', 'report.exported')->first();
        $this->assertNotNull($audit);
        $this->assertSame('csv-summary', $audit->new_values['format']);
        $this->assertArrayNotHasKey('content', $audit->new_values);
    }

    public function test_report_access_follows_the_six_role_rbac_matrix(): void
    {
        $allowed = ['tenant-owner', 'agency-manager', 'accountant', 'viewer-auditor'];
        foreach (['tenant-owner', 'agency-manager', 'rental-agent', 'fleet-manager', 'accountant', 'viewer-auditor'] as $role) {
            $fixture = $this->fixture($role);
            $response = $this->actingAs($fixture['user'])->get(route('reports.index', $this->filters()));
            $this->assertSame(in_array($role, $allowed, true) ? 200 : 403, $response->getStatusCode(), $role);
        }
    }

    public function test_dashboard_does_not_query_finance_without_financial_or_report_permission(): void
    {
        $fixture = $this->fixture('fleet-manager');
        $queries = [];
        DB::listen(function ($event) use (&$queries): void {
            $queries[] = $event->sql;
        });

        $this->actingAs($fixture['user'])->get(route('dashboard'))->assertOk();
        $sql = implode("\n", $queries);

        $this->assertStringNotContainsString('payment_allocations', $sql);
        $this->assertStringNotContainsString('from "invoices"', $sql);
    }

    public function test_doctor_is_non_destructive_and_reporting_checks_pass(): void
    {
        $fixture = $this->fixture();
        $before = [DB::table('tenants')->count(), DB::table('vehicles')->count(), DB::table('audit_logs')->count()];

        $exit = Artisan::call('rentfleet:doctor', ['--json' => true]);
        $output = Artisan::output();

        $this->assertSame(0, $exit, $output);
        $this->assertStringContainsString('Index du reporting', $output);
        $this->assertStringContainsString('Allocations financières', $output);
        $this->assertSame($before, [DB::table('tenants')->count(), DB::table('vehicles')->count(), DB::table('audit_logs')->count()]);
        $this->assertNotNull($fixture['tenant']);
    }

    public function test_required_expiration_commands_are_scheduled(): void
    {
        $commands = collect(app(Schedule::class)->events())->pluck('command')->implode("\n");

        $this->assertStringContainsString('reservations:expire-pending', $commands);
        $this->assertStringContainsString('insurance:expire-policies', $commands);
    }

    public function test_main_report_tables_do_not_introduce_a_major_n_plus_one(): void
    {
        $fixture = $this->fixture();
        foreach (range(1, 18) as $index) {
            $this->reservation($fixture, '2026-08-'.str_pad((string) $index, 2, '0', STR_PAD_LEFT).' 09:00:00', '2026-08-'.str_pad((string) $index, 2, '0', STR_PAD_LEFT).' 10:00:00');
        }
        $queries = 0;
        DB::listen(function () use (&$queries): void {
            $queries++;
        });

        $this->actingAs($fixture['user'])->get(route('reports.index', $this->filters()))->assertOk();

        $this->assertLessThan(60, $queries, 'Le rapport ne doit pas charger une requête par ligne.');
    }

    public function test_blade_report_keeps_filters_and_paginates_reservation_rows(): void
    {
        $fixture = $this->fixture();
        foreach (range(1, 17) as $index) {
            $this->reservation($fixture, '2026-08-'.str_pad((string) $index, 2, '0', STR_PAD_LEFT).' 09:00:00', '2026-08-'.str_pad((string) $index, 2, '0', STR_PAD_LEFT).' 10:00:00');
        }

        $response = $this->actingAs($fixture['user'])->get(route('reports.index', [...$this->filters(), 'currency' => 'MAD', 'reservations_page' => 2]));

        $response->assertOk()->assertViewIs('reports.index')->assertSee('Filtres actifs')->assertSee('Finance par devise');
        $this->assertSame(2, $response->viewData('reservationRows')->currentPage());
        $this->assertSame(2, $response->viewData('reservationRows')->count());
        $response->assertSee('currency=MAD', false);
    }

    private function fixture(string $roleSlug = 'tenant-owner', bool $createVehicle = true): array
    {
        $tenant = Tenant::factory()->create(['settings' => ['currency' => 'MAD', 'timezone' => 'Africa/Casablanca']]);
        $agency = app(TenantContext::class)->run($tenant, fn () => Agency::factory()->create());
        $role = Role::query()->where('slug', $roleSlug)->firstOrFail();
        $user = User::factory()->create([
            'tenant_id' => $tenant->id,
            'agency_id' => $roleSlug === 'tenant-owner' ? null : $agency->id,
            'role_id' => $role->id,
            'must_change_password' => false,
        ]);

        return app(TenantContext::class)->run($tenant, function () use ($tenant, $agency, $role, $user, $createVehicle): array {
            $category = VehicleCategory::create(['code' => 'D1-'.str()->random(8), 'name' => 'Catégorie D1', 'is_active' => true]);
            $customer = Customer::create(['agency_id' => $agency->id, 'customer_type' => 'individual', 'first_name' => 'Client', 'last_name' => 'D1', 'verification_status' => 'verified']);
            $vehicle = $createVehicle ? app(CreateVehicle::class)->handle([
                'agency_id' => $agency->id,
                'vehicle_category_id' => $category->id,
                'registration_number' => 'D1-'.str()->random(10),
                'brand' => 'Dacia',
                'model' => 'Logan',
                'production_year' => 2026,
                'fuel_type' => 'diesel',
                'transmission' => 'manual',
                'current_mileage' => 1000,
            ], $user->id) : null;

            return compact('tenant', 'agency', 'role', 'user', 'category', 'customer', 'vehicle');
        }, $agency->id);
    }

    private function vehicleForAgency(array $fixture, Agency $agency): Vehicle
    {
        return app(TenantContext::class)->run($fixture['tenant'], fn () => app(CreateVehicle::class)->handle([
            'agency_id' => $agency->id,
            'vehicle_category_id' => $fixture['category']->id,
            'registration_number' => 'D1-'.str()->random(10),
            'brand' => 'Renault',
            'model' => 'Clio',
            'production_year' => 2026,
            'fuel_type' => 'petrol',
            'transmission' => 'manual',
            'current_mileage' => 500,
        ], $fixture['user']->id), $agency->id);
    }

    private function reservation(array $fixture, string $start, string $end): Reservation
    {
        return app(TenantContext::class)->run($fixture['tenant'], fn () => Reservation::create([
            'agency_id' => $fixture['agency']->id,
            'customer_id' => $fixture['customer']->id,
            'vehicle_category_id' => $fixture['category']->id,
            'vehicle_id' => $fixture['vehicle']?->id,
            'reservation_number' => 'RES-D1-'.str()->random(10),
            'starts_at' => CarbonImmutable::parse($start, 'Africa/Casablanca'),
            'ends_at' => CarbonImmutable::parse($end, 'Africa/Casablanca'),
            'status' => 'draft',
            'subtotal' => '0.00',
            'options_total' => '0.00',
            'total_amount' => '0.00',
            'deposit_amount' => '0.00',
            'currency' => 'MAD',
            'pricing_snapshot' => [],
            'created_by' => $fixture['user']->id,
        ]), $fixture['agency']->id);
    }

    private function block(array $fixture, string $start, string $end, string $type = 'manual', string $status = 'active'): VehicleBlock
    {
        return app(TenantContext::class)->run($fixture['tenant'], fn () => VehicleBlock::create([
            'agency_id' => $fixture['agency']->id,
            'vehicle_id' => $fixture['vehicle']->id,
            'block_type' => $type,
            'starts_at' => CarbonImmutable::parse($start, 'Africa/Casablanca'),
            'ends_at' => CarbonImmutable::parse($end, 'Africa/Casablanca'),
            'status' => $status,
            'reason' => $type === 'manual' ? 'Bloc de test D1' : null,
            'created_by' => $fixture['user']->id,
            'released_at' => $status === 'active' ? null : CarbonImmutable::parse($end, 'Africa/Casablanca'),
            'rental_contract_id' => $type === 'contract' ? $this->contract($fixture)->id : null,
        ]), $fixture['agency']->id);
    }

    private function contract(array $fixture, string $currency = 'MAD'): RentalContract
    {
        $reservation = $this->reservation($fixture, '2026-08-10 09:00:00', '2026-08-11 09:00:00');

        return app(TenantContext::class)->run($fixture['tenant'], fn () => RentalContract::create([
            'agency_id' => $fixture['agency']->id,
            'reservation_id' => $reservation->id,
            'customer_id' => $fixture['customer']->id,
            'vehicle_id' => $fixture['vehicle']->id,
            'contract_number' => 'CTR-D1-'.str()->random(10),
            'status' => 'draft',
            'expected_start_at' => $reservation->starts_at,
            'expected_return_at' => $reservation->ends_at,
            'rental_subtotal' => '100.00',
            'additional_charges_total' => '0.00',
            'total_amount' => '100.00',
            'deposit_required' => '0.00',
            'currency' => $currency,
            'created_by' => $fixture['user']->id,
        ]), $fixture['agency']->id);
    }

    private function invoice(array $fixture, string $amount, string $currency = 'MAD', string $status = 'issued'): Invoice
    {
        $contract = $this->contract($fixture, $currency);

        return app(TenantContext::class)->run($fixture['tenant'], fn () => Invoice::create([
            'agency_id' => $fixture['agency']->id,
            'rental_contract_id' => $contract->id,
            'customer_id' => $fixture['customer']->id,
            'invoice_number' => 'INV-D1-'.str()->random(10),
            'status' => $status,
            'issued_at' => CarbonImmutable::parse('2026-08-15 10:00:00', 'Africa/Casablanca'),
            'due_at' => CarbonImmutable::parse('2026-08-30 10:00:00', 'Africa/Casablanca'),
            'currency' => $currency,
            'tax_mode' => 'none',
            'tax_rate' => '0.0000',
            'subtotal' => $amount,
            'tax_amount' => '0.00',
            'total_amount' => $amount,
            'paid_amount' => '0.00',
            'balance_due' => $amount,
            'customer_snapshot' => [],
            'contract_snapshot' => [],
            'created_by' => $fixture['user']->id,
            'issued_by' => $fixture['user']->id,
        ]), $fixture['agency']->id);
    }

    private function payment(array $fixture, Invoice $invoice, string $amount, string $status, string $direction = 'incoming', ?Payment $reversalOf = null): Payment
    {
        return app(TenantContext::class)->run($fixture['tenant'], function () use ($fixture, $invoice, $amount, $status, $direction, $reversalOf): Payment {
            $payment = Payment::create([
                'agency_id' => $fixture['agency']->id,
                'rental_contract_id' => $invoice->rental_contract_id,
                'customer_id' => $fixture['customer']->id,
                'payment_number' => 'PAY-D1-'.str()->random(10),
                'direction' => $direction,
                'payment_method' => 'cash',
                'status' => $status,
                'amount' => $amount,
                'currency' => $invoice->currency,
                'idempotency_key' => 'd1-'.str()->uuid(),
                'paid_at' => CarbonImmutable::parse('2026-08-15 11:00:00', 'Africa/Casablanca'),
                'posted_at' => $status === 'pending' ? null : CarbonImmutable::parse('2026-08-15 11:00:00', 'Africa/Casablanca'),
                'reversal_of_id' => $reversalOf?->id,
                'created_by' => $fixture['user']->id,
                'posted_by' => $status === 'pending' ? null : $fixture['user']->id,
            ]);
            PaymentAllocation::create([
                'agency_id' => $fixture['agency']->id,
                'customer_id' => $fixture['customer']->id,
                'currency' => $invoice->currency,
                'payment_id' => $payment->id,
                'invoice_id' => $invoice->id,
                'amount' => $amount,
            ]);

            return $payment;
        }, $fixture['agency']->id);
    }

    private function deposit(array $fixture, RentalContract $contract, string $type, string $amount, ?DepositTransaction $reversalOf = null): DepositTransaction
    {
        return app(TenantContext::class)->run($fixture['tenant'], fn () => DepositTransaction::create([
            'agency_id' => $fixture['agency']->id,
            'rental_contract_id' => $contract->id,
            'transaction_number' => 'DEP-D1-'.str()->random(10),
            'transaction_type' => $type,
            'amount' => $amount,
            'currency' => $contract->currency,
            'reversal_of_id' => $reversalOf?->id,
            'idempotency_key' => 'd1-'.str()->uuid(),
            'occurred_at' => CarbonImmutable::parse('2026-08-16 10:00:00', 'Africa/Casablanca'),
            'reason' => 'Test D1',
            'created_by' => $fixture['user']->id,
        ]), $fixture['agency']->id);
    }

    private function expense(array $fixture, string $status, string $amount): Expense
    {
        return app(TenantContext::class)->run($fixture['tenant'], fn () => Expense::create([
            'agency_id' => $fixture['agency']->id,
            'expense_number' => 'EXP-D1-'.str()->random(10),
            'category' => 'administration',
            'description' => 'Dépense D1',
            'amount' => $amount,
            'tax_amount' => '0.00',
            'currency' => 'MAD',
            'expense_date' => '2026-08-15',
            'status' => $status,
            'created_by' => $fixture['user']->id,
            'approved_by' => $status === 'approved' ? $fixture['user']->id : null,
            'rejected_by' => $status === 'rejected' ? $fixture['user']->id : null,
            'rejected_at' => $status === 'rejected' ? now() : null,
            'rejection_reason' => $status === 'rejected' ? 'Refus D1' : null,
        ]), $fixture['agency']->id);
    }

    private function report(array $fixture, string $from, string $to, ?string $currency = null, ?array $agencyIds = null): array
    {
        $criteria = $this->criteria($fixture, $from, $to, $currency, $agencyIds);

        return app(TenantContext::class)->run(
            $fixture['tenant'],
            fn () => app(BuildMinimalReport::class)->handle($criteria),
            $fixture['user']->agency_id,
        );
    }

    private function criteria(array $fixture, string $from, string $to, ?string $currency = null, ?array $agencyIds = null): ReportCriteria
    {
        return ReportCriteria::fromInclusiveDates(
            $fixture['tenant']->id,
            $agencyIds ?? [$fixture['agency']->id],
            $from,
            $to,
            'Africa/Casablanca',
            $currency,
        );
    }

    private function filters(): array
    {
        return ['date_from' => '2026-08-01', 'date_to' => '2026-08-31'];
    }

    private function freezeAt(string $date): void
    {
        $time = CarbonImmutable::parse($date, 'Africa/Casablanca');
        Carbon::setTestNow($time);
        CarbonImmutable::setTestNow($time);
    }
}
