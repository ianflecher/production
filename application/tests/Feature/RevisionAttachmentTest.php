<?php

namespace Tests\Feature;

use App\Models\Inquiry;
use App\Models\InquiryDesign;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Sending a layout back can carry files.
 *
 * What the client wants changed is often easier shown than said — a marked-up
 * screenshot, a photo, the reference they actually meant. The note stays
 * required; the files are the optional half, and they land in the same place
 * the artist already looks for references.
 */
class RevisionAttachmentTest extends TestCase
{
    use RefreshDatabase;

    /** An inquiry with a layout the artist has handed in, ready to be sent back. */
    private function submittedLayout(): array
    {
        $officer = User::factory()->create(['job_role' => User::ROLE_SALES, 'is_active' => true]);
        $artist = User::factory()->create(['job_role' => 'artist', 'is_active' => true]);

        $inquiry = Inquiry::create([
            'client_id' => \App\Models\Client::create([
                'name' => 'Juan', 'last_name' => 'Dela Cruz', 'contact_number' => '0917',
                'office_address' => 'Angeles City', 'delivery_address' => 'Angeles City',
                'created_by' => $officer->id,
            ])->id,
            'created_by' => $officer->id,
            'status' => Inquiry::STATUS_OPEN,
            'layout_status' => Inquiry::LAYOUT_SUBMITTED,
            'layout_artist_id' => $artist->id,
            'layout_sent_at' => now()->subDay(),
            'layout_submitted_at' => now()->subHour(),
        ]);

        // Handed back and waiting on the client - the design says so, because
        // that is where the state lives now.
        $inquiry->designs()->create([
            'position' => 0,
            'artist_id' => $artist->id,
            'status' => InquiryDesign::STATUS_SUBMITTED,
            'sent_at' => now()->subDay(),
            'submitted_at' => now()->subHour(),
        ]);

        return [$officer, $artist, $inquiry];
    }

    public function test_a_revision_can_carry_a_file(): void
    {
        Storage::fake('local');
        [$officer, , $inquiry] = $this->submittedLayout();

        $this->actingAs($officer)->post(route('inquiries.layout.revise', $inquiry), [
            'layout_revision_note' => 'Move the logo to the left chest.',
            'revision_files' => [UploadedFile::fake()->image('markup.png')],
        ])->assertRedirect();

        $inquiry->refresh();

        $this->assertSame(Inquiry::LAYOUT_WITH_ARTIST, $inquiry->layout_status);
        $this->assertNull($inquiry->layout_submitted_at, 'it goes back to being undrawn');

        $files = collect($inquiry->layout_files ?? []);
        $this->assertCount(1, $files);
        $this->assertSame('revision', $files->first()['kind']);
        $this->assertSame('markup.png', $files->first()['original_name']);
        $this->assertSame($officer->id, $files->first()['uploaded_by']);
        Storage::disk('local')->assertExists($files->first()['path']);
    }

    public function test_several_files_can_go_back_at_once(): void
    {
        Storage::fake('local');
        [$officer, , $inquiry] = $this->submittedLayout();

        $this->actingAs($officer)->post(route('inquiries.layout.revise', $inquiry), [
            'layout_revision_note' => 'Two things wrong.',
            'revision_files' => [
                UploadedFile::fake()->image('front.png'),
                UploadedFile::fake()->image('back.jpg'),
            ],
        ])->assertRedirect();

        $this->assertCount(2, $inquiry->refresh()->layout_files);
    }

    public function test_the_file_is_optional_and_the_note_is_not(): void
    {
        [$officer, , $inquiry] = $this->submittedLayout();

        // Note alone still works, exactly as before.
        $this->actingAs($officer)->post(route('inquiries.layout.revise', $inquiry), [
            'layout_revision_note' => 'Just make it bigger.',
        ])->assertRedirect();

        $this->assertNull($inquiry->refresh()->layout_files);

        // A file with no note is still refused.
        [$officer2, , $inquiry2] = $this->submittedLayout();
        Storage::fake('local');

        $this->actingAs($officer2)->post(route('inquiries.layout.revise', $inquiry2), [
            'revision_files' => [UploadedFile::fake()->image('markup.png')],
        ])->assertInvalid(['layout_revision_note']);

        $this->assertNull($inquiry2->refresh()->layout_files);
    }

