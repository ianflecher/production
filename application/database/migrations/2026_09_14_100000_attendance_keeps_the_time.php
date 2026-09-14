<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Attendance knew whether somebody came, never when.
 *
 * A present/absent flag answers "was the shop's work covered today". It
 * cannot answer any of the questions the office actually gets asked: who is
 * habitually late, whether the overtime somebody filed was worked, how much
 * of a day an early leaver missed. All of that was memory and argument.
 *
 * The minutes are stored rather than derived on the way out. The shift they
 * were measured against can change, and a day already recorded should not
 * quietly re-score itself when it does.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendances', function (Blueprint $table) {
            $table->time('time_in')->nullable()->after('status');
            $table->time('time_out')->nullable()->after('time_in');

            // Unsigned: these are distances, never directions. Which side of
            // the shift a person fell is already said by which column it is in.
            $table->unsignedSmallInteger('late_minutes')->default(0)->after('time_out');
            $table->unsignedSmallInteger('undertime_minutes')->default(0)->after('late_minutes');
            $table->unsignedSmallInteger('overtime_minutes')->default(0)->after('undertime_minutes');
        });
    }

    public function down(): void
    {
        Schema::table('attendances', function (Blueprint $table) {
            $table->dropColumn([
                'time_in', 'time_out',
                'late_minutes', 'undertime_minutes', 'overtime_minutes',
            ]);
        });
    }
};
