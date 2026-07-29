<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SecureCookiesOverHttps
{
    /**
     * The Quick Tunnel serves the app over HTTPS while local access stays
     * plain HTTP, so the Secure cookie flag has to follow the request
     * scheme instead of a fixed config value. Runs after TrustProxies,
     * so isSecure() already honours X-Forwarded-Proto from cloudflared.
     */
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->isSecure()) {
            config(['session.secure' => true]);
        }

        return $next($request);
    }
}
