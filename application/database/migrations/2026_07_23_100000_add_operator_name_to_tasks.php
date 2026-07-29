<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Floor accounts are shared, so the person who actually ran a step is the name
 * they typed at the station — not the account it was auto-assigned to. Store it
 * on the task so the pipeline shows who really did the work.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->string('operator_name', 100)->nullable()->after('assigned_to');
        });
    }

    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->dropColumn('operator_name');
        });
    }
};
