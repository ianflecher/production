<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_items', function (Blueprint $table) {
            // Category groups the raw material (sewing / printer / fabric / production).
            $table->string('category', 40)->nullable()->after('name');
            $table->string('code', 60)->nullable()->after('category');
            $table->string('photo')->nullable()->after('code');
            $table->string('size', 60)->nullable()->after('photo');
            $table->string('color', 60)->nullable()->after('size');
            // The starting stock when the item was first added — `quantity` is the
            // remaining stock now.
            $table->decimal('beginning_stock', 12, 2)->default(0)->after('quantity');
        });
    }

    public function down(): void
    {
        Schema::table('inventory_items', function (Blueprint $table) {
            $table->dropColumn(['category', 'code', 'photo', 'size', 'color', 'beginning_stock']);
        });
    }
};
