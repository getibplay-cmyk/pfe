<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

class BelkhirSpaceVisualComponentsTest extends TestCase
{
    public function test_icon_buttons_keep_accessible_names_tooltips_and_link_semantics(): void
    {
        $button = Blade::render('<x-icon-button icon="trash" label="Supprimer le document" variant="danger" />');
        $link = Blade::render('<x-icon-button icon="view" label="Afficher le véhicule" href="/vehicles/one" />');

        $this->assertStringContainsString('<button', $button);
        $this->assertStringContainsString('type="button"', $button);
        $this->assertStringContainsString('aria-label="Supprimer le document"', $button);
        $this->assertStringContainsString('title="Supprimer le document"', $button);
        $this->assertStringContainsString('role="tooltip"', $button);
        $this->assertStringContainsString('group-focus-within:block', $button);
        $this->assertStringContainsString('h-11 w-11', $button);
        $this->assertStringContainsString('aria-hidden="true"', $button);
        $this->assertStringContainsString('focusable="false"', $button);
        $this->assertStringContainsString('<a', $link);
        $this->assertStringContainsString('href="/vehicles/one"', $link);
    }

    public function test_confirmation_dialog_is_accessible_and_does_not_use_browser_confirm(): void
    {
        $dialog = Blade::render('<x-confirm-dialog />');

        $this->assertStringContainsString('role="alertdialog"', $dialog);
        $this->assertStringContainsString('aria-modal="true"', $dialog);
        $this->assertStringContainsString('aria-labelledby="belkhir-space-confirm-dialog-title"', $dialog);
        $this->assertStringContainsString('x-ref="cancel"', $dialog);
        $this->assertStringContainsString('x-on:keydown.escape.window', $dialog);
        $this->assertStringNotContainsString('window.confirm', $dialog);
    }

    public function test_file_input_keeps_native_contract_and_exposes_local_preview(): void
    {
        $html = Blade::render(<<<'BLADE'
            <x-file-input
                id="vehicle-photo"
                name="photos[]"
                label="Photos du véhicule"
                accept="image/jpeg,image/png"
                :multiple="true"
                :required="true"
                preview="image"
                formats="JPEG, PNG"
                max-size="5 Mo"
            />
        BLADE);

        $this->assertStringContainsString('for="vehicle-photo"', $html);
        $this->assertStringContainsString('id="vehicle-photo"', $html);
        $this->assertStringContainsString('name="photos[]"', $html);
        $this->assertStringContainsString('accept="image/jpeg,image/png"', $html);
        $this->assertStringContainsString('multiple', $html);
        $this->assertStringContainsString('required', $html);
        $this->assertStringContainsString('type="file"', $html);
        $this->assertStringContainsString('class="peer sr-only"', $html);
        $this->assertStringContainsString(':src="previewUrl"', $html);
        $this->assertStringNotContainsString('display:none', $html);
        $this->assertStringNotContainsString('base64', $html);
    }

    public function test_photo_frames_and_gallery_preserve_complete_evidence(): void
    {
        $evidence = Blade::render('<x-photo-frame src="/private/evidence" alt="Photo de contrôle" kind="evidence" />');
        $gallery = Blade::render(<<<'BLADE'
            <x-photo-gallery id="inspection-gallery" :images="[
                ['src' => '/private/front', 'alt' => 'Vue avant'],
                ['src' => '/private/back', 'alt' => 'Vue arrière'],
            ]" />
        BLADE);

