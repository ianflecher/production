<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class NoStoreHtmlPages
{
    /**
     * A page is never served from yesterday's copy.
     *
     * On the LAN the app is plain HTTP, and without a Cache-Control header a
     * browser is free to decide for itself how long a page stays good. It
     * guesses generously — so pressing Back, or reopening a restored tab, can
     * hand somebody a form built hours ago carrying a token the session has
     * long since forgotten. They fill it in and are told the page expired.
     *
     * Every page here is a live view of the shop anyway; there is nothing worth
     * caching, and a stale one is worse than a slow one.
     *
     * HTML only. Images, CSS and scripts still cache normally.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $type = (string) $response->headers->get('Content-Type');

        if ($type === '' || str_contains($type, 'text/html')) {
            $response->headers->set('Cache-Control', 'no-store, no-cache, must-revalidate');
            $response->headers->set('Pragma', 'no-cache');
        }

        return $response;
    }
}
