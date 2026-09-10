<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * A former migration copied an inquiry's old `brief` layout status into
     * its first design. A sent brief with an assigned artist is work that is
     * already on that artist's desk, so it must use the design-state name the
     * queue understands: `with_artist`.
     */
    public function up(): void
    {
        DB::table('inquiry_designs')
            ->where('status', 'brief')
            ->whereNotNull('artist_id')
            ->whereNotNull('sent_at')
            ->update(['status' => 'with_artist', 'updated_at' => now()]);
    }

    public function down(): void
    {
        // Do not turn real active artist work back into the obsolete state.
    }
};
