<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tech_packs', function (Blueprint $table) {
            $table->string('zipper_type', 60)->nullable()->after('color_type');
            $table->string('lip_pocket_color', 60)->nullable()->after('zipper_type');
        });
    }

    public function down(): void
    {
        Schema::table('tech_packs', function (Blueprint $table) {
            $table->dropColumn(['zipper_type', 'lip_pocket_color']);
        });
    }
};