    public function test_a_junk_file_type_is_refused(): void
    {
        Storage::fake('local');
        [$officer, , $inquiry] = $this->submittedLayout();

        $this->actingAs($officer)->post(route('inquiries.layout.revise', $inquiry), [
            'layout_revision_note' => 'See attached.',
            'revision_files' => [UploadedFile::fake()->create('script.exe', 10)],
        ])->assertInvalid(['revision_files.0']);

        $this->assertNull($inquiry->refresh()->layout_files);
        $this->assertSame(Inquiry::LAYOUT_SUBMITTED, $inquiry->layout_status, 'a refused upload changes nothing');
    }

    public function test_earlier_references_are_kept_not_replaced(): void
    {
        Storage::fake('local');
        [$officer, , $inquiry] = $this->submittedLayout();

        $inquiry->update(['layout_files' => [[
            'path' => 'inquiry-layouts/old.png', 'original_name' => 'peg.png',
            'mime' => 'image/png', 'size' => 10, 'uploaded_by' => $officer->id, 'kind' => 'output',
        ]]]);

        $this->actingAs($officer)->post(route('inquiries.layout.revise', $inquiry), [
            'layout_revision_note' => 'Closer to the peg.',
            'revision_files' => [UploadedFile::fake()->image('markup.png')],
        ])->assertRedirect();

        $files = collect($inquiry->refresh()->layout_files);
        $this->assertCount(2, $files, 'the original reference survives');
        $this->assertSame('peg.png', $files[0]['original_name']);
        $this->assertSame('markup.png', $files[1]['original_name']);
    }

    public function test_no_upload_box_while_the_design_sits_with_the_client(): void
    {
        // It used to be offered here, so an artist holding a revised drawing
        // had somewhere to put it. In the shop that read as an instruction:
        // the box invited a revision nobody had asked for, and sending one
        // quietly took the design off the client's desk.
        Storage::fake('local');
        [, $artist] = $this->submittedLayout();

        $this->actingAs($artist)->get(route('inquiries.layouts'))
            ->assertOk()
            ->assertSee('waiting on the client')
            ->assertDontSee('Upload the revised design')
            ->assertDontSee('name="files[]"', false);
    }

    public function test_the_box_comes_back_when_the_client_asks_for_a_change(): void
    {
        Storage::fake('local');
        [$officer, $artist, $inquiry] = $this->submittedLayout();

        $this->sendBack($officer, $inquiry, 'Make the logo bigger.')->assertRedirect();

        // Back on the artist's desk, carrying what the client said - and now
        // there is somewhere to put the redraw.
        $this->actingAs($artist)->get(route('inquiries.layouts'))
            ->assertOk()
            ->assertSee('Make the logo bigger.')
            ->assertSee('Upload the revised design');

        $this->actingAs($artist)->post(route('inquiries.layout.submit', $inquiry), [
            'layout_files' => [UploadedFile::fake()->image('v2.png')],
        ])->assertRedirect();

        $files = collect($inquiry->refresh()->layout_files);
        $this->assertCount(1, $files);
        $this->assertSame('layout', $files->first()['kind']);
        $this->assertSame('v2.png', $files->first()['original_name']);
    }

    public function test_a_revised_upload_clears_the_change_that_was_asked_for(): void
    {
        Storage::fake('local');
        [$officer, $artist, $inquiry] = $this->submittedLayout();

        $this->actingAs($officer)->post(route('inquiries.layout.revise', $inquiry), [
            'layout_revision_note' => 'Move the logo.',
        ])->assertRedirect();

        $this->actingAs($artist)->post(route('inquiries.layout.submit', $inquiry), [
            'layout_files' => [UploadedFile::fake()->image('v2.png')],
        ])->assertRedirect();

        $inquiry->refresh();
        $this->assertNull($inquiry->layout_revision_note, 'the note is answered by the new drawing');
        $this->assertSame(Inquiry::LAYOUT_SUBMITTED, $inquiry->layout_status);
        $this->assertNotNull($inquiry->layout_submitted_at);
    }

    public function test_someone_elses_layout_is_still_not_theirs_to_upload(): void
    {
        Storage::fake('local');
        [, , $inquiry] = $this->submittedLayout();
        $other = User::factory()->create(['job_role' => 'artist', 'is_active' => true]);

        $this->actingAs($other)->post(route('inquiries.layout.submit', $inquiry), [
            'layout_files' => [UploadedFile::fake()->image('sneaky.png')],
        ])->assertForbidden();

        $this->assertNull($inquiry->refresh()->layout_files);
    }

