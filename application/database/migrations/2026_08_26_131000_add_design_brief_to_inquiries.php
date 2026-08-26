<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('inquiries', 'design_brief')) {
            return;
        }

        Schema::table('inquiries', function (Blueprint $table) {
            $table->json('design_brief')->nullable();
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('inquiries', 'design_brief')) {
            return;
        }

        Schema::table('inquiries', function (Blueprint $table) {
            $table->dropColumn('design_brief');
        });
    }
};
