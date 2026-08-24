<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The stage-2 sign-off moves.
 *
 * The template stopped being its own sheet when it became the flats panel
 * inside the tech pack, so the step the leader approves is the pack itself.
 * And the mockup is the client's design — it goes to them through sales, the
 * way the layout already does, rather than being signed off internally.
 *
 * Orders already running are renamed with everything else so the board does
 * not show two names for the same step. Code still recognises the old name
 * (Task::isTechPackStep) in case a row is missed.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('tasks')
            ->where('department', 'Production template')
            ->update(['department' => 'Tech pack']);

        DB::table('tasks')
            ->where('department', 'Final mockup')
            ->where('stage', 2)
            ->update(['approver_role' => 'sales']);
    }

    public function down(): void
    {
        DB::table('tasks')
            ->where('department', 'Tech pack')
            ->update(['department' => 'Production template']);

        DB::table('tasks')
            ->where('department', 'Final mockup')
            ->where('stage', 2)
            ->update(['approver_role' => 'leader']);
    }
};
