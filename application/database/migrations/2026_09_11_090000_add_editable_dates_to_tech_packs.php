<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tech_packs', function (Blueprint $table) {
            $table->date('pack_created_date')->nullable()->after('artist_name');
            $table->date('pack_delivery_date')->nullable()->after('pack_created_date');
        });
    }

    public function down(): void
    {
        Schema::table('tech_packs', function (Blueprint $table) {
            $table->dropColumn(['pack_created_date', 'pack_delivery_date']);
        });
    }
};
