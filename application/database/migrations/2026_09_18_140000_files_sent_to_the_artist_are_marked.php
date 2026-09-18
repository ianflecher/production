<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Mark the files an officer SENT the artist, so the artist can see them.
 *
 * They were shown only once the tech pack had gone out, and the tech pack is
 * stage three - the artist is drawing from stage one. So an officer sent a
 * link, the artist was already working, and the page showed nothing: two live
 * links, one of them a name list, sitting on a job nobody could read them on.
 *
 * "Sent" rather than a date comparison: the send box is its own act, and a
 * file put there is for the artist whatever else is happening to the order.
 * The rows already made by that box are the ones with no kind at all.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('job_order_files')
            ->whereNull('kind')
            ->orWhere('kind', '')
            ->update(['kind' => 'sent']);
    }

    public function down(): void
    {
        DB::table('job_order_files')->where('kind', 'sent')->update(['kind' => null]);
    }
};
