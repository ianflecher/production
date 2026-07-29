<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * job_role becomes the single source of truth for what a person is.
     * super_admin / leader / sales live in job_role too; everything else
     * (artist, supply_chain, production, Printer, Sewing, …) is an agent.
     * The permission "role" is derived in the User model, not stored.
     */
    public function up(): void
    {
        DB::table('users')->where('role', 'super_admin')->update(['job_role' => 'super_admin']);
        DB::table('users')->where('role', 'leader')->update(['job_role' => 'leader']);
        DB::table('users')->where('role', 'sales')->update(['job_role' => 'sales']);
        // Safety: any agent without a team defaults to production.
        DB::table('users')->whereNull('job_role')->update(['job_role' => 'production']);

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('role');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('role')->default('agent')->after('password');
        });

        DB::table('users')->whereIn('job_role', ['super_admin', 'leader', 'sales'])->update([
            'role' => DB::raw('job_role'),
        ]);
        DB::table('users')->whereIn('job_role', ['super_admin', 'leader', 'sales'])->update(['job_role' => null]);
    }
};
