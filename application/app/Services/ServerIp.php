<?php

namespace App\Services;

/**
 * This PC's address on the office network.
 *
 * The router hands the server its IP by DHCP, so it can change on a reboot.
 * Artists record where a design lives on the shared drive — e.g.
 * \\192.168.150.201\Designs\IC2026-00002.ai — and once the IP moves, every
 * path recorded before it stops opening.
 *
 * So a path that points at THIS machine is stored with a {SERVER} marker in
 * place of the address, and the marker is expanded to whatever the IP is right
 * now whenever the path is shown. A path pointing at some other machine keeps
 * its own address and is never rewritten.
 */
class ServerIp
{
    /** Placeholder standing in for this machine inside a stored path. */
    public const TOKEN = '{SERVER}';

    private static ?string $cached = null;

    /** This machine's current LAN IP, or null if it can't be worked out. */
    public static function current(): ?string
    {
        if (self::$cached !== null) {
            return self::$cached ?: null;
        }

        // What the web server answered on is the most reliable when serving a
        // request; fall back to resolving our own hostname (also works on CLI).
        $candidates = [
            request()?->server('SERVER_ADDR'),
            gethostbyname(gethostname()),
        ];

        foreach ($candidates as $ip) {
            if (is_string($ip) && self::isPrivate($ip)) {
                return self::$cached = $ip;
            }
        }

        self::$cached = '';

        return null;
    }

    /**
     * This machine's name on the network.
     *
     * The better half of a path: \IC-PRINT-01\FOR PRINT keeps working when
     * the router hands the machine a different address, which an IP path does
     * not. The address stays on the sheet as the alternative, for a PC that
     * cannot resolve the name.
     */
    public static function deviceName(): ?string
    {
        $host = gethostname();

        if (! is_string($host) || trim($host) === '') {
            return null;
        }

        // Just the machine, not machine.office.local — a UNC path takes the
        // short name and the domain suffix only gets in the way.
        return strtoupper(explode('.', trim($host))[0]);
    }

    /** True for an office-network address (not loopback, not the internet). */
    public static function isPrivate(string $ip): bool
    {
        if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return false;
        }

        // Loopback and "no DHCP answered" addresses are not how another PC
        // reaches us.
        if (str_starts_with($ip, '127.') || str_starts_with($ip, '169.254.')) {
            return false;
        }

        // PHP only offers the inverse filter: an address is on a private range
        // exactly when it fails the public-only check.
        return filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE
        ) === false;
    }

    /**
     * The address a person's PC is on, from their last sign-in. Falls back to
     * this server's address when we've never seen them log in.
     */
    public static function ipForUser(?\App\Models\User $user): ?string
    {
        $ip = $user?->last_login_ip;

        return ($ip && self::isPrivate($ip)) ? $ip : self::current();
    }

    /**
     * Store a path: swap the machine's address for the marker, so the path
     * keeps working after that PC's address changes — or after the person
     * moves to a different PC. Anything else is left alone.
     */
    public static function pack(?string $path, ?string $ip = null): ?string
    {
        $path = $path === null ? null : trim($path);
        $ip ??= self::current();

        if (blank($path) || $ip === null) {
            return $path;
        }

        return str_replace($ip, self::TOKEN, $path);
    }

    /**
     * Show a path: put back the address that machine is on right now. If we
     * can't determine one, the marker is left visible rather than handing out a
     * silently broken path.
     */
    public static function unpack(?string $path, ?string $ip = null): ?string
    {
        $ip ??= self::current();

        if (blank($path) || $ip === null) {
            return $path;
        }

        return str_replace(self::TOKEN, $ip, $path);
    }
}
