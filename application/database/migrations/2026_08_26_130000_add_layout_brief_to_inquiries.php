<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inquiries', function (Blueprint $table) {
            $table->text('layout_reference_note')->nullable();
            $table->json('layout_files')->nullable();
            $table->timestamp('layout_brief_completed_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('inquiries', function (Blueprint $table) {
            $table->dropColumn(['layout_reference_note', 'layout_files', 'layout_brief_completed_at']);
        });
    }
};
