<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // MySQL gives the FIRST timestamp column an implicit
        // "ON UPDATE CURRENT_TIMESTAMP", which was silently resetting
        // started_at every time the row was updated (e.g. when ending a stint).
        // datetime has no such behaviour.
        Schema::table('printer_sessions', function (Blueprint $table) {
            $table->dateTime('started_at')->change();
            $table->dateTime('ended_at')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('printer_sessions', function (Blueprint $table) {
            $table->timestamp('started_at')->change();
            $table->timestamp('ended_at')->nullable()->change();
        });
    }
};
