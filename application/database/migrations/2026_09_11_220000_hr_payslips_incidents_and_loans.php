<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Stage four and six: what HR issues, and what the employee can read.
 *
 * The payslip RECORDS what payroll worked out; it does not work it out. Gross
 * and the lines that come off it are typed by HR and the net is added up from
 * them — deliberately not a payroll engine, because SSS, PhilHealth and
 * Pag-IBIG tables change every year and a wrong table quietly underpays
 * people. The arithmetic it does do is the arithmetic nobody argues about.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hr_payslips', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hr_employee_id')->constrained('hr_employees')->cascadeOnDelete();

            $table->date('period_start');
            $table->date('period_end');
            $table->decimal('gross', 12, 2)->default(0);

            // [{label, amount}] each. A list rather than columns, because the
            // shop's deductions are not a fixed set and a column per one means
            // a migration every time somebody is docked for something new.
            $table->json('earnings')->nullable();
            $table->json('deductions')->nullable();

            $table->decimal('net', 12, 2)->default(0);
            $table->text('note')->nullable();

            // Until it is released the employee cannot see it — a half-typed
            // payslip is not something to show the person it is about.
            $table->dateTime('released_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['hr_employee_id', 'period_end']);
        });

        Schema::create('hr_incidents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hr_employee_id')->constrained('hr_employees')->cascadeOnDelete();

            $table->date('occurred_on');
            $table->string('kind')->default('note');
            $table->text('description');
            $table->text('action_taken')->nullable();

            // The employee saying they have read it. Not agreement — a record
            // that it was put in front of them.
            $table->dateTime('acknowledged_at')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['hr_employee_id', 'occurred_on']);
        });

        Schema::create('hr_loans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hr_employee_id')->constrained('hr_employees')->cascadeOnDelete();

            $table->decimal('principal', 12, 2);
            $table->string('reason')->nullable();
            $table->date('borrowed_on');
            // What comes off each payslip. A plan, not a promise — what was
            // actually paid is the payments table.
            $table->decimal('per_payslip', 12, 2)->nullable();
            $table->text('note')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('hr_loan_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hr_loan_id')->constrained('hr_loans')->cascadeOnDelete();
            $table->decimal('amount', 12, 2);
            $table->date('paid_on');
            $table->string('note')->nullable();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_loan_payments');
        Schema::dropIfExists('hr_loans');
        Schema::dropIfExists('hr_incidents');
        Schema::dropIfExists('hr_payslips');
    }
};
