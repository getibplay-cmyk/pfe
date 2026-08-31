<?php

namespace Tests\Feature;

use Tests\TestCase;

class BelkhirSpaceVisualWorkflowTest extends TestCase
{
    public function test_authenticated_layout_registers_the_shared_belkhir_space_interactions(): void
    {
        $app = file_get_contents(resource_path('js/app.js'));
        $layout = file_get_contents(resource_path('views/layouts/app.blade.php'));

        $this->assertStringContainsString("import { registerBelkhirSpaceUi } from './belkhir-space-ui';", $app);
        $this->assertStringContainsString('registerBelkhirSpaceUi(Alpine);', $app);
        $this->assertStringContainsString('const belkhirSpaceLoading = initializeBelkhirSpaceLoading();', $app);
        $this->assertStringContainsString('initializeLoadingForms(document, window, belkhirSpaceLoading);', $app);
        $this->assertStringContainsString('<x-belkhir-space-loading />', $layout);
        $this->assertStringContainsString('<x-confirm-dialog />', $layout);

        $loading = file_get_contents(resource_path('views/components/belkhir-space-loading.blade.php'));
        $this->assertStringContainsString('data-belkhir-space-progress', $loading);
        $this->assertStringContainsString('data-belkhir-space-loading-overlay', $loading);
        $this->assertSame(2, preg_match_all('/^\s{4}hidden$/m', $loading));
    }

    public function test_real_progress_is_used_only_with_a_known_denominator(): void
    {
        $report = file_get_contents(resource_path('views/reports/index.blade.php'));
        $tenant = file_get_contents(resource_path('views/platform/tenants/show.blade.php'));
        $reallocation = file_get_contents(resource_path('views/fleet/reallocation-planning/index.blade.php'));

        $this->assertStringContainsString('<x-progress-bar', $report);
        $this->assertStringContainsString(':max="100"', $report);
        $this->assertStringContainsString('$capabilityTotal > 0', $tenant);
        $this->assertStringContainsString(':max="$capabilityTotal"', $tenant);
        $this->assertStringNotContainsString('progressbar', $reallocation);
        $this->assertStringContainsString('<x-spinner', $reallocation);
    }

    public function test_private_document_forms_keep_their_names_and_gain_accessible_file_inputs(): void
    {
        $vehicle = file_get_contents(resource_path('views/vehicles/show.blade.php'));
        $document = file_get_contents(resource_path('views/documents/show.blade.php'));
        $contract = file_get_contents(resource_path('views/contracts/show.blade.php'));

        $this->assertStringContainsString('id="vehicle-document-file" name="file"', $vehicle);
        $this->assertStringContainsString('enctype="multipart/form-data"', $vehicle);
        $this->assertStringContainsString('id="document_version" name="file"', $document);
        $this->assertStringContainsString('x-belkhir-space-confirm', $document);
        $this->assertStringContainsString('name="file"', $contract);
        $this->assertStringContainsString('preview="image" fit="contain"', $contract);
        $this->assertStringContainsString('data-loading-form', $contract);
    }

    public function test_vehicle_photo_assistants_preview_without_changing_existing_refs(): void
    {
        $view = file_get_contents(resource_path('views/vehicles/form.blade.php'));
        $color = file_get_contents(resource_path('js/vehicle-color-assistant.js'));
        $registration = file_get_contents(resource_path('js/vehicle-registration-assistant.js'));

        $this->assertStringContainsString('x-ref="fullPhoto"', $view);
        $this->assertStringContainsString('x-ref="closeUpPhoto"', $view);
        $this->assertStringContainsString('x-ref="colorPhoto"', $view);
        $this->assertStringContainsString('object-contain', $view);
        $this->assertStringContainsString('createObjectURL', $color);
        $this->assertStringContainsString('revokeObjectURL', $color);
        $this->assertStringContainsString('createObjectURL', $registration);
        $this->assertStringContainsString('revokeObjectURL', $registration);
    }

    public function test_filters_and_long_actions_share_loading_protection(): void
    {
        foreach ([
            'views/reservations/index.blade.php',
            'views/vehicles/index.blade.php',
            'views/contracts/index.blade.php',
            'views/reports/index.blade.php',
            'views/intelligence/rental-usage-anomalies/index.blade.php',
            'views/platform/tenants/index.blade.php',
        ] as $path) {
            $view = file_get_contents(resource_path($path));

            $this->assertStringContainsString('<x-filter-panel', $view, $path);
            $this->assertStringContainsString('data-loading-form', $view, $path);
        }

        $statistics = file_get_contents(resource_path('views/platform/statistics.blade.php'));
        $this->assertStringContainsString('platform-statistics-filters-title', $statistics);
        $this->assertStringContainsString('x-on:submit="if (submitting)', $statistics);
        $this->assertStringContainsString('<x-spinner', $statistics);
    }

    public function test_attachment_responses_are_excluded_from_global_loading(): void
    {
        foreach ([
            'views/reservations/index.blade.php',
            'views/reports/index.blade.php',
            'views/documents/show.blade.php',
            'views/intelligence/index.blade.php',
            'views/intelligence/result-batches/index.blade.php',
            'views/intelligence/demand-forecasts/index.blade.php',
            'views/intelligence/fleet-reallocation/index.blade.php',
        ] as $path) {
            $view = file_get_contents(resource_path($path));

            $this->assertStringContainsString('data-no-global-loading="true"', $view, $path);
        }
    }
}
