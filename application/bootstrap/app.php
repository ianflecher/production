<?php

use App\Http\Middleware\EnsureRole;
use App\Http\Middleware\EnsureUserIsActive;
use App\Http\Middleware\SecureCookiesOverHttps;
use App\Support\TrustedProxies;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

$app = Application::configure(basePath: dirname(__DIR__))
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

// A serverless host gives you a read-only filesystem with one writable corner,
// so storage moves there. Set by api/index.php, which creates the directories
// before this runs. Nothing else — the office PC included — sets it, and those
// keep writing to storage/ in the project as they always have.
if ($storage = env('APP_STORAGE_PATH')) {
    $app->useStoragePath($storage);
}

return $app;
