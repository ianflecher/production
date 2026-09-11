<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * An account whose password somebody else chose goes one place: the page where
 * they choose their own.
 *
 * Only accounts flagged must_change_password are affected, and that flag
 * defaults to false — so every account that existed before HR did carries on
 * exactly as it did.
 *
 * The exceptions matter more than the rule. Left out, the redirect would send
 * the change-password page to itself and lock the person in a loop with no way
 * out, not even logging out.
 */
class MustChangePassword
{
    /** Reachable while the flag is set. */
    private const ALLOWED = [
        'password.change',
        'password.change.save',
        'logout',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user || ! $user->must_change_password) {
            return $next($request);
        }

        if ($request->routeIs(self::ALLOWED)) {
            return $next($request);
        }

        // A background poll must not be answered with a redirect to an HTML
        // page - the tab would try to render it and the screen would go odd.
        if ($request->expectsJson()) {
            return response()->json(['message' => 'Change your password first.'], 409);
        }

        return redirect()->route('password.change');
    }
}
