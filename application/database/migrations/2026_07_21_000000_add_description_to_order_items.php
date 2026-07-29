<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Per-line description so each size/quantity row on the job order sheet
        // carries its own description (e.g. product name), lined up row-for-row.
        Schema::table('order_items', function (Blueprint $table) {
            $table->string('description', 255)->nullable()->after('size');
        });
    }

    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->dropColumn('description');
        });
    }
};
