<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tech_packs', function (Blueprint $table) {
            $table->text('bottom_text')->nullable()->after('additional_tech_notes');
            $table->unsignedSmallInteger('bottom_image_width')->nullable();
            $table->unsignedSmallInteger('bottom_image_height')->nullable();
            $table->unsignedSmallInteger('bottom_text_width')->nullable();
            $table->unsignedSmallInteger('bottom_text_height')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('tech_packs', function (Blueprint $table) {
            $table->dropColumn([
                'bottom_text', 'bottom_image_width', 'bottom_image_height',
                'bottom_text_width', 'bottom_text_height',
            ]);
        });
    }
};
