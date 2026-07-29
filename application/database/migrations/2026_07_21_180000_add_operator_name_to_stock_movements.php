<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Accounts are shared on the floor, so whoever physically brings stock in
        // or takes it out types their own name.
        Schema::table('stock_movements', function (Blueprint $table) {
            $table->string('operator_name', 100)->nullable()->after('user_id');
        });
    }

    public function down(): void
    {
        Schema::table('stock_movements', function (Blueprint $table) {
            $table->dropColumn('operator_name');
        });
    }
};
