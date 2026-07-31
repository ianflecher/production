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
        $concat = DB::getDriverName() === 'mysql'
            ? "CONCAT('printer_', station)"   // MySQL
            : "'printer_' || station";        // SQLite / others
        DB::table('station_sessions')->update([
            'station' => DB::raw($concat),
        ]);
    }

    public function down(): void
    {
        $substr = DB::getDriverName() === 'mysql'
            ? "SUBSTRING(station, 9)"   // MySQL
            : "substr(station, 9)";     // SQLite / others
        DB::table('station_sessions')
            ->where('station', 'like', 'printer\_%')
            ->update(['station' => DB::raw($substr)]);

        Schema::table('station_sessions', function (Blueprint $table) {
            $table->renameColumn('station', 'printer');
        });

        Schema::rename('station_sessions', 'printer_sessions');
    }
};
