<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The mover's account is shared — several people walk the floor under it,
     * the way the machine stations are shared. A message signed only "Mover"
     * says nothing about who to answer, so the person types their own name,
     * exactly as an operator does when taking a station.
     */
    public function up(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->string('sender_name', 100)->nullable()->after('sender_id');
        });
    }

    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->dropColumn('sender_name');
        });
    }
};
