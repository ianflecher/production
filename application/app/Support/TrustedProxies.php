<?php

namespace App\Support;

/**
 * Which proxies the app believes about "this request arrived over HTTPS".
 *
 * The office runs cloudflared on the same PC, so the only hop is loopback and
 * that is all we trust — forwarded headers arriving from anywhere else are
 * somebody else's claim, not ours.
 *
 * A hosted deployment is different: the platform terminates TLS and forwards
 * from an address that changes and isn't published. Left untrusted, Laravel
 * reads the request as plain HTTP and writes http:// links into an https://
 * page, so the browser blocks the stylesheet as mixed content and the app
 * renders unstyled. Such a deployment sets TRUSTED_PROXIES=*, which is only
 * safe because the platform is the sole way in.
 */
class TrustedProxies
{
    /** @return string|array<int, string> */
    public static function from(?string $setting): string|array
    {
        $setting = trim((string) $setting);

        if ($setting === '') {
            return ['127.0.0.1', '::1'];
        }

        if ($setting === '*') {
            return '*';
        }

        return array_values(array_filter(
            array_map('trim', explode(',', $setting)),
            fn ($proxy) => $proxy !== ''
        ));
    }
}