    // ---- three revisions, then a leader ------------------------------

    private function sendBack(User $user, Inquiry $inquiry, string $note = 'Change it.')
    {
        // Refresh first: update() writes only what is dirty, so putting the
        // layout back with a stale model that still says "submitted" in memory
        // would save nothing and the next send-back would 403.
        //
        // The DESIGNS are what say "waiting on the client" now - the brief's
        // own status is worked out from them - so they are what has to be put
        // back for another round.
        $inquiry->refresh()->designs()->update([
            'status' => InquiryDesign::STATUS_SUBMITTED,
            'submitted_at' => now(),
        ]);

        $inquiry->update([
            'layout_status' => Inquiry::LAYOUT_SUBMITTED,
            'layout_submitted_at' => now(),
        ]);

        return $this->actingAs($user)->post(route('inquiries.layout.revise', $inquiry), [
            'layout_revision_note' => $note,
        ]);
    }

    public function test_each_send_back_is_counted(): void
    {
        [$officer, , $inquiry] = $this->submittedLayout();

        foreach ([1, 2, 3] as $round) {
            $this->sendBack($officer, $inquiry)->assertRedirect();
            $this->assertSame($round, (int) $inquiry->refresh()->layout_revision_count);
        }
    }

    public function test_the_officer_is_stopped_after_three(): void
    {
        [$officer, , $inquiry] = $this->submittedLayout();

        foreach ([1, 2, 3] as $round) {
            $this->sendBack($officer, $inquiry)->assertRedirect();
        }

        $this->sendBack($officer, $inquiry, 'One more thing.')
            ->assertInvalid(['layout_revision_note']);

        $inquiry->refresh();
        $this->assertSame(3, (int) $inquiry->layout_revision_count, 'the refused round is not counted');
        $this->assertSame(Inquiry::LAYOUT_SUBMITTED, $inquiry->layout_status, 'it stays with the client');
    }

    public function test_a_leader_can_give_a_fourth_round(): void
    {
        [$officer, , $inquiry] = $this->submittedLayout();
        $leader = User::factory()->create(['job_role' => 'leader', 'is_active' => true]);

        foreach ([1, 2, 3] as $round) {
            $this->sendBack($officer, $inquiry)->assertRedirect();
        }

        $this->sendBack($leader, $inquiry, 'Client is worth it.')->assertRedirect();

        $inquiry->refresh();
        $this->assertSame(4, (int) $inquiry->layout_revision_count, 'the extra round is still recorded');
        $this->assertSame(Inquiry::LAYOUT_WITH_ARTIST, $inquiry->layout_status);
    }

    public function test_the_artist_is_told_which_round_they_are_on(): void
    {
        [$officer, $artist, $inquiry] = $this->submittedLayout();

        $this->sendBack($officer, $inquiry, 'Move the logo.')->assertRedirect();

        $this->actingAs($artist)->get(route('inquiries.layouts'))
            ->assertOk()->assertSee('Revision 1')->assertSee('of 3');
    }

    public function test_the_officer_sees_the_send_back_button_close(): void
    {
        [$officer, , $inquiry] = $this->submittedLayout();

        foreach ([1, 2, 3] as $round) {
            $this->sendBack($officer, $inquiry)->assertRedirect();
        }

        // Hand it back once more so the officer is looking at the page that
        // offers the button. refresh() first — see sendBack().
        $inquiry->refresh()->designs()->update([
            'status' => InquiryDesign::STATUS_SUBMITTED,
            'submitted_at' => now(),
        ]);

        $inquiry->update([
            'layout_status' => Inquiry::LAYOUT_SUBMITTED,
            'layout_submitted_at' => now(),
        ]);

        $this->actingAs($officer)->get(route('inquiries.layout', $inquiry))
            ->assertOk()->assertSee('has had its 3 revisions');
    }

    public function test_the_artist_sees_the_file_that_came_back(): void
    {
        Storage::fake('local');
        [$officer, $artist, $inquiry] = $this->submittedLayout();

        $this->actingAs($officer)->post(route('inquiries.layout.revise', $inquiry), [
            'layout_revision_note' => 'Move the logo.',
            'revision_files' => [UploadedFile::fake()->image('markup.png')],
        ])->assertRedirect();

        $this->actingAs($artist)->get(route('inquiries.layouts'))
            ->assertOk()
            ->assertSee('markup.png')
            ->assertSee('sent back with the change');
    }
}
