<?php

use App\Http\Middleware\EnsureRole;
use App\Http\Middleware\EnsureUserIsActive;
use App\Http\Middleware\NoStoreHtmlPages;
use App\Http\Middleware\SecureCookiesOverHttps;
use App\Support\TrustedProxies;
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
        // Loopback only unless a deployment says otherwise — see TrustedProxies.
        $middleware->trustProxies(at: TrustedProxies::from(env('TRUSTED_PROXIES')));

        $middleware->web(prepend: [
            SecureCookiesOverHttps::class,
            NoStoreHtmlPages::class,
        ]);

        // An account whose password somebody else chose cannot go anywhere
        // else until it is changed. Appended to the whole web group rather
        // than to one route group, so no corner of the app is a way round it.
        // It does nothing at all unless must_change_password is set, and that
        // defaults to false — every account that exists today is unaffected.
        $middleware->web(append: [
            \App\Http\Middleware\MustChangePassword::class,
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
