<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

class BelkhirSpaceBrandIdentityTest extends TestCase
{
    public function test_commercial_identity_is_centralized(): void
    {
        $this->assertSame('BELKHIR SPACE', config('brand.name'));
        $this->assertSame('Gestion intelligente de location de véhicules', config('brand.description'));
    }

    public function test_brand_logo_exposes_accessible_light_and_dark_variants(): void
    {
        foreach (['light', 'dark'] as $surface) {
            $html = Blade::render('<x-brand-logo :surface="$surface" />', compact('surface'));

            $this->assertStringContainsString('data-brand="belkhir-space"', $html);
            $this->assertStringContainsString('data-brand-mark="belkhir-space-monogram"', $html);
            $this->assertStringContainsString('role="img"', $html);
            $this->assertStringContainsString('aria-label="Monogramme BELKHIR SPACE"', $html);
            $this->assertStringContainsString('BELKHIR SPACE', $html);
            $this->assertStringContainsString('Gestion intelligente de location de véhicules', $html);
            $this->assertStringNotContainsString('RentFleet', $html);
        }
    }

    public function test_compact_brand_logo_keeps_the_full_identity_for_screen_readers(): void
    {
        $html = Blade::render('<x-brand-logo compact />');

        $this->assertStringContainsString('class="sr-only"', $html);
        $this->assertStringContainsString('BELKHIR SPACE — Gestion intelligente de location de véhicules', $html);
    }

    public function test_login_uses_the_commercial_name_without_enabling_public_registration(): void
    {
        $this->get(route('login'))
            ->assertOk()
            ->assertSee('Connexion à BELKHIR SPACE')
            ->assertDontSee('Connexion à RentFleet')
            ->assertDontSee('Créer un compte');
    }

    public function test_authentication_email_uses_the_centralized_commercial_name(): void
    {
        $user = new User([
            'name' => 'Responsable de démonstration',
            'email' => 'responsable@example.test',
        ]);

        $html = (new ResetPassword('opaque-test-token'))->toMail($user)->render()->toHtml();

        $this->assertSame('BELKHIR SPACE', config('app.name'));
        $this->assertStringContainsString('BELKHIR SPACE', $html);
        $this->assertStringNotContainsString('RentFleet', $html);
    }

    public function test_primary_brand_surfaces_do_not_hardcode_the_legacy_commercial_name(): void
    {
        $surfaces = [
            resource_path('views/layouts/app.blade.php'),
            resource_path('views/layouts/guest.blade.php'),
            resource_path('views/auth/login.blade.php'),
            resource_path('views/platform/dashboard.blade.php'),
            resource_path('views/platform/statistics.blade.php'),
            resource_path('views/platform/plans/index.blade.php'),
            resource_path('views/tenant/account-saas.blade.php'),
            resource_path('views/intelligence/demand-forecasts/index.blade.php'),
        ];

        foreach ($surfaces as $surface) {
            $this->assertStringNotContainsString('RentFleet', file_get_contents($surface));
            $this->assertStringContainsString("config('brand.name')", file_get_contents($surface));
        }
    }

    public function test_major_brand_actions_use_decorative_icons(): void
    {
        $mailButton = Blade::render('<x-submit-button label="Envoyer" icon="mail" />');

        $this->assertStringContainsString('data-icon="mail"', $mailButton);
        $this->assertStringContainsString('aria-hidden="true"', $mailButton);

        $intelligence = file_get_contents(resource_path('views/intelligence/index.blade.php'));
        $applicationLayout = file_get_contents(resource_path('views/layouts/app.blade.php'));
        $plans = file_get_contents(resource_path('views/platform/plans/index.blade.php'));

        $this->assertGreaterThanOrEqual(8, substr_count($intelligence, '<x-icon name="launch" size="xs" />'));
        $this->assertStringContainsString('<x-icon name="logout" size="xs" />', $applicationLayout);
        $this->assertStringContainsString('<x-icon name="view" size="xs" />', $applicationLayout);
        $this->assertStringContainsString('<x-icon name="close" size="xs" />Retirer', $plans);
        $this->assertStringContainsString('<x-icon name="add" size="xs" />Ajouter', $plans);
    }

    public function test_guest_brand_accent_uses_solid_segments_instead_of_a_multicolor_gradient(): void
    {
        $guestLayout = file_get_contents(resource_path('views/layouts/guest.blade.php'));
        $styles = file_get_contents(resource_path('css/app.css'));

        $this->assertStringNotContainsString('bg-gradient-', $guestLayout);
        $this->assertStringNotContainsString('bg-gradient-', $styles);
        $this->assertStringContainsString('flex-1 bg-belkhir-space-blue', $guestLayout);
        $this->assertStringContainsString('w-20 bg-belkhir-space-orange', $guestLayout);
        $this->assertStringContainsString('w-1/4 bg-belkhir-space-orange', $styles);
    }

    public function test_technical_rentfleet_identifiers_are_not_rebranded(): void
    {
        $this->assertStringContainsString(
            "'consent_text_version' => 'rentfleet-consent-v1'",
            file_get_contents(config_path('rentals.php')),
        );
        $this->assertStringContainsString(
            "protected \$signature = 'rentfleet:doctor",
            file_get_contents(app_path('Console/Commands/RentFleetDoctor.php')),
        );
        $this->assertStringContainsString(
            "TEMPLATE_ID = 'rentfleet-rental-contract-fr-ar'",
            file_get_contents(app_path('Support/Contracts/BilingualRentalContractDocument.php')),
        );
    }
}
