<?php

use App\Http\Middleware\EnsureRole;
use App\Http\Middleware\EnsureUserIsActive;
use App\Http\Middleware\SecureCookiesOverHttps;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // cloudflared (Quick Tunnel) runs on this same PC, so the only proxy
        // hop is loopback. Trusting only loopback keeps forwarded headers
        // from other networks untrusted while HTTPS detection still works.
        $middleware->trustProxies(at: ['127.0.0.1', '::1']);

        $middleware->web(prepend: [
            SecureCookiesOverHttps::class,
        ]);

        $middleware->alias([
            'active' => EnsureUserIsActive::class,
            'role' => EnsureRole::class,
        ]);

        $middleware->redirectGuestsTo(fn () => route('login'));
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
