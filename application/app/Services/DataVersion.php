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
        // The inbox is a screen like any other, and the station board moves
        // whenever somebody picks a job up or puts it down.
        'messages',
        'station_sessions',
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
     * It is a count, the highest id and the SUM of every row's updated_at —
     * not the maximum.
     *
     * The maximum was the bug: demo and back-dated rows carry timestamps in
     * the future, so a genuine edit today was older than the maximum and the
     * fingerprint never moved. Screens sat there showing stale data and the
     * auto-reload looked broken. A sum moves whenever ANY row's updated_at
     * changes, whatever order the dates are in, and the max(id) catches an
     * insert that lands in the same second.
     */
    public static function current(): string
    {
        // Seconds since the epoch, per driver. Tests run on SQLite; the shop
        // runs on MySQL.
        $seconds = DB::getDriverName() === 'sqlite'
            ? "strftime('%s', updated_at)"
            : 'UNIX_TIMESTAMP(updated_at)';

        $selects = array_map(
            // Table names come from the constant above, never from input.
            fn (string $table) => "select '{$table}' as t, count(*) as c, coalesce(max(id), 0) as i, "
                ."coalesce(sum({$seconds}), 0) as s from `{$table}`",
            self::WATCHED
        );

        $rows = DB::select(implode(' union all ', $selects));

        $parts = [];
        foreach ($rows as $row) {
            $parts[$row->t] = $row->c.'/'.$row->i.'/'.$row->s;
        }

        // Keyed and sorted, so the fingerprint doesn't depend on row order.
        ksort($parts);

        return md5(json_encode($parts));
    }
}
