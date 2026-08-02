<?php

namespace App\Services;

/**
 * Works out the address a CLIENT can reach, which is not always the address
 * staff are browsing.
 *
 * Staff may open the app three ways — 127.0.0.1 on the server, the office LAN
 * IP, or the Cloudflare tunnel. Only the tunnel is reachable from outside, so a
 * questionnaire link built from the current request would be useless to a client
 * whenever the officer happens to be on the LAN. This resolves the public base
 * URL instead:
 *
 *   1. config('app.public_url')  — set PUBLIC_URL in .env for a permanent domain
 *   2. current-tunnel-url.txt    — written by start-imprint.bat when the tunnel starts
 *   3. null                      — nothing public available; caller keeps the request host
 */
class PublicUrl
{
    /** Hosts that only exist inside the building — never reachable by a client. */
    private const PRIVATE_PATTERNS = [
        '/^localhost$/i',
        '/^127\./',
        '/^10\./',
        '/^192\.168\./',
        // 172.16.0.0 – 172.31.255.255
        '/^172\.(1[6-9]|2[0-9]|3[01])\./',
        '/^169\.254\./',
        '/\.local$/i',
    ];

    /** The public base URL (no trailing slash), or null if there isn't one. */
    public static function base(): ?string
    {
        $configured = trim((string) config('app.public_url', ''));
        if ($configured !== '') {
            return rtrim($configured, '/');
        }

        $file = base_path('../current-tunnel-url.txt');
        if (! is_file($file)) {
            return null;
        }

        $url = trim((string) @file_get_contents($file));

        // Only accept something that actually looks like a URL — the file is
        // rewritten by a batch script and could be empty or hold an error line.
        if (! preg_match('#^https?://[A-Za-z0-9.\-]+(:\d+)?/?$#', $url)) {
            return null;
        }

        return rtrim($url, '/');
    }

    /**
     * Re-point a generated route URL at the public base, keeping its path and
     * query. Returns the URL unchanged when no public base is known.
     */
    public static function rewrite(string $url): string
    {
        $base = self::base();
        if ($base === null) {
            return $url;
        }

        $path = (string) (parse_url($url, PHP_URL_PATH) ?: '');
        $query = parse_url($url, PHP_URL_QUERY);

        return $base.$path.($query ? '?'.$query : '');
    }

    /** True when the URL's host is private — i.e. a client could not open it. */
    public static function isPrivate(string $url): bool
    {
        $host = (string) (parse_url($url, PHP_URL_HOST) ?: '');
        if ($host === '') {
            return true;
        }

        foreach (self::PRIVATE_PATTERNS as $pattern) {
            if (preg_match($pattern, $host)) {
                return true;
            }
        }

        return false;
    }
}
