<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Which questionnaire question the file was uploaded under
        // ("peg" = design peg/inspiration, "logo" = logo files, null = general).
        Schema::table('job_order_files', function (Blueprint $table) {
            $table->string('kind', 20)->nullable()->after('original_name');
        });
    }

    public function down(): void
    {
        Schema::table('job_order_files', function (Blueprint $table) {
            $table->dropColumn('kind');
        });
    }
};
