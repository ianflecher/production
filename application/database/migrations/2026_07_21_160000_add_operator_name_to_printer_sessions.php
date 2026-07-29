<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Accounts are often shared on the floor, so the person actually running
        // the printer types their own name when they start.
        Schema::table('printer_sessions', function (Blueprint $table) {
            $table->string('operator_name', 100)->nullable()->after('user_id');
        });
    }

    public function down(): void
    {
        Schema::table('printer_sessions', function (Blueprint $table) {
            $table->dropColumn('operator_name');
        });
    }
};
