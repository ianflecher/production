<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The same board now covers presses, cutting, pairing, sewing and QC —
        // not just printers.
        Schema::rename('printer_sessions', 'station_sessions');

        Schema::table('station_sessions', function (Blueprint $table) {
            $table->renameColumn('printer', 'station');
        });

        // Existing printer rows keyed on the bare printer name; the station keys
        // are prefixed so they can't collide with a press or line station.
        DB::table('station_sessions')->update([
            'station' => DB::raw("CONCAT('printer_', station)"),
        ]);
    }

    public function down(): void
    {
        DB::table('station_sessions')
            ->where('station', 'like', 'printer\_%')
            ->update(['station' => DB::raw("SUBSTRING(station, 9)")]);

        Schema::table('station_sessions', function (Blueprint $table) {
            $table->renameColumn('station', 'printer');
        });

        Schema::rename('station_sessions', 'printer_sessions');
    }
};
