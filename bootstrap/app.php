<?php

use App\Http\Middleware\EnsurePlatformAdmin;
use App\Http\Middleware\RequestCorrelation;
use App\Http\Middleware\ResolveTenantContext;
use App\Http\Middleware\SecurityHeaders;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Routing\Middleware\SubstituteBindings;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->append([
            RequestCorrelation::class,
            SecurityHeaders::class,
        ]);

        $middleware->alias([
            'tenant' => ResolveTenantContext::class,
            'platform' => EnsurePlatformAdmin::class,
        ]);

        $middleware->prependToPriorityList(SubstituteBindings::class, ResolveTenantContext::class);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();
