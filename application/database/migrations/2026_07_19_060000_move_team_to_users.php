<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Team (VIP/META) belongs to the account officer, so the job order fills it
     * in automatically from whoever created the order.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'team')) {
                $table->string('team')->nullable()->after('job_role');
            }
        });

        Schema::table('job_orders', function (Blueprint $table) {
            if (Schema::hasColumn('job_orders', 'team')) {
                $table->dropColumn('team');
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('team');
        });

        Schema::table('job_orders', function (Blueprint $table) {
            if (! Schema::hasColumn('job_orders', 'team')) {
                $table->string('team')->nullable()->after('status');
            }
        });
    }
};
