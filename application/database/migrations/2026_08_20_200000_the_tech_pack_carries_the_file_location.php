<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The Export step goes.
 *
 * Its whole job was to carry one thing — where the print-ready files were
 * saved — and the tech pack already has a file location panel on the sheet the
 * floor reads. So the step was a gate with nothing behind it: the printer sat
 * waiting for somebody to close a task whose answer was already on the pack.
 *
 * Orders already running lose the step too, or they would wait forever on a
 * gate nothing opens. Anything the artist attached to it is left alone — the
 * files stay on the order, they just no longer hold the printer up.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('tasks')
            ->whereIn('department', ['Export', 'Export TIFF', 'Export sticker', 'Export embroidery'])
            ->whereIn('status', ['todo', 'ready', 'in_progress', 'for_checking', 'revision_required'])
            ->update(['status' => 'cancelled']);
    }

    public function down(): void
    {
        DB::table('tasks')
            ->whereIn('department', ['Export', 'Export TIFF', 'Export sticker', 'Export embroidery'])
            ->where('status', 'cancelled')
            ->update(['status' => 'todo']);
    }
};
