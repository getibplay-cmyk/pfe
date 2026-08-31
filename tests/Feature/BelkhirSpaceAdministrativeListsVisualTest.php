<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class BelkhirSpaceAdministrativeListsVisualTest extends TestCase
{
    public function test_administrative_lists_use_shared_belkhir_space_components_and_keep_authorization_guards(): void
    {
        foreach ([
            'agencies' => ['create', 'update'],
            'users' => ['create', 'update'],
            'vehicle-categories' => ['create', 'update'],
        ] as $view => $abilities) {
            $source = File::get(resource_path('views/'.$view.'/index.blade.php'));

            $this->assertNotEmpty(Blade::compileString($source), $view);
            $this->assertStringContainsString('<x-page-header', $source, $view);
            $this->assertStringContainsString('<x-responsive-table', $source, $view);
            $this->assertStringContainsString('<x-empty-state', $source, $view);
            $this->assertStringContainsString('<x-icon-button', $source, $view);

            foreach ($abilities as $ability) {
                $this->assertStringContainsString("@can('{$ability}'", $source, $view.' '.$ability);
            }
        }
    }

    public function test_supported_filters_keep_their_original_query_names_and_loading_guard(): void
    {
        $agencies = File::get(resource_path('views/agencies/index.blade.php'));
        $users = File::get(resource_path('views/users/index.blade.php'));
        $categories = File::get(resource_path('views/vehicle-categories/index.blade.php'));

        foreach (['name="q"', 'name="status"'] as $field) {
            $this->assertStringContainsString($field, $agencies);
        }

        foreach (['name="q"', 'name="role_id"', 'name="status"'] as $field) {
            $this->assertStringContainsString($field, $users);
        }

        $this->assertStringContainsString("route('agencies.index')", $agencies);
        $this->assertStringContainsString("route('users.index')", $users);
        $this->assertStringContainsString('data-loading-form', $agencies);
        $this->assertStringContainsString('data-loading-form', $users);
        $this->assertStringNotContainsString('method="GET"', $categories);
    }

    public function test_repeated_actions_are_icon_buttons_with_precise_business_labels(): void
    {
        $agencies = File::get(resource_path('views/agencies/index.blade.php'));
        $users = File::get(resource_path('views/users/index.blade.php'));
        $categories = File::get(resource_path('views/vehicle-categories/index.blade.php'));

        $this->assertStringContainsString('Consulter l’agence ', $agencies);
        $this->assertStringContainsString('Modifier l’agence ', $agencies);
        $this->assertStringContainsString('Modifier l’utilisateur ', $users);
        $this->assertStringContainsString('Modifier la catégorie ', $categories);

        $this->assertStringNotContainsString('class="text-indigo-700"', $agencies);
        $this->assertStringNotContainsString('class="rf-button-link"', $users);
        $this->assertStringNotContainsString('class="text-blue-700"', $categories);
    }
}
