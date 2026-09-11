<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('production_orders', function (Blueprint $table) {
            // Percentage withheld from a VAT order: 0, 1, or 2.
            $table->unsignedTinyInteger('withholding_rate')->default(0)->after('vat_inclusive');
        });
    }

    public function down(): void
    {
        Schema::table('production_orders', function (Blueprint $table) {
            $table->dropColumn('withholding_rate');
        });
    }
};
