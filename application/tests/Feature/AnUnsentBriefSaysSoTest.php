<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Inquiry;
use App\Models\InquiryDesign;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A design still in the brief says so, rather than naming an artist.
 *
 * An officer prepared a brief and it sat at STATUS_BRIEF. The artist's queue
 * skips that status, so it was on nobody's desk - but the officer's screen
 * read "Port is drawing it", because that badge was the else-arm of the chain
 * and caught anything that was not approved or submitted. Neither of them had
 * a reason to check, and the job sat there.
 *
 * The model had three status constants and the database had a fourth,
 * "brief", left by the migration that split one enquiry's layout into
 * designs. A state nothing names is a state nothing can report.
 *
 * Deliberately NOT asked here: the enquiry's layout_sent_at. That looked like
 * the better question - it is the press that locks the brief and notifies the
 * artist - but the shop does not work that way. The live database has layouts
 * two revisions deep with files attached whose brief was never formally sent,
 * because a design is visible to its artist the moment it is added. Asking
 * layout_sent_at would call those unsent and take live work off a queue.
 */
class AnUnsentBriefSaysSoTest extends TestCase
{
    use RefreshDatabase;

    private function officer(): User
    {
        return User::factory()->create(['job_role' => User::ROLE_SALES, 'is_active' => true]);
    }

    private function artist(): User
    {
        return User::factory()->create(['job_role' => User::JOB_ARTIST, 'is_active' => true]);
    }

    private function design(
        User $officer,
        User $artist,
        string $status = InquiryDesign::STATUS_WITH_ARTIST,
        bool $sent = true,
    ): InquiryDesign {
        $client = Client::create([
            'name' => 'Joanne', 'last_name' => 'Lu', 'contact_number' => '0917-000-0000',
            'created_by' => $officer->id,
        ]);

        $inquiry = Inquiry::create([
            'client_id' => $client->id,
            'what_they_want' => 'Consignee jackets',
            'created_by' => $officer->id,
            'layout_sent_at' => $sent ? now() : null,
        ]);

        return InquiryDesign::create([
            'inquiry_id' => $inquiry->id,
            'position' => 1,
            'artist_id' => $artist->id,
            'status' => $status,
        ]);
    }

    /* ---------------- what the officer is told ---------------- */

    /** The bug itself. */
    public function test_a_design_still_in_the_brief_does_not_claim_the_artist_is_drawing_it(): void
    {
        $officer = $this->officer();
        $artist = $this->artist();
        $design = $this->design($officer, $artist, InquiryDesign::STATUS_BRIEF);

        $this->assertTrue($design->fresh()->notSentYet());

        $this->actingAs($officer)
            ->get(route('inquiries.layout', $design->inquiry))
            ->assertOk()
            ->assertSee('Not sent yet')
            ->assertDontSee($artist->name.' is drawing it');
    }

    /** It still says who it is FOR, so the choice made is not lost. */
    public function test_it_still_names_who_the_brief_is_for(): void
    {
        $officer = $this->officer();
        $artist = $this->artist();
        $design = $this->design($officer, $artist, InquiryDesign::STATUS_BRIEF);

        $this->actingAs($officer)
            ->get(route('inquiries.layout', $design->inquiry))
            ->assertOk()
            ->assertSee('for '.$artist->name);
    }

    /** Once it is with the artist, it says what it always said. */
    public function test_a_design_with_the_artist_says_the_artist_is_drawing_it(): void
    {
        $officer = $this->officer();
        $artist = $this->artist();
        $design = $this->design($officer, $artist);

        $this->actingAs($officer)
            ->get(route('inquiries.layout', $design->inquiry))
            ->assertOk()
            ->assertSee($artist->name.' is drawing it')
            ->assertDontSee('Not sent yet');
    }

    /**
     * The correction that matters most.
     *
     * A brief the officer never formally sent, whose design IS with the
     * artist and has been worked on, must keep reading as with the artist -
     * that is a real shape on the live database, and calling it "not sent
     * yet" would be the same lie in reverse.
     */
    public function test_work_under_way_on_a_never_formally_sent_brief_still_reads_as_with_the_artist(): void
    {
        $officer = $this->officer();
        $artist = $this->artist();

        $design = $this->design($officer, $artist, InquiryDesign::STATUS_WITH_ARTIST, sent: false);
        $design->update(['revision_count' => 2]);

        $this->assertFalse($design->fresh()->notSentYet());

        $this->actingAs($officer)
            ->get(route('inquiries.layout', $design->inquiry))
            ->assertOk()
            ->assertSee($artist->name.' is drawing it')
            ->assertDontSee('Not sent yet');
    }

    /* ---------------- what the artist is given ---------------- */

    /** A design still in the brief is on nobody's queue. */
    public function test_an_artist_is_not_given_a_design_still_in_the_brief(): void
    {
        $officer = $this->officer();
        $artist = $this->artist();
        $this->design($officer, $artist, InquiryDesign::STATUS_BRIEF);

        $this->actingAs($artist)
            ->get(route('inquiries.layouts'))
            ->assertOk()
            ->assertDontSee('Joanne');
    }

    public function test_an_artist_is_given_it_once_it_is_with_them(): void
    {
        $officer = $this->officer();
        $artist = $this->artist();
        $this->design($officer, $artist);

        $this->actingAs($artist)
            ->get(route('inquiries.layouts'))
            ->assertOk()
            ->assertSee('Joanne');
    }

    /**
     * And the queue does NOT ask about layout_sent_at - work already under
     * way on a brief nobody formally sent must stay on the artist's desk.
     */
    public function test_the_queue_keeps_work_on_a_never_formally_sent_brief(): void
    {
        $officer = $this->officer();
        $artist = $this->artist();
        $this->design($officer, $artist, InquiryDesign::STATUS_WITH_ARTIST, sent: false);

        $this->actingAs($artist)
            ->get(route('inquiries.layouts'))
            ->assertOk()
            ->assertSee('Joanne');
    }

    /** A design added after the brief went out is with the artist too. */
    public function test_a_design_added_later_is_with_the_artist(): void
    {
        $officer = $this->officer();
        $artist = $this->artist();
        $first = $this->design($officer, $artist);

        $later = InquiryDesign::create([
            'inquiry_id' => $first->inquiry_id,
            'position' => 2,
            'artist_id' => $artist->id,
            'status' => InquiryDesign::STATUS_WITH_ARTIST,
        ]);

        $this->assertFalse($later->fresh()->notSentYet());

        $this->actingAs($officer)
            ->get(route('inquiries.layout', $first->inquiry))
            ->assertOk()
            ->assertDontSee('Not sent yet');
    }
}
