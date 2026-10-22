<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tech_packs', function (Blueprint $table) {
            $table->boolean('sample_all_sizes')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('tech_packs', fn (Blueprint $table) => $table->dropColumn('sample_all_sizes'));
    }
};
