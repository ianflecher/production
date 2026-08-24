<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Take the Export step off the board.
 *
 * Cancelling it stopped it holding the printer up, but it still printed a row
 * on every order's step list — a step nobody will ever work, sitting between
 * the tech pack and the raw materials. The step is gone, so the row goes too.
 *
 * One exception: an Export step that has files attached is left alone. Those
 * are the artist's print-ready paths, and the row is the only thing pointing at
 * them. A stale line on the board is a smaller loss than a lost file path.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('tasks')
            ->whereIn('department', ['Export', 'Export TIFF', 'Export sticker', 'Export embroidery'])
            ->whereNotExists(fn ($q) => $q
                ->select(DB::raw(1))
                ->from('task_files')
                ->whereColumn('task_files.task_id', 'tasks.id'))
            ->delete();
    }

    public function down(): void
    {
        // The pipeline no longer builds this step, so there is nothing to put
        // back: rebuilding an order's pipeline is how it would return.
    }
};
