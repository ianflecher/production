<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A note on every step of the pipeline.
 *
 * `instructions` is what the step was TOLD; `revision_note` is why it came
 * back. Neither is a place for the person who ran it to say what happened —
 * the machine was down till noon, the fabric ran short, we used the other
 * thread. That went in a group chat, or nowhere.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->text('note')->nullable()->after('operator_name');
        });
    }

    public function down(): void
    {
        Schema::table('tasks', fn (Blueprint $t) => $t->dropColumn('note'));
    }
};
