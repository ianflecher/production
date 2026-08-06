<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class DataVersion
{
    /**
     * The tables whose contents the screens are built from. A change to any of
     * them means an open page is showing something stale.
     */
    private const WATCHED = [
        'production_orders',
        // The client list feeds the order form's picker, so an edit there
        // should reach an open page too. It costs nothing extra: the whole
        // fingerprint is still one round trip.
        'clients',
        'tasks',
        'job_orders',
        'job_order_files',
        'task_files',
        'payments',
        'attendances',
        'users',
        'inventory_items',
        'material_requests',
        'product_items',
        'product_movements',
        'product_receipts',
    ];

    /**
     * A fingerprint of the data the UI shows. When it changes, open pages
     * notice on their next check and reload themselves.
     *
     * This is asked for by every open tab on a timer, so it is deliberately
     * ONE round trip: the counts and last-touched times for all the watched
     * tables are gathered in a single union rather than a query each. Asking
     * table by table cost eighteen queries every few seconds per person, which
     * on its own was more work than the server could keep up with — and far
     * worse against a database that isn't on the same machine.
     *
     * Note: updated_at has one-second resolution, so two edits to the same row
     * inside one second look identical. Screens check on a 15-second timer, so
     * a change always lands in a later second than the one before it.
     */
    public static function current(): string
    {
        $selects = array_map(
            // Table names come from the constant above, never from input.
            fn (string $table) => "select '{$table}' as t, count(*) as c, max(updated_at) as m from `{$table}`",
            self::WATCHED
        );

        $rows = DB::select(implode(' union all ', $selects));

        $parts = [];
        foreach ($rows as $row) {
            $parts[$row->t] = $row->c.'@'.$row->m;
        }

        // Keyed and sorted, so the fingerprint doesn't depend on row order.
        ksort($parts);

        return md5(json_encode($parts));
    }
}