        $this->assertStringContainsString('object-contain', $evidence);
        $this->assertStringContainsString('aspect-[4/3]', $evidence);
        $this->assertStringContainsString('role="dialog"', $gallery);
        $this->assertStringContainsString('aria-modal="true"', $gallery);
        $this->assertStringContainsString('Photo précédente', $gallery);
        $this->assertStringContainsString('Photo suivante', $gallery);
        $this->assertStringContainsString('x-on:keydown.escape.window', $gallery);
        $this->assertStringNotContainsString('Storage::url', $gallery);
    }

    public function test_progress_and_skeleton_states_expose_real_accessible_values(): void
    {
        $progress = Blade::render('<x-progress-bar label="Fonctionnalités activées" :value="4" :max="6" value-text="4 / 6" />');
        $skeleton = Blade::render('<x-skeleton variant="chart" label="Chargement du graphique" />');

        $this->assertStringContainsString('role="progressbar"', $progress);
        $this->assertStringContainsString('aria-valuemin="0"', $progress);
        $this->assertStringContainsString('aria-valuemax="6"', $progress);
        $this->assertStringContainsString('aria-valuenow="4"', $progress);
        $this->assertStringContainsString('aria-valuetext="4 / 6 · 66,7 %"', $progress);
        $this->assertStringContainsString('width: 66.7%', $progress);
        $this->assertStringContainsString('role="status"', $skeleton);
        $this->assertStringContainsString('aria-live="polite"', $skeleton);
    }

    public function test_every_required_action_icon_is_available_from_the_central_component(): void
    {
        $source = file_get_contents(resource_path('views/components/icon.blade.php'));

        foreach (['view', 'edit', 'trash', 'download', 'upload', 'save', 'login', 'logout', 'launch', 'analysis', 'disable', 'print', 'plus', 'search', 'filter', 'reset', 'close', 'menu', 'calendar', 'chart', 'image', 'file', 'refresh', 'previous', 'next', 'success', 'warning', 'error', 'mail'] as $name) {
            $this->assertStringContainsString("@case('{$name}')", $source);
        }
    }

    public function test_primary_text_actions_receive_the_expected_semantic_icon(): void
    {
        foreach ([
            'Créer' => 'add',
            'Ajouter' => 'add',
            'Enregistrer' => 'save',
            'Actualiser' => 'refresh',
            'Générer' => 'chart',
            'Analyser' => 'analysis',
            'Importer' => 'upload',
            'Exporter' => 'download',
            'Télécharger' => 'download',
            'Imprimer' => 'print',
            'Appliquer les filtres' => 'filter',
            'Réinitialiser' => 'reset',
            'Se connecter' => 'login',
            'Désactiver' => 'disable',
            'Supprimer' => 'delete',
        ] as $label => $icon) {
            $html = Blade::render('<x-submit-button :label="$label" />', compact('label'));

            $this->assertStringContainsString("data-icon=\"{$icon}\"", $html, $label);
        }

        $this->assertStringContainsString(
            'name="previous"',
            file_get_contents(resource_path('views/reservations/form.blade.php')),
        );
    }

    public function test_intelligence_media_forms_keep_their_http_contract_and_private_routes(): void
    {
        $pages = [
            'vehicle-colors' => file_get_contents(resource_path('views/intelligence/vehicle-colors/index.blade.php')),
            'vehicle-plates' => file_get_contents(resource_path('views/intelligence/vehicle-plates/index.blade.php')),
            'vehicle-damages' => file_get_contents(resource_path('views/intelligence/vehicle-damages/index.blade.php')),
        ];

        foreach ($pages as $page) {
            $this->assertStringContainsString('name="image"', $page);
            $this->assertStringContainsString('accept="image/jpeg,image/png,image/webp"', $page);
            $this->assertStringContainsString('data-loading-form', $page);
            $this->assertStringContainsString('<x-file-input', $page);
            $this->assertStringContainsString('<x-submit-button', $page);
            $this->assertStringNotContainsString('Storage::url', $page);
        }

        $this->assertStringContainsString("route('intelligence.vehicle-colors.input', \$run)", $pages['vehicle-colors']);
        $this->assertStringContainsString("route('intelligence.vehicle-plates.input', \$run)", $pages['vehicle-plates']);
        $this->assertStringContainsString("route('intelligence.vehicle-plates.crop', \$run)", $pages['vehicle-plates']);
        $this->assertStringContainsString('<x-photo-gallery', $pages['vehicle-plates']);
        $this->assertStringContainsString("route('intelligence.vehicle-damages.input', \$run)", $pages['vehicle-damages']);
        $this->assertStringContainsString('data-damage-overlay-frame', $pages['vehicle-damages']);
        $this->assertStringContainsString('<x-slot:overlay>', $pages['vehicle-damages']);
    }
}
