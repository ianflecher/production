<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The HR module is taken out.
 *
 * Applicants, interviews, offers, the employment file, payslips, loans,
 * incidents, requests, deadlines and the holiday calendar. All eleven tables
 * were empty on the live database when this ran.
 *
 * attendances STAYS. It looks like an HR table and is not: the artist leader
 * marks the floor present on it every morning, and StaffAssigner reads that to
 * decide who design work can be handed to. Only the clock columns go - the
 * time in and out, and the minutes measured against a shift - because the
 * thing that read them was the payroll.
 *
 * Dropped children-first, so no foreign key is left pointing at a table that
 * has gone.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendances', function (Blueprint $table) {
            $table->dropColumn([
                'time_in', 'time_out',
                'late_minutes', 'undertime_minutes', 'overtime_minutes',
            ]);
        });

        foreach ([
            'hr_loan_payments',
            'hr_loans',
            'hr_payslips',
            'hr_incidents',
            'hr_requests',
            'hr_deadlines',
            'hr_employees',
            'hr_job_offers',
            'hr_interviews',
            'hr_applicants',
            'holidays',
        ] as $table) {
            Schema::dropIfExists($table);
        }
    }

    /**
     * Not reversible.
     *
     * Putting eleven tables back would mean copying every column definition
     * out of five migrations into this one, where they would immediately
     * start drifting from the originals. The originals are still in the
     * repository and in the history: bringing HR back means reverting the
     * commit that removed it, not running this backwards.
     */
    public function down(): void
    {
        throw new \RuntimeException(
            'The HR module was removed deliberately. Restore it by reverting '
            .'the commit, not by rolling this migration back.'
        );
    }
};
