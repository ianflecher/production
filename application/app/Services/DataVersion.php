<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class DataVersion
{
    /**
     * A cheap fingerprint of the data the UI shows. When any of it changes,
     * open pages notice on their next poll and reload themselves.
     */
    public static function current(): string
    {
        $parts = [
            DB::table('production_orders')->max('updated_at'),
            DB::table('production_orders')->count(),
            DB::table('tasks')->max('updated_at'),
            DB::table('tasks')->count(),
            DB::table('job_orders')->max('updated_at'),
            DB::table('job_order_files')->count(),
            DB::table('task_files')->count(),
            DB::table('payments')->count(),
            DB::table('attendances')->max('updated_at'),
            DB::table('users')->max('updated_at'),
            DB::table('inventory_items')->max('updated_at'),
            DB::table('inventory_items')->count(),
            DB::table('material_requests')->max('updated_at'),
            DB::table('product_items')->max('updated_at'),
            DB::table('product_items')->count(),
            DB::table('product_movements')->count(),
            DB::table('product_receipts')->max('updated_at'),
            DB::table('product_receipts')->count(),
        ];

        return md5(json_encode($parts));
    }
}
