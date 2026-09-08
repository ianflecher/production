<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One client, one brief, many designs.
 *
 * An enquiry carried ONE layout: one artist, one set of files, one approval,
 * one revision counter. A client who wants ten designs is ordinary - a moto
 * team orders a jersey, a jacket, shorts, and one each for two riders - and
 * there was nowhere to put that. The shop either opened ten enquiries for one
 * client, losing the fact that it is one job, or piled ten drawings onto one
 * layout, where they went to one artist and the client had to accept or reject
 * the lot. Eight approved and two to redo could not be written down at all.
 *
 * A design is its own row now. It has its own artist, so five can go to one
 * and five to another; its own files; and its own approve / send-back cycle
 * with its own count of the rounds used. The brief above it stays where it is,
 * because it is the same brief for all of them, and so does the client.
 *
 * The enquiry's own layout_status is left in place and is now worked out FROM
 * the designs: with the artists until they are all handed back, submitted once
 * they are, approved only when every one of them is.
 *
 * Everything already drawn becomes a single design, so an enquiry mid-flight
 * keeps its artist, its drawings and the rounds it has already used, and the
 * app only ever has to speak one language.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inquiry_designs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('inquiry_id')->constrained()->cascadeOnDelete();

            // What the shop calls it - "Jersey", "Rider 2". Optional: a client
            // who wants three of the same thing has nothing to call them.
            $table->string('label', 120)->nullable();
            $table->unsignedSmallInteger('position')->default(0);

            // Whose desk it is on. Nullable for the same reason the enquiry's
            // is: nobody may be in when the brief is written.
            $table->foreignId('artist_id')->nullable()->constrained('users')->nullOnDelete();

            $table->string('status', 20)->default('with_artist');
            $table->json('files')->nullable();

            $table->text('revision_note')->nullable();
            $table->unsignedTinyInteger('revision_count')->default(0);

            $table->timestamp('sent_at')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('approved_at')->nullable();

            $table->timestamps();

            // The artist's queue is "my designs, oldest first", and the brief
            // page reads them by enquiry.
            $table->index(['artist_id', 'status']);
            $table->index(['inquiry_id', 'position']);
        });

        // What has already been drawn becomes one design, so nothing mid-flight
        // has to be re-entered and every page can read designs from here on.
        //
        // The officer's brief material stays on the enquiry - it is the brief
        // for every design, not one design's drawing. Only the artist's own
        // files (kind = layout) come across, and the enquiry keeps its copy:
        // this migration adds, it does not take anything away.
        $existing = DB::table('inquiries')
            ->where(function ($q) {
                $q->whereNotNull('layout_artist_id')->orWhereNotNull('layout_files');
            })
            ->get();

        foreach ($existing as $inquiry) {
            $files = json_decode($inquiry->layout_files ?? '[]', true) ?: [];

            $drawings = array_values(array_filter(
                $files,
                fn ($file) => ($file['kind'] ?? 'output') === 'layout'
            ));

            DB::table('inquiry_designs')->insert([
                'inquiry_id' => $inquiry->id,
                'label' => null,
                'position' => 0,
                'artist_id' => $inquiry->layout_artist_id,
                'status' => $inquiry->layout_status ?: 'with_artist',
                'files' => $drawings ? json_encode($drawings) : null,
                'revision_note' => $inquiry->layout_revision_note,
                'revision_count' => (int) ($inquiry->layout_revision_count ?? 0),
                'sent_at' => $inquiry->layout_sent_at,
                'submitted_at' => $inquiry->layout_submitted_at,
                'approved_at' => $inquiry->layout_approved_at,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('inquiry_designs');
    }
};
