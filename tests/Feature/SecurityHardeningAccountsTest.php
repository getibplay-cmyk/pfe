<?php

namespace Tests\Feature;

use App\Actions\Auth\ResetPasswordWithToken;
use App\Http\Middleware\TrustedProxies;
use App\Notifications\Auth\EmailChangeNotification;
use App\Support\Auth\AuthenticationLimits;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class SecurityHardeningAccountsTest extends TestCase
{
    use RefreshDatabase;

    public function test_email_change_keeps_the_trusted_identity_until_explicit_confirmation_and_cannot_be_replayed(): void
    {
        Notification::fake();
        $user = $this->createTenantOwner();
        $this->assertSame($user->fresh()->security_version, $user->security_version);
        $original = $user->email;
        $url = null;
        $this->actingAs($user)->patch(route('profile.update'), ['name' => 'Test', 'email' => 'pending@example.test', 'current_password' => 'password'])->assertSessionHasNoErrors();
        Notification::assertSentOnDemand(EmailChangeNotification::class, function ($notification, $channels, $notifiable) use (&$url) {
            if ($notifiable->routes['mail'] === 'pending@example.test') {
                $url = $notification->toMail($notifiable)->actionUrl;

                return true;
            }

            return false;
        });
        $this->assertSame($original, $user->fresh()->email);
        $this->assertNotNull($user->fresh()->email_verified_at);
        $this->assertStringNotContainsString('pending@example.test', DB::table('users')->where('id', $user->id)->value('pending_email'));
        $this->actingAs($user->fresh())->get($url)->assertOk()->assertHeader('Referrer-Policy', 'no-referrer');
        $this->assertSame($original, $user->fresh()->email);
        $this->post($url)->assertRedirect(route('profile.edit'));
        $this->assertSame('pending@example.test', $user->fresh()->email);
        $this->assertNotNull($user->fresh()->email_verified_at);
        $this->assertNull($user->fresh()->pending_email);
        $this->post($url)->assertNotFound();
    }

    public function test_password_reset_is_one_use_revokes_version_and_refuses_suspended_accounts(): void
    {
        $user = $this->createTenantOwner();
        $token = Password::createToken($user);
        $credentials = ['email' => $user->email, 'token' => $token, 'password' => 'une longue phrase personnelle'];
        $reset = app(ResetPasswordWithToken::class);
        $this->assertSame(Password::PASSWORD_RESET, $reset->handle($credentials));
        $this->assertSame(1, $user->fresh()->security_version);
        $this->assertSame(Password::INVALID_TOKEN, $reset->handle($credentials));
        $token = Password::createToken($user->fresh());
        $user->forceFill(['is_active' => false])->save();
        $this->assertSame(Password::INVALID_TOKEN, $reset->handle([...$credentials, 'token' => $token]));
        $this->assertSame(1, $user->fresh()->security_version);
    }

    public function test_session_absolute_expiry_and_security_version_invalidate_access_without_mfa(): void
    {
        $user = $this->createTenantOwner();
        $this->actingAs($user)->withSession(['account_session' => ['user_id' => $user->id, 'version' => 0, 'started_at' => now()->subHours(8)->timestamp]])
            ->getJson(route('profile.edit'))->assertUnauthorized();
        $this->assertGuest();
        $this->actingAs($user)->withSession(['account_session' => ['user_id' => $user->id, 'version' => -1, 'started_at' => now()->timestamp]])
            ->get(route('profile.edit'))->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_password_phrases_are_allowed_short_and_bcrypt_truncated_values_are_rejected(): void
    {
        config(['security.password_breach_check' => false]);
        $valid = fn ($value) => Validator::make(['password' => $value], ['password' => ['required', PasswordRule::defaults()]])->passes();
        $this->assertTrue($valid('une phrase facile à retenir'));
        $this->assertFalse($valid('TropCourt12!'));
        $this->assertFalse($valid('password123456789'));
        $this->assertFalse($valid("une phrase contenant\0un octet nul"));
        if (Hash::getDefaultDriver() === 'bcrypt') {
            $this->assertFalse($valid(str_repeat('é', 37)));
        }
    }

    public function test_breach_screening_sends_only_a_prefix_and_refuses_service_failures(): void
    {
        config(['security.password_breach_check' => true]);
        $password = 'une phrase fictive compromise';
        $digest = strtoupper(sha1($password));
        Http::fake(['api.pwnedpasswords.com/*' => Http::response(substr($digest, 5).':1', 200)]);
        $validator = Validator::make(['password' => $password], ['password' => [PasswordRule::defaults()]]);
        $this->assertTrue($validator->fails());
        Http::assertSent(fn ($request) => $request->url() === 'https://api.pwnedpasswords.com/range/'.substr($digest, 0, 5) && $request->body() === '');
        Http::fake(['*' => Http::response('', 503)]);
        $this->assertTrue(Validator::make(['password' => $password], ['password' => [PasswordRule::defaults()]])->fails());
    }

    public function test_distributed_login_attempts_are_bounded_by_account_and_have_no_email_in_the_cache_key(): void
    {
        for ($i = 1; $i <= 10; $i++) {
            $request = Request::create('/login', 'POST', ['email' => 'target@example.test'], server: ['REMOTE_ADDR' => '192.0.2.'.$i]);
            AuthenticationLimits::reserveLogin($request);
            foreach (AuthenticationLimits::loginKeys($request) as $key => $limit) {
                $this->assertStringNotContainsString('target@example.test', $key);
            }
        }
        $this->expectException(ValidationException::class);
        AuthenticationLimits::reserveLogin(Request::create('/login', 'POST', ['email' => 'target@example.test'], server: ['REMOTE_ADDR' => '192.0.2.20']));
    }

    public function test_forwarded_ip_and_host_are_not_trusted_from_a_direct_client(): void
    {
        config(['security.trusted_proxies' => ['192.0.2.1']]);
        $request = Request::create('https://localhost/profile', server: ['REMOTE_ADDR' => '192.0.2.99', 'HTTP_X_FORWARDED_FOR' => '198.51.100.1', 'HTTP_X_FORWARDED_HOST' => 'hostile.example']);
        (new TrustedProxies)->handle($request, function ($request) {
            $this->assertSame('192.0.2.99', $request->ip());
            $this->assertSame('localhost', $request->getHost());

            return response('ok');
        });
        Request::setTrustedProxies([], 0);
    }

    public function test_an_invalid_recovery_token_cannot_trigger_an_external_password_check(): void
    {
        config(['security.password_breach_check' => true]);
        Http::preventStrayRequests();
        $this->post(route('password.store'), ['email' => 'absent@example.test', 'token' => str_repeat('a', 64),
            'password' => 'a long synthetic recovery phrase', 'password_confirmation' => 'a long synthetic recovery phrase'])
            ->assertSessionHasErrors('email');
        Http::assertNothingSent();
    }
}
