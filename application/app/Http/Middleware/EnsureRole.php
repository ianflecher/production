<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureRole
{
    /**
     * Usage: ->middleware('role:leader,super_admin')
     *
     * "artist_lead" is not a permission role — the artist leader is an agent
     * like the artists he leads. It is accepted here as a named exception so
     * the two pages that ARE his (checking tech packs, the artist accounts)
     * can let him in without handing him the whole leader group.
     */
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();

        $ok = $user && (
            in_array($user->role, $roles, true)
            || (in_array('artist_lead', $roles, true) && $user->isArtistLead())
        );

        abort_unless($ok, 403);

        return $next($request);
    }
}
