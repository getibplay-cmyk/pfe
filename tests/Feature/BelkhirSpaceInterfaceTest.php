<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

class BelkhirSpaceInterfaceTest extends TestCase
{
    public function test_belkhir_space_tokens_and_shared_interface_primitives_are_centralized(): void
    {
        $tokens = file_get_contents(resource_path('js/belkhir-space-tokens.js'));
        $css = file_get_contents(resource_path('css/app.css'));

        foreach (['#0B1220', '#111827', '#1D4ED8', '#1E40AF', '#C2410C', '#FFEDD5', '#F8FAFC', '#FFFFFF', '#64748B', '#D9E2EC', '#15803D', '#B45309', '#B91C1C', '#0369A1'] as $color) {
            $this->assertStringContainsString($color, $tokens);
        }

        $this->assertStringContainsString('prefers-reduced-motion: reduce', $css);
        $this->assertStringContainsString('.rf-button-quiet', $css);
        $this->assertStringContainsString('.rf-chart-surface', $css);
        $this->assertStringContainsString('space: BELKHIR_SPACE_COLORS', file_get_contents(base_path('tailwind.config.js')));
        $this->assertStringContainsString("theme('colors.belkhir.space.blue')", $css);
        $this->assertFileExists(resource_path('views/components/loading-state.blade.php'));
        $this->assertFileExists(resource_path('views/components/quiet-button.blade.php'));
        $this->assertStringNotContainsString('@import url(', $css);
    }

    public function test_breadcrumb_has_ordered_accessible_structure_and_non_link_current_page(): void
    {
        $html = Blade::render(<<<'BLADE'
            <x-breadcrumbs :items="[
                ['label' => 'Réservations', 'url' => '/reservations'],
                ['label' => 'RES-2026-000001'],
            ]" />
        BLADE);

        $this->assertStringContainsString('<nav aria-label="Fil d’Ariane"', $html);
        $this->assertStringContainsString('<ol', $html);
        $this->assertStringContainsString('href="/reservations"', $html);
        $this->assertStringContainsString('aria-current="page"', $html);
        $this->assertSame(1, substr_count($html, 'href='));
        $this->assertStringContainsString('aria-hidden="true"', $html);
    }

    public function test_navigation_keeps_one_rbac_source_and_uses_business_groups(): void
    {
        $builder = file_get_contents(app_path('Support/Ui/NavigationBuilder.php'));
        $layout = file_get_contents(resource_path('views/layouts/app.blade.php'));
        $mobile = file_get_contents(resource_path('views/components/mobile-navigation.blade.php'));

        foreach (['Activité locative', 'Parc automobile', 'Finance', 'Aide à la décision', 'Administration'] as $group) {
            $this->assertStringContainsString($group, $builder);
        }

        $this->assertStringContainsString("['customers.*', 'drivers.*']", $builder);
        $this->assertStringContainsString(':sections="$navigationSections"', $layout);
        $this->assertStringContainsString('<x-navigation-item :item="$item" surface="desktop"', $layout);
        $this->assertStringContainsString('<x-navigation-item :item="$item" surface="mobile"', $mobile);
    }

    public function test_mobile_shell_and_loading_states_expose_accessible_controls(): void
    {
        $layout = file_get_contents(resource_path('views/layouts/app.blade.php'));
        $mobile = file_get_contents(resource_path('views/components/mobile-navigation.blade.php'));
        $loading = Blade::render('<x-loading-state message="Préparation du tableau…" />');

        $this->assertStringContainsString('aria-expanded', $layout);
        $this->assertStringContainsString('aria-controls="navigation-mobile"', $layout);
        $this->assertStringContainsString('@keydown.escape.window', $layout);
        $this->assertStringContainsString('role="dialog"', $mobile);
        $this->assertStringContainsString('aria-modal="true"', $mobile);
        $this->assertStringContainsString('role="status"', $loading);
        $this->assertStringContainsString('aria-live="polite"', $loading);
    }

    public function test_charts_keep_accessible_tables_and_user_facing_labels_are_cleaned(): void
    {
        $statistics = file_get_contents(resource_path('views/platform/statistics.blade.php'));
        $navigation = file_get_contents(app_path('Support/Ui/NavigationBuilder.php'));
        $intelligence = file_get_contents(resource_path('views/intelligence/index.blade.php'));

        $this->assertSame(4, substr_count($statistics, '<canvas class="opacity-0 transition-opacity duration-500 motion-reduce:transition-none" role="img"'));
        $this->assertGreaterThanOrEqual(5, substr_count($statistics, '<table'));
        $this->assertStringContainsString('<x-progress-bar', $statistics);
        $this->assertStringContainsString('data-chart-skeleton', $statistics);
        $this->assertStringContainsString('motion-reduce:transition-none', $statistics);
        $this->assertStringContainsString('Opérations en attente', $statistics);
        $this->assertStringContainsString('Analyses et prévisions', $navigation);
        $this->assertStringContainsString('Modèles IA et accès', $navigation);
        $this->assertStringContainsString('title="Aide à la décision"', $intelligence);
        $this->assertStringNotContainsString('title="Intelligence et export anonymisé"', $intelligence);
    }

    public function test_operational_pages_use_business_language_and_a_real_loading_state(): void
    {
        $reservations = file_get_contents(resource_path('views/reservations/index.blade.php'));
        $operationalPages = $reservations
            .file_get_contents(resource_path('views/fleet/reallocation-planning/index.blade.php'))
            .file_get_contents(resource_path('views/vehicles/form.blade.php'))
            .file_get_contents(resource_path('views/contracts/show.blade.php'))
            .file_get_contents(resource_path('views/platform/statistics.blade.php'));

        $this->assertStringContainsString('<x-loading-state', $reservations);
        $this->assertStringNotContainsString('x-cloak x-show="forecasts.length === 7"', $reservations);

        foreach (['HGB', 'OR-Tools', 'ANPR', 'RT-DETR', 'Runtime', 'worker', 'Feature flag', 'checkpoint', 'SHA-256', 'ONNX'] as $technicalTerm) {
            $this->assertStringNotContainsString($technicalTerm, $operationalPages);
        }
    }
}
