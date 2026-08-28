<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which accountant confirmed it, not which login.
 *
 * The finance desk is one account shared by two people, so `confirmed_by`
 * answers "the finance login" and not "who checked the account". The floor
 * already has this problem and solved it the same way: a station records the
 * operator's name beside the account it was typed on.
 *
 * Nullable — a confirmation that predates this has a login and no name, and
 * inventing one would be worse than leaving it blank.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->string('confirmed_name', 100)->nullable()->after('confirmed_by');
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropColumn('confirmed_name');
        });
    }
};
