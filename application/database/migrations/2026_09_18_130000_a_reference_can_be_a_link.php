<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What the officer sends the artist can be a link, not only a file.
 *
 * Half of what a client sends arrives as a link - a Drive folder, a Facebook
 * post, a Pinterest board - and uploading it meant downloading it first, or
 * pasting the address into a chat the system cannot see.
 *
 * Named external_path because that is what task_files already calls the same
 * idea: a thing that lives somewhere else, either on the shared drive or on
 * the web. One name for one concept beats two.
 *
 * path becomes nullable: a link has no file behind it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('job_order_files', function (Blueprint $table) {
            $table->string('external_path', 2000)->nullable()->after('path');
            $table->string('path')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('job_order_files', function (Blueprint $table) {
            $table->dropColumn('external_path');
        });
    }
};
