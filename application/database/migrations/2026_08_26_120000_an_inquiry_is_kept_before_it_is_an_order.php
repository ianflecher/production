<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An inquiry is kept before it is an order.
 *
 * Somebody asks for a price, gives their details, and then goes quiet. Until
 * now nothing held them: the order form captured the client and the job in one
 * go, so a person who did not order that day left no trace, and chasing them
 * was somebody's notebook. The ones who never came back were the ones nobody
 * could name.
 *
 * So the client details are saved on their own, the moment they are given.
 * That record is the inquiry, and it stays on the officer's follow-up list
 * until it turns into an order — at which point it is answered and drops off.
 *
 * The follow-ups are a table of their own rather than a column on the inquiry:
 * chasing somebody is a thing that happens repeatedly, and "when did we last
 * call, and what did they say" cannot be answered by a field that the next
 * call overwrites.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inquiries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();

            // Whose inquiry it is, and which team they were in when they took
            // it — held here rather than read off the officer, so moving them
            // between teams does not rewrite last month's list.
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('team')->nullable();

            // open — still being chased
            // ordered — became a job; the order it became is below
            // lost — they said no, or went cold and were closed off
            $table->string('status')->default('open');
            $table->foreignId('production_order_id')->nullable()->constrained()->nullOnDelete();

            $table->text('what_they_want')->nullable();
            $table->date('next_follow_up_on')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->string('closed_reason')->nullable();

            $table->timestamps();

            // The follow-up list is "mine, still open, soonest first" and the
            // team leader's is the same by team.
            $table->index(['status', 'next_follow_up_on']);
            $table->index(['created_by', 'status']);
            $table->index(['team', 'status']);
        });

        Schema::create('inquiry_follow_ups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('inquiry_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('note');

            // What this call set up, kept alongside the note so the history
            // reads as a sequence of promises rather than only of remarks.
            $table->date('next_follow_up_on')->nullable();
            $table->timestamps();
        });

        Schema::table('users', function (Blueprint $table) {
            // Kyson runs META, Paula runs VIP: an account officer who also
            // sees, and can chase, the inquiries of everyone on their team.
            $table->boolean('is_team_leader')->default(false)->after('team');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inquiry_follow_ups');
        Schema::dropIfExists('inquiries');

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('is_team_leader');
        });
    }
};
