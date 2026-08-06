<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * When a step was released to whoever works it.
     *
     * submitted_at and approved_at say when it was handed in and signed off,
     * but nothing said when it STARTED being available — which is what marks
     * the moment a job reached a particular part of the shop.
     */
    public function up(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->timestamp('released_at')->nullable()->after('approved_at');
        });

        // Steps already out of TODO were released at some point in the past;
        // their last edit is the closest record of it we have.
        DB::table('tasks')
            ->whereNotIn('status', ['todo'])
            ->whereNull('released_at')
            ->update(['released_at' => DB::raw('updated_at')]);
    }

    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->dropColumn('released_at');
        });
    }
};
