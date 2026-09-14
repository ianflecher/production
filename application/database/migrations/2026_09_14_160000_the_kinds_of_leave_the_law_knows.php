<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The kinds of leave, and the paper that goes with them.
 *
 * Two kinds were offered - vacation and sick - and everything else had to be
 * filed as one of them. A woman taking her 105 days of maternity leave filed
 * it as sick leave and it came off her sick allowance, which is not what
 * maternity leave is: it is a separate statutory entitlement and it is not
 * illness. The same for a funeral, and for the seven days a father gets.
 *
 * Taken from the shop's own TGIF HR module, which has the full list.
 *
 * Only vacation and sick draw on an allowance, because those are the two the
 * shop grants. The rest are entitlements in their own right or unpaid, and
 * counting them against a holiday balance would be wrong - so they are
 * recorded, counted in working days, and draw nothing down.
 *
 * The attachment is the other half. Sick leave past a couple of days wants a
 * medical certificate and maternity wants proof of dates; the desk was being
 * sent photographs over chat because the request had nowhere to put one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hr_requests', function (Blueprint $table) {
            // Where the certificate or proof lives. One per request: the desk
            // asks for a document, not a folder.
            $table->string('attachment_path')->nullable()->after('reason');
            $table->string('attachment_name')->nullable()->after('attachment_path');
        });

        Schema::table('hr_employees', function (Blueprint $table) {
            // active / on_leave / suspended / ended. ended_on already says
            // WHEN somebody left; this says what they are now, which is the
            // question a payroll run and a station board both need to ask.
            $table->string('status')->default('active')->after('salary_period');
        });

        // Anybody with a leaving date is not active, whatever the new column
        // defaults to.
        \DB::table('hr_employees')->whereNotNull('ended_on')->update(['status' => 'ended']);
    }

    public function down(): void
    {
        Schema::table('hr_requests', function (Blueprint $table) {
            $table->dropColumn(['attachment_path', 'attachment_name']);
        });

        Schema::table('hr_employees', function (Blueprint $table) {
            $table->dropColumn('status');
        });
    }
};
