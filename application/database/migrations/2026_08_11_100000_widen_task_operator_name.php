<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `operator_name` held one person, because one person ran a station.
 *
 * Sewing is not like that: a job order passes through several pairs of hands,
 * each recorded against the seams they ran, and the step now credits all of
 * them. Eight names ran past 100 characters and MySQL refused the write — so
 * finishing the step threw a 500 after the sheet had already been saved.
 *
 * 500 characters is roughly twenty names, which is more than the shop has.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->string('operator_name', 500)->nullable()->change();
        });
    }

    public function down(): void
    {
        // Truncate anything that would not fit, or the column change fails.
        \Illuminate\Support\Facades\DB::table('tasks')
            ->whereNotNull('operator_name')
            ->update(['operator_name' => \Illuminate\Support\Facades\DB::raw('LEFT(operator_name, 100)')]);

        Schema::table('tasks', function (Blueprint $table) {
            $table->string('operator_name', 100)->nullable()->change();
        });
    }
};
