<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /** Maam Madel manages sewing, not the rest of the production line. */
    public function up(): void
    {
        DB::table('users')
            ->where('email', 'SewingSup@imprintcustoms.ph')
            ->update(['job_role' => 'sewing supervisor']);
    }

    public function down(): void
    {
        DB::table('users')
            ->where('email', 'SewingSup@imprintcustoms.ph')
            ->where('job_role', 'sewing supervisor')
            ->update(['job_role' => 'supervisor']);
    }
};
