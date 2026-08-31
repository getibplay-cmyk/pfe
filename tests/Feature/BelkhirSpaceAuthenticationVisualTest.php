<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class BelkhirSpaceAuthenticationVisualTest extends TestCase
{
    public function test_login_has_the_belkhir_space_brand_panel_and_accessible_loading_form(): void
    {
        $this->get(route('login'))
            ->assertOk()
            ->assertSee('Pilotez votre activité de location en toute clarté.')
            ->assertSee('Réservations et contrats')
            ->assertSee('Parc automobile')
            ->assertSee('Analyses et prévisions')
            ->assertSee('data-belkhir-space-route-motif', false)
            ->assertSee('data-loading-form', false)
            ->assertSee('data-loading-submit', false)
            ->assertSee('data-loading-spinner', false)
            ->assertSee('Connexion en cours…')
            ->assertSee('autocomplete="username"', false)
            ->assertSee('autocomplete="current-password"', false)
            ->assertDontSee('register')
            ->assertDontSee('signup');
    }

    public function test_password_field_is_secure_before_javascript_and_has_an_accessible_toggle(): void
    {
        $html = Blade::render(<<<'BLADE'
            <x-password-field id="secret" name="secret" label="Mot de passe" autocomplete="current-password" />
        BLADE);

        $this->assertStringContainsString('type="password"', $html);
        $this->assertStringContainsString('aria-label="Afficher le mot de passe"', $html);
        $this->assertStringContainsString('x-bind:aria-label=', $html);
        $this->assertStringContainsString('aria-pressed="false"', $html);
        $this->assertStringContainsString('x-bind:aria-pressed=', $html);
        $this->assertStringContainsString('type="button"', $html);
        $this->assertStringContainsString('min-w-11', $html);
    }

    public function test_password_recovery_keeps_the_official_contract_and_shared_loading_state(): void
    {
        $this->get(route('password.request'))
            ->assertOk()
            ->assertSee('Mot de passe oublié')
            ->assertSee('action="'.route('password.email').'"', false)
            ->assertSee('name="email"', false)
            ->assertSee('data-loading-form', false)
            ->assertSee('Envoi en cours…');

        $this->get(route('password.reset', ['token' => 'jeton-visuel']))
            ->assertOk()
            ->assertSee('Réinitialiser le mot de passe')
            ->assertSee('name="token"', false)
            ->assertSee('name="password"', false)
            ->assertSee('name="password_confirmation"', false)
            ->assertSee('data-loading-form', false)
            ->assertSee('Enregistrement en cours…');
    }

    public function test_all_existing_auth_views_share_the_same_visual_identity_without_route_changes(): void
    {
        foreach ([
            'login',
            'forgot-password',
            'reset-password',
            'confirm-password',
            'verify-email',
            'change-required-password',
        ] as $view) {
            $source = File::get(resource_path('views/auth/'.$view.'.blade.php'));

            $this->assertStringContainsString('<x-guest-layout>', $source, $view);
            $this->assertStringContainsString('<x-auth-heading', $source, $view);
            $this->assertStringContainsString('data-loading-form', $source, $view);
            $this->assertStringContainsString('<x-submit-button', $source, $view);
        }

        foreach ([
            'login',
            'password.request',
            'password.email',
            'password.reset',
            'password.store',
            'password.confirm',
            'verification.notice',
            'verification.send',
            'password.change-required',
            'password.change-required.update',
            'logout',
        ] as $routeName) {
            $this->assertTrue(Route::has($routeName), $routeName);
        }
    }

    public function test_authentication_emails_use_professional_french_copy(): void
    {
        app()->setLocale('fr');

        $this->assertSame('Réinitialisation de votre mot de passe', __('Reset Password Notification'));
        $this->assertSame('Réinitialiser le mot de passe', __('Reset Password'));
        $this->assertSame('Vérifier votre adresse e-mail', __('Verify Email Address'));
        $this->assertSame('Bonjour,', __('Hello!'));
        $this->assertSame('Cordialement,', __('Regards,'));
        $this->assertSame('Tous droits réservés.', __('All rights reserved.'));
        $this->assertStringContainsString(
            'expirera dans 60 minutes',
            __('This password reset link will expire in :count minutes.', ['count' => 60]),
        );
    }
}
