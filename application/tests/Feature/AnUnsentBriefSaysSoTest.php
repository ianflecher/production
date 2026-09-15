<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Inquiry;
use App\Models\InquiryDesign;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A brief nobody has sent says so, and reaches nobody's desk.
 *
 * An officer prepared a brief, named the artist, attached the file and the
 * notes - and never pressed send. The officer's screen read "Port is drawing
 * it", because that badge was the else-arm and caught anything that was not
 * approved or submitted. Port's queue was empty. Neither of them had any
 * reason to check, and the job sat there.
 *
 * The other half of the same omission pointed the opposite way: a design is
 * created as with_artist the moment it is added, so an artist could ALSO see
 * work an officer was still writing up, and start drawing from instructions
 * that were about to change.
 *
 * Both come from nothing consulting layout_sent_at, which is the only honest
 * record of a handover: it is the press that locks the brief, notifies the
 * artist and stamps the time.
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

    private function brief(User $officer, User $artist, bool $sent, string $status = InquiryDesign::STATUS_WITH_ARTIST): InquiryDesign
    {
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

    /** The bug itself: an unsent brief claimed the artist was drawing it. */
    public function test_an_unsent_brief_does_not_claim_the_artist_is_drawing_it(): void
    {
        $officer = $this->officer();
        $artist = $this->artist();
        $design = $this->brief($officer, $artist, sent: false);

        $this->actingAs($officer)
            ->get(route('inquiries.layout', $design->inquiry))
            ->assertOk()
            ->assertSee('Not sent yet')
            ->assertDontSee($artist->name.' is drawing it');
    }

    /** It still says who it is FOR, so the choice made is not lost. */
    public function test_an_unsent_brief_still_names_who_it_is_for(): void
    {
        $officer = $this->officer();
        $artist = $this->artist();
        $design = $this->brief($officer, $artist, sent: false);

        $this->actingAs($officer)
            ->get(route('inquiries.layout', $design->inquiry))
            ->assertOk()
            ->assertSee('for '.$artist->name);
    }

    /** A design left at the old "brief" status reads the same way. */
    public function test_the_legacy_brief_status_reads_as_unsent(): void
    {
        $officer = $this->officer();
        $artist = $this->artist();
        $design = $this->brief($officer, $artist, sent: false, status: InquiryDesign::STATUS_BRIEF);

        $this->assertTrue($design->fresh()->notSentYet());

        $this->actingAs($officer)
            ->get(route('inquiries.layout', $design->inquiry))
            ->assertOk()
            ->assertSee('Not sent yet');
    }

    /** And once it HAS been sent, it says what it always said. */
    public function test_a_sent_brief_says_the_artist_is_drawing_it(): void
    {
        $officer = $this->officer();
        $artist = $this->artist();
        $design = $this->brief($officer, $artist, sent: true);

        $this->actingAs($officer)
            ->get(route('inquiries.layout', $design->inquiry))
            ->assertOk()
            ->assertSee($artist->name.' is drawing it')
            ->assertDontSee('Not sent yet');
    }

    /**
     * A design added AFTER the brief went out is a different case, and must
     * not be caught by any of this.
     *
     * The brief has already been handed over - layout_sent_at is stamped -
     * and adding another design to it announces itself to the artist on the
     * spot. So it is genuinely with them from the moment it is added, and
     * saying "not sent yet" would be the same lie in reverse.
     */
    public function test_a_design_added_after_the_brief_went_out_is_with_the_artist(): void
    {
        $officer = $this->officer();
        $artist = $this->artist();

        // The brief, already sent.
        $first = $this->brief($officer, $artist, sent: true);

        // And another design on the same brief, added later.
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

        // And the artist has both of them.
        $this->actingAs($artist)
            ->get(route('inquiries.layouts'))
            ->assertOk()
            ->assertSee('Joanne');

        $this->assertSame(2, InquiryDesign::where('inquiry_id', $first->inquiry_id)
            ->where('artist_id', $artist->id)->count());
    }

    /* ---------------- what the artist is given ---------------- */

    /** The other half: work still being written up is on nobody's desk. */
    public function test_an_artist_is_not_given_a_brief_that_was_never_sent(): void
    {
        $officer = $this->officer();
        $artist = $this->artist();
        $this->brief($officer, $artist, sent: false);

        $this->actingAs($artist)
            ->get(route('inquiries.layouts'))
            ->assertOk()
            ->assertDontSee('Joanne');
    }

    public function test_an_artist_is_given_it_once_it_is_sent(): void
    {
        $officer = $this->officer();
        $artist = $this->artist();
        $this->brief($officer, $artist, sent: true);

        $this->actingAs($artist)
            ->get(route('inquiries.layouts'))
            ->assertOk()
            ->assertSee('Joanne');
    }

    /** The leader's whole-shop view follows the same rule. */
    public function test_the_leaders_queue_leaves_unsent_briefs_out_too(): void
    {
        $officer = $this->officer();
        $artist = $this->artist();
        $leader = User::factory()->create(['job_role' => User::ROLE_LEADER, 'is_active' => true]);

        $this->brief($officer, $artist, sent: false);

        $this->actingAs($leader)
            ->get(route('inquiries.layouts'))
            ->assertOk()
            ->assertDontSee('Joanne');
    }
}
