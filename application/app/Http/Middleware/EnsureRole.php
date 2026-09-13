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

        // Some of these are not roles but permissions a PERSON can hold, so
        // they are asked of the user rather than matched against their role:
        // the artist lead, the order desk which one leader also runs, and the
        // design side - officers, agents and artists together, who are four
        // different roles and one audience.
        $ok = $user && (
            in_array($user->role, $roles, true)
            || (in_array('artist_lead', $roles, true) && $user->isArtistLead())
            || (in_array('order_desk', $roles, true) && $user->canCreateOrders())
            || (in_array('design_side', $roles, true) && $user->canSeeDesignBoard())
        );

        abort_unless($ok, 403);

        return $next($request);
    }
}
