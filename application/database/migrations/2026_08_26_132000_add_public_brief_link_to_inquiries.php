<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inquiries', function (Blueprint $table) {
            $table->string('brief_token', 64)->nullable()->unique();
            $table->timestamp('brief_expires_at')->nullable();
            $table->timestamp('client_brief_submitted_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('inquiries', function (Blueprint $table) {
            $table->dropUnique(['brief_token']);
            $table->dropColumn(['brief_token', 'brief_expires_at', 'client_brief_submitted_at']);
        });
    }
};
