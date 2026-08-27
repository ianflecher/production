<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The layout is drawn and approved before the job order is written.
 *
 * An order used to be written first and the artist told afterwards, which
 * meant committing a job number, a price and a due date to something the
 * client had not seen yet. When they then asked for a different design, the
 * order was already on the books.
 *
 * So the artist now works from the inquiry: it goes into a layout queue of
 * their own, they draw it, and the officer marks it approved once the client
 * has said yes. Only then does the job order open. Nothing is committed to the
 * books until there is a design the client actually wants.
 *
 * The artist is held here rather than assigned through a task, because there
 * is no order yet for a task to belong to — that is the whole point.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inquiries', function (Blueprint $table) {
            // brief → with_artist → submitted → approved
            $table->string('layout_status')->default('brief')->after('layout_brief_completed_at');
            $table->foreignId('layout_artist_id')->nullable()->after('layout_status')
                ->constrained('users')->nullOnDelete();
            $table->timestamp('layout_sent_at')->nullable()->after('layout_artist_id');
            $table->timestamp('layout_submitted_at')->nullable()->after('layout_sent_at');
            $table->timestamp('layout_approved_at')->nullable()->after('layout_submitted_at');
            $table->text('layout_revision_note')->nullable()->after('layout_approved_at');

            // The artist's queue is "mine, still to draw", read on every page
            // they open.
            $table->index(['layout_artist_id', 'layout_status']);
        });

        Schema::table('production_orders', function (Blueprint $table) {
            // The order remembers that its layout was approved back on the
            // inquiry. Everything downstream — the downpayment gate, the
            // "awaiting client approval" alert, whether the floor may start —
            // asks layoutApproved(), and with no Layout task left on the
            // pipeline there is nothing else for it to read.
            $table->timestamp('layout_approved_at')->nullable()->after('due_date');
        });
    }

    public function down(): void
    {
        Schema::table('inquiries', function (Blueprint $table) {
            $table->dropForeign(['layout_artist_id']);
            $table->dropIndex(['layout_artist_id', 'layout_status']);
            $table->dropColumn([
                'layout_status', 'layout_artist_id', 'layout_sent_at',
                'layout_submitted_at', 'layout_approved_at', 'layout_revision_note',
            ]);
        });

        Schema::table('production_orders', function (Blueprint $table) {
            $table->dropColumn('layout_approved_at');
        });
    }
};
