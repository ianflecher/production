<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Production/design files (mockup, template, TIFF, sticker, embroidery) are
     * referenced by a network path instead of being uploaded — saves disk space.
     * When set, `path` (the stored copy) stays null and this holds the location.
     */
    public function up(): void
    {
        Schema::table('task_files', function (Blueprint $table) {
            $table->string('external_path', 1024)->nullable()->after('path');
            $table->string('path')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('task_files', function (Blueprint $table) {
            $table->dropColumn('external_path');
        });
    }
};
