<?php

namespace App\Support;

use App\Models\User;

/**
 * The accounts offered on the login page of a demo deployment.
 *
 * Showing somebody the system means showing them each role: the account
 * officer's view is not the floor's, and the floor's is not the mover's. Handing
 * out seven logins one at a time makes that tedious, so a demo lists them.
 *
 * Never on by default — see config('app.demo_logins'). The office runs this same
 * code against the real shop.
 */
class DemoLogins
{
    /** One account per role worth showing, in the order a job travels. */
    private const ROLES = [
        ['super_admin', 'Super admin', 'Everything'],
        ['sales', 'Account officer', 'Takes orders, collects payment'],
        ['leader', 'Leader', 'Approves designs, runs the floor'],
        ['artist', 'Artist', 'Layout, mockup, print files'],
        ['Printer', 'Printer', 'Runs a machine from the station board'],
        ['Pairing', 'Pairing', 'Runs a machine from the station board'],
        ['Mover', 'Mover', 'Chases jobs on the floor'],
        ['Finance', 'Finance', 'The books'],
        ['Inventory', 'Inventory', 'Counts finished stock in'],
    ];

    /** True only where a deployment has said its data is invented. */
    public static function enabled(): bool
    {
        return (bool) config('app.demo_logins', false);
    }

    /**
     * The accounts to offer, skipping any role this database has nobody for.
     *
     * @return array<int, array{email: string, name: string, role: string, note: string}>
     */
    public static function all(): array
    {
        if (! self::enabled()) {
            return [];
        }

        $staff = User::where('is_active', true)->orderBy('id')->get();
        $offered = [];

        foreach (self::ROLES as [$jobRole, $label, $note]) {
            $person = $staff->first(
                fn (User $u) => strtolower(trim((string) $u->job_role)) === strtolower($jobRole)
            );

            if ($person) {
                $offered[] = [
                    'email' => $person->email,
                    'name' => $person->name,
                    'role' => $label,
                    'note' => $note,
                ];
            }
        }

        return $offered;
    }

    /** The password these demo accounts share. */
    public static function password(): string
    {
        return User::DEFAULT_PASSWORD;
    }
}
