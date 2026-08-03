<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The address a person's PC had when they last signed in.
 *
 * Artists record where a design sits on their own machine. That machine gets
 * its address by DHCP, and staff move between PCs, so the address recorded with
 * a file goes stale. Stamping it at every login means a file path can always be
 * shown with the address that person is on right now.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('last_login_ip', 45)->nullable()->after('last_login_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('last_login_ip');
        });
    }
};
