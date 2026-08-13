<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * "Release to client" moves from the account officer to the products desk.
 *
 * It is the last step of the pipeline: the boxes are handed over at the
 * counter. The products desk is holding them; the account officer never
 * touches them and was being asked to confirm a handover they were not part
 * of, from a page meant for reviewing samples.
 *
 * Orders already waiting move with it — otherwise every order in flight keeps
 * asking the wrong desk until it closes.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('tasks')
            ->where('department', 'Release to client')
            ->where('approver_role', 'sales')
            ->update(['approver_role' => 'inventory']);
    }

    public function down(): void
    {
        DB::table('tasks')
            ->where('department', 'Release to client')
            ->where('approver_role', 'inventory')
            ->update(['approver_role' => 'sales']);
    }
};
