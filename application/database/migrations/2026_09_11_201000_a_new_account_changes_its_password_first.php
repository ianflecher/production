<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An account made FOR somebody has a password they did not choose.
 *
 * HR hands a new hire a username and a temporary password, and the shop's
 * existing "Reset password" does the same thing - both leave an account whose
 * password is known to somebody other than its owner. This flag says so, and
 * the app walks them to the change-password page until it is cleared.
 *
 * Default false, so not one existing account is affected: nobody signing in
 * tomorrow sees anything different unless HR made their account today.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('must_change_password')->default(false)->after('password');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('must_change_password');
        });
    }
};
