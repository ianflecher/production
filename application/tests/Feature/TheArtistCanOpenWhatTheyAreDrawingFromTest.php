<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Inquiry;
use App\Models\InquiryDesign;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The artist can open the references on the brief they are drawing.
 *
 * This guard has now been widened three times for the same symptom — every
 * thumbnail on the brief coming back 403 and rendering as a broken picture —
 * and the third time was because it was asking the wrong question rather than
 * asking too narrow a one.
 *
 * layout_artist_id is the old shape, from when a brief had one artist. A
 * brief now carries designs and each design carries its own artist, so a
 * brief drawn entirely through designs leaves that column at NULL. The artist
 * matched nothing, was sent through the account officer's gates, and was
 * refused the very images they were drawing from.
 *
 * So the question is whether this person is drawing anything on this brief,
 * asked of the designs as well as the column. These tests hold it there.
 */
class TheArtistCanOpenWhatTheyAreDrawingFromTest extends TestCase
{
    use RefreshDatabase;

    private function officer(): User
    {
        return User::factory()->create(['job_role' => User::ROLE_SALES, 'is_active' => true]);
    }

    private function artist(string $name = 'Maru'): User
    {
        return User::factory()->create(['job_role' => User::JOB_ARTIST, 'name' => $name, 'is_active' => true]);
    }

    /** A brief carrying one reference picture, the way an officer leaves it. */
    private function brief(User $officer, array $extra = []): Inquiry
    {
        Storage::fake('local');
        Storage::disk('local')->put('inquiry-layouts/peg.jpg', 'not really a jpeg');

        return Inquiry::create(array_merge([
            'client_id' => Client::create(['name' => 'Migi', 'last_name' => 'Morte'])->id,
            'created_by' => $officer->id,
            'team' => $officer->team,
            'status' => Inquiry::STATUS_OPEN,
            'what_they_want' => 'Jersey',
            'layout_files' => [[
                'path' => 'inquiry-layouts/peg.jpg',
                'original_name' => 'peg.jpg',
                'mime' => 'image/jpeg',
                'size' => 17,
                'kind' => 'peg',
            ]],
        ], $extra));
    }

    private function open(User $as, Inquiry $inquiry)
    {
        return $this->actingAs($as)->get(route('inquiries.layout.file', [$inquiry, 'index' => 0]));
    }

    /**
     * The case that was broken: the artist is on the DESIGN, and the brief's
     * own artist column was never filled in because nothing fills it any more.
     */
    public function test_the_artist_of_a_design_can_open_the_briefs_references(): void
    {
        $officer = $this->officer();
        $inquiry = $this->brief($officer);
        $artist = $this->artist();

        $inquiry->designs()->create([
            'label' => 'JERSEY', 'position' => 0,
            'artist_id' => $artist->id,
            'status' => InquiryDesign::STATUS_WITH_ARTIST,
            'sent_at' => now(),
        ]);

        $this->assertNull($inquiry->layout_artist_id, 'the old column is what nothing fills any more');

        $this->open($artist, $inquiry)->assertOk();
    }

    /** The older shape still works — those rows are still in the database. */
    public function test_the_briefs_own_artist_can_still_open_them(): void
    {
        $officer = $this->officer();
        $artist = $this->artist();
        $inquiry = $this->brief($officer, ['layout_artist_id' => $artist->id]);

        $this->open($artist, $inquiry)->assertOk();
    }

    /** And the people who were always allowed. */
    public function test_the_officer_and_the_artist_leader_can_open_them(): void
    {
        $officer = $this->officer();
        $inquiry = $this->brief($officer);

        $this->open($officer, $inquiry)->assertOk();

        $lead = User::factory()->create(['job_role' => User::JOB_ARTIST_LEAD, 'is_active' => true]);
        $this->open($lead, $inquiry)->assertOk();

        $leader = User::factory()->create(['job_role' => User::ROLE_LEADER, 'is_active' => true]);
        $this->open($leader, $inquiry)->assertOk();
    }

    /**
     * Widening it is not opening it. An artist with nothing on this brief has
     * no more reason to read the client's references than the floor does.
     */
    public function test_an_artist_with_nothing_on_the_brief_is_still_refused(): void
    {
        $officer = $this->officer();
        $inquiry = $this->brief($officer);

        $inquiry->designs()->create([
            'label' => 'JERSEY', 'position' => 0,
            'artist_id' => $this->artist('Mick')->id,
            'status' => InquiryDesign::STATUS_WITH_ARTIST,
        ]);

        $this->open($this->artist('Stranger'), $inquiry)->assertForbidden();
        $this->open(User::factory()->create(['job_role' => User::JOB_PRODUCTION, 'is_active' => true]), $inquiry)
            ->assertForbidden();
    }

    /** A file that is not there is missing, not forbidden. */
    public function test_a_reference_that_is_not_on_disk_is_a_404(): void
    {
        $officer = $this->officer();
        $inquiry = $this->brief($officer);

        Storage::disk('local')->delete('inquiry-layouts/peg.jpg');

        $this->open($officer, $inquiry)->assertNotFound();
    }
}
