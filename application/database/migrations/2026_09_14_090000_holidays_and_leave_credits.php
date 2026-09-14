<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Days the shop is shut, and the leave each person is allowed.
 *
 * A leave request carried two dates and nothing counted what lay between
 * them. Four days off over a weekend is two days of leave, and over Holy Week
 * it may be one — the desk was working that out on paper every time, and
 * nothing on the request said which answer had been used.
 *
 * And nobody had a balance. A person could file leave every week of the year
 * and the only thing standing between them and it was somebody remembering
 * how much they had already taken.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('holidays', function (Blueprint $table) {
            $table->id();
            $table->date('date')->unique();
            $table->string('name');

            // Regular holidays are paid and worked differently from the
            // special non-working ones. The shop needs to tell them apart
            // even though today only the working-day count reads this.
            $table->string('kind')->default('regular');

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('date');
        });

        Schema::table('hr_employees', function (Blueprint $table) {
            // What they are allowed in a year. Nullable rather than zero:
            // null is "nobody has set this", which is not the same as "none",
            // and staff who predate the HR desk should not read as having
            // used up an allowance nobody ever gave them.
            $table->unsignedSmallInteger('vacation_credits')->nullable()->after('salary_period');
            $table->unsignedSmallInteger('sick_credits')->nullable()->after('vacation_credits');
        });

        Schema::table('hr_requests', function (Blueprint $table) {
            // Worked out when the request is filed and kept, so the number the
            // desk decided on is the number that stays on the record even if
            // the holiday calendar changes afterwards.
            $table->decimal('working_days', 5, 2)->nullable()->after('ends_at');
        });
    }

    public function down(): void
    {
        Schema::table('hr_requests', function (Blueprint $table) {
            $table->dropColumn('working_days');
        });

        Schema::table('hr_employees', function (Blueprint $table) {
            $table->dropColumn(['vacation_credits', 'sick_credits']);
        });

        Schema::dropIfExists('holidays');
    }
};
