<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Raw materials and Sticker are now chosen on the job order (like decoration
     * & cutting) and decide whether those supply-chain steps get created.
     */
    public function up(): void
    {
        Schema::table('production_orders', function (Blueprint $table) {
            $table->boolean('needs_raw_materials')->default(true)->after('cutting_type');
            $table->boolean('needs_sticker')->default(true)->after('needs_raw_materials');
        });
    }

    public function down(): void
    {
        Schema::table('production_orders', function (Blueprint $table) {
            $table->dropColumn(['needs_raw_materials', 'needs_sticker']);
        });
    }
};
