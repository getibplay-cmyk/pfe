<?php

namespace Tests\Unit;

use App\Http\Middleware\RequireRecentPassword;
use Illuminate\Contracts\Routing\ResponseFactory;
use Illuminate\Contracts\Routing\UrlGenerator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Response;

class RequireRecentPasswordTest extends TestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public static function staleProofs(): array
    {
        return [
            'missing' => [null], 'string' => ['1800000000'], 'array' => [[]],
            'future' => [1800000001], 'exact expiry' => [1799999100],
            'older' => [1799999000], 'zero' => [0],
        ];
    }

    #[DataProvider('staleProofs')]
    public function test_untrusted_or_expired_confirmation_never_executes_a_sensitive_action(mixed $proof): void
    {
        $request = $this->request($proof);
        $factory = $this->createMock(ResponseFactory::class);
        $factory->expects($this->once())->method('json')->with($this->isType('array'), 423)
            ->willReturn(new JsonResponse(['message' => 'Password confirmation required.'], 423));
        $middleware = new RequireRecentPassword($factory, $this->createMock(UrlGenerator::class), 900);
        $response = $middleware->handle($request, function () {
            $this->fail('A stale proof reached the protected action.');
        });
        $this->assertSame(423, $response->getStatusCode());
    }

    public function test_a_recent_confirmation_preserves_the_request_and_response(): void
    {
        $request = $this->request(1799999999);
        $factory = $this->createMock(ResponseFactory::class);
        $factory->expects($this->never())->method('json');
        $middleware = new RequireRecentPassword($factory, $this->createMock(UrlGenerator::class), 900);
        $expected = new Response('authorized');
        $actual = $middleware->handle($request, function ($received) use ($request, $expected) {
            $this->assertSame($request, $received);

            return $expected;
        });
        $this->assertSame($expected, $actual);
    }

    public function test_a_route_cannot_extend_elevation_beyond_fifteen_minutes(): void
    {
        $request = $this->request(1799999099);
        $factory = $this->createMock(ResponseFactory::class);
        $factory->expects($this->once())->method('json')->willReturn(new JsonResponse([], 423));
        $middleware = new RequireRecentPassword($factory, $this->createMock(UrlGenerator::class), 10800);
        $this->assertSame(423, $middleware->handle($request, fn () => new Response, null, 10800)->getStatusCode());
    }

    public function test_a_shorter_configured_confirmation_window_is_respected(): void
    {
        $request = $this->request(1799999700);
        $factory = $this->createMock(ResponseFactory::class);
        $factory->expects($this->once())->method('json')->willReturn(new JsonResponse([], 423));
        $middleware = new RequireRecentPassword($factory, $this->createMock(UrlGenerator::class), 300);
        $this->assertSame(423, $middleware->handle($request, fn () => new Response)->getStatusCode());
    }

    private function request(mixed $proof): Request
    {
        Carbon::setTestNow(Carbon::createFromTimestampUTC(1800000000));
        $request = Request::create('/platform/tenants/1/suspend', 'POST', server: ['HTTP_ACCEPT' => 'application/json']);
        $session = new Store('recent-auth-test', new ArraySessionHandler(120));
        $session->start();
        if ($proof !== null) {
            $session->put('auth.password_confirmed_at', $proof);
        }
        $request->setLaravelSession($session);

        return $request;
    }
}
