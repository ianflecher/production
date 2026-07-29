<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Which production team a person belongs to.
        Schema::table('users', function (Blueprint $table) {
            $table->string('job_role')->nullable()->after('role');
        });

        DB::statement("UPDATE users SET job_role = 'artist' WHERE is_artist = 1");

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('is_artist');
        });

        Schema::table('tasks', function (Blueprint $table) {
            // Which team owns this step (artist / supply_chain / production).
            $table->string('team')->nullable()->after('department');
            // Review-only steps (the job order) go straight to the approver.
            $table->boolean('auto_submit')->default(false)->after('auto_assign');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_artist')->default(false)->after('role');
        });
        DB::statement("UPDATE users SET is_artist = 1 WHERE job_role = 'artist'");
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('job_role');
        });
        Schema::table('tasks', function (Blueprint $table) {
            $table->dropColumn(['team', 'auto_submit']);
        });
    }
};
