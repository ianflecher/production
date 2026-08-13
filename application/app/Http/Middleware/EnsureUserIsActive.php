<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserIsActive
{
    /**
     * Log out any account that has been deactivated since it signed in.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && ! $user->is_active) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login')->withErrors([
                'email' => 'This account has been deactivated. Please contact your leader.',
            ]);
        }

        // Keep the office address of this person's PC up to date.
        //
        // It was only written at sign-in, and only when the sign-in itself
        // came from an office address. Somebody who stays signed in for weeks,
        // or who signs in over the tunnel, kept whatever address they had
        // months ago - which is what the artist's export box then offered
        // them. Any request from an office address is proof of where they are
        // now.
        if ($user
            && \App\Services\ServerIp::isPrivate((string) $request->ip())
            && $user->last_login_ip !== $request->ip()) {
            // The page being rendered right now must see it too: move to a
            // different PC and the export path should offer THAT machine
            // immediately, not on the next click.
            $user->last_login_ip = $request->ip();

            // Written straight to the row: no model events and no updated_at,
            // so it cannot make every page look changed to the auto-reload.
            \App\Models\User::where('id', $user->id)
                ->update(['last_login_ip' => $request->ip()]);
        }

        return $next($request);
    }
}
