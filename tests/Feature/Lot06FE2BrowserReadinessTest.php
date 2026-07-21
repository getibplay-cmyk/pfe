<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

class Lot06FE2BrowserReadinessTest extends TestCase
{
    public function test_priority_validation_feedback_is_french_and_uses_a_readable_attribute(): void
    {
        app()->setLocale('fr');

        $message = Validator::make(
            ['customer_id' => null],
            ['customer_id' => ['required']],
        )->errors()->first('customer_id');

        $this->assertSame('Le champ client est obligatoire.', $message);
    }

    public function test_reservation_errors_are_linked_to_fields_and_receive_focus_support(): void
    {
        $view = File::get(resource_path('views/reservations/form.blade.php'));
        $javascript = File::get(resource_path('js/app.js'));

        $this->assertStringContainsString('<x-form-errors />', $view);
        $this->assertStringContainsString('aria-invalid="true"', $view);
        $this->assertStringContainsString('aria-describedby="reservation-customer-error"', $view);
        $this->assertStringContainsString('<x-field-error id="reservation-customer-error"', $view);
        $this->assertStringContainsString('document.querySelector(\'[aria-invalid="true"]\')', $javascript);
        $this->assertStringContainsString('scrollIntoView', $javascript);
    }

    public function test_menus_restore_focus_and_tables_keep_mobile_scroll_available(): void
    {
        $layout = File::get(resource_path('views/layouts/app.blade.php'));
        $styles = File::get(resource_path('css/app.css'));
        $maintenance = File::get(resource_path('views/maintenance/index.blade.php'));
        $users = File::get(resource_path('views/users/index.blade.php'));

        $this->assertStringContainsString('x-ref="userMenuButton"', $layout);
        $this->assertStringContainsString('if (mobileMenu) closeMenu()', $layout);
        $this->assertStringContainsString('aria-controls="menu-utilisateur"', $layout);
        $this->assertStringContainsString('overflow-x: clip', $styles);
        $this->assertStringContainsString('overflow-x-auto', $styles);
        $this->assertStringContainsString('<x-responsive-table label="Ordres de maintenance">', $maintenance);
        $this->assertStringContainsString('<x-responsive-table label="Utilisateurs">', $users);
    }

    public function test_observed_unlabelled_controls_now_have_accessible_names(): void
    {
        $customer = File::get(resource_path('views/customers/show.blade.php'));
        $insurance = File::get(resource_path('views/insurance/index.blade.php'));
        $finance = File::get(resource_path('views/contracts/partials/finance.blade.php'));
        $contract = File::get(resource_path('views/contracts/show.blade.php'));

        foreach (['customer-rejection-reason', 'driver-document-title-', 'driver-document-file-', 'customer-document-file'] as $id) {
            $this->assertStringContainsString($id, $customer);
        }
        foreach (['insurance-company-search', 'insurance-policy-search', 'insurance-policy-status', 'insurance-policy-type', 'insurance-claim-search', 'insurance-claim-status'] as $id) {
            $this->assertStringContainsString($id, $insurance);
        }
        $this->assertStringContainsString('deposit-reversal-reason-', $finance);
        foreach (['damage-description', 'damage-vehicle-area', 'damage-severity', 'damage-estimated-cost', 'cleaning-amount', 'return-reason'] as $id) {
            $this->assertStringContainsString($id, $contract);
        }
        $this->assertStringContainsString('UiLabel::get($inspection->inspection_type)', $contract);
    }

    public function test_real_browser_result_and_screenshot_manifest_are_complete_and_safe(): void
    {
        $resultPath = base_path('docs/evidence/browser-data/lot06f-e2-browser-results.json');
        $this->assertFileExists($resultPath);

        $raw = File::get($resultPath);
        $result = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
        $failedChecks = array_filter($result['checks'], fn (array $check): bool => ! $check['passed']);
        $majorIssues = array_filter(
            $result['issues'],
            fn (array $issue): bool => in_array($issue['severity'], ['bloquant', 'majeur'], true),
        );

        $this->assertSame('rentfleet_test', $result['qa_database']);
        $this->assertArrayHasKey('chrome', $result['browsers']);
        $this->assertArrayHasKey('edge', $result['browsers']);
        $this->assertCount(258, $result['checks']);
        $this->assertCount(51, $result['page_audits']);
        $this->assertCount(29, $result['screenshots']);
        $this->assertSame([], array_values($failedChecks));
        $this->assertSame([], array_values($majorIssues));

        foreach ($result['screenshots'] as $screenshot) {
            $this->assertStringStartsWith('docs/evidence/screenshots/lot06f-e2/', $screenshot);
            $this->assertFileExists(base_path($screenshot));
            $this->assertSame("\x89PNG\r\n\x1a\n", substr(File::get(base_path($screenshot)), 0, 8));
        }

        foreach (['DB_PASSWORD', 'APP_KEY=', 'BEGIN PRIVATE KEY', 'E2_QA_PASSWORD'] as $sensitiveMarker) {
            $this->assertStringNotContainsString($sensitiveMarker, $raw);
        }
    }
}
