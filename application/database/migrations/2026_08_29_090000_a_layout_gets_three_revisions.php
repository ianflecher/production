<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Count the rounds a layout has been sent back.
 *
 * The note said what the client wanted changed but was overwritten each time,
 * so nothing recorded how many rounds a job had already cost. Three is the
 * shop's limit for an account officer; a leader can still give a fourth.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inquiries', function (Blueprint $table) {
            $table->unsignedTinyInteger('layout_revision_count')->default(0)->after('layout_revision_note');
        });
    }

    public function down(): void
    {
        Schema::table('inquiries', function (Blueprint $table) {
            $table->dropColumn('layout_revision_count');
        });
    }
};
