<?php

namespace Tests\Feature;

use Tests\TestCase;

class BelkhirSpaceVisualStatisticsTest extends TestCase
{
    public function test_platform_statistics_use_only_available_aggregates_with_accessible_fallbacks(): void
    {
        $view = file_get_contents(resource_path('views/platform/statistics.blade.php'));

        foreach (['tenant_states', 'subscription_states', 'monthly_runs', 'activations'] as $aggregate) {
            $this->assertStringContainsString("\$statistics['{$aggregate}']", $view);
        }

        $this->assertStringContainsString("\$statistics['payments']['currencies']", $view);
        $this->assertStringContainsString("\$statistics['totals']['tenants']", $view);
        $this->assertStringContainsString("\$statistics['totals']['active_tenants']", $view);
        $this->assertSame(4, substr_count($view, 'data-platform-chart='));
        $this->assertSame(4, substr_count($view, 'data-chart-skeleton'));
        $this->assertGreaterThanOrEqual(4, substr_count($view, 'aria-describedby='));
        $this->assertGreaterThanOrEqual(5, substr_count($view, '<table'));
        $this->assertStringContainsString('$tenantTotal > 0', $view);
        $this->assertStringNotContainsString('Paiements toutes devises', $view);
    }

    public function test_platform_and_tenant_dashboards_use_icon_kpis_and_real_denominators(): void
    {
        $platform = file_get_contents(resource_path('views/platform/dashboard.blade.php'));
        $tenant = file_get_contents(resource_path('views/dashboard.blade.php'));

        $this->assertStringContainsString('$capabilitySlots = $tenantTotal * count($statistics[\'activations\'])', $platform);
        $this->assertStringContainsString(':max="$capabilitySlots"', $platform);
        $this->assertStringContainsString(':max="$tenantTotal"', $platform);
        $this->assertSame(2, substr_count($platform, 'data-platform-chart='));
        $this->assertSame(2, substr_count($platform, 'data-chart-skeleton'));
        $this->assertStringContainsString('icon="chart"', $tenant);
        $this->assertSame(2, substr_count($tenant, '<x-tenant-chart-card'));
        $this->assertStringContainsString("\$dashboardStatistics['availability']['total']", $tenant);
        $this->assertStringContainsString('motion-reduce:transition-none', $platform.$tenant);

        $statistics = file_get_contents(resource_path('views/platform/statistics.blade.php'));
        $this->assertSame(6, substr_count($platform.$statistics, 'data-platform-chart='));
    }

    public function test_tenant_dashboard_and_reports_use_bounded_real_charts_with_accessible_fallbacks(): void
    {
        $dashboard = file_get_contents(resource_path('views/dashboard.blade.php'));
        $report = file_get_contents(resource_path('views/reports/index.blade.php'));
        $component = file_get_contents(resource_path('views/components/tenant-chart-card.blade.php'));
        $presenter = file_get_contents(app_path('Support/Reporting/BelkhirSpaceReportPresenter.php'));

        $this->assertSame(2, substr_count($dashboard, '<x-tenant-chart-card'));
        $this->assertSame(3, substr_count($report, '<x-tenant-chart-card'));
        $this->assertStringContainsString('data-chart-skeleton', $component);
        $this->assertStringContainsString('aria-describedby', $component);
        $this->assertStringContainsString('<table', $component);
        $this->assertStringContainsString('<caption', $component);
        $this->assertStringContainsString('Aucune donnée sur cette période', $component);
        $this->assertStringContainsString("Période du {{ \$period['from'] }} au {{ \$period['to'] }}", $component);
        $this->assertStringContainsString('unité : {{ mb_strtolower($unit) }}', $component);
        $this->assertStringContainsString("\$fleetTotal = \$this->validatedCount(\$fleet['total'], 'fleet.total')", $presenter);
        $this->assertStringNotContainsString("(int) \$fleet['total']", $presenter);
        foreach (['DB::', '::query(', '->where(', '::where('] as $queryMarker) {
            $this->assertStringNotContainsString($queryMarker, $dashboard.$report.$component);
        }
    }

    public function test_chart_runtime_destroys_instances_and_honours_reduced_motion(): void
    {
        $runtime = file_get_contents(resource_path('js/platform-statistics.js'));

        $this->assertStringContainsString('ChartClass.getChart(canvas)?.destroy()', $runtime);
        $this->assertStringContainsString('(prefers-reduced-motion: reduce)', $runtime);
        $this->assertStringContainsString('duration: 500', $runtime);
        $this->assertStringContainsString('skeleton.hidden = true', $runtime);
    }

    public function test_progress_component_exposes_label_fraction_percentage_bar_and_aria_contract(): void
    {
        $component = file_get_contents(resource_path('views/components/progress-bar.blade.php'));

        $this->assertStringContainsString('{{ $label }}', $component);
        $this->assertStringContainsString("\$fraction.' · '.\$progress['percentage']", $component);
        $this->assertStringContainsString('role="progressbar"', $component);
        $this->assertStringContainsString('aria-valuemin="0"', $component);
        $this->assertStringContainsString('aria-valuemax=', $component);
        $this->assertStringContainsString('aria-valuenow=', $component);
        $this->assertStringContainsString('aria-valuetext=', $component);
        $this->assertStringContainsString("style=\"width: {{ \$progress['width'] }}%\"", $component);
    }
}
