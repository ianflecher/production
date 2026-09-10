<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tech_packs', function (Blueprint $table) {
            $table->string('imported_pack_path')->nullable()->after('extra_notes');
            $table->string('imported_pack_name')->nullable()->after('imported_pack_path');
        });
    }

    public function down(): void
    {
        Schema::table('tech_packs', function (Blueprint $table) {
            $table->dropColumn(['imported_pack_path', 'imported_pack_name']);
        });
    }
};
