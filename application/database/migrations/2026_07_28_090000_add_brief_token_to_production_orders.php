<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('production_orders', function (Blueprint $table) {
            // Random, unguessable code used in the public design-questionnaire
            // link (replaces the signed signature), plus when that link expires.
            $table->string('brief_token', 40)->nullable()->unique()->after('order_number');
            $table->timestamp('brief_expires_at')->nullable()->after('brief_token');
        });
    }

    public function down(): void
    {
        Schema::table('production_orders', function (Blueprint $table) {
            $table->dropColumn(['brief_token', 'brief_expires_at']);
        });
    }
};
