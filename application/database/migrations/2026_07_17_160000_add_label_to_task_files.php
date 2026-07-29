<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('task_files', function (Blueprint $table) {
            // Which slot this file fills, e.g. "Mockup design" / "Template".
            $table->string('label')->nullable()->after('original_name');
        });
    }

    public function down(): void
    {
        Schema::table('task_files', function (Blueprint $table) {
            $table->dropColumn('label');
        });
    }
};
