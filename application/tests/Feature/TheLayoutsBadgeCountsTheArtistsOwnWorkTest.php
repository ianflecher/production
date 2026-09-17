<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Inquiry;
use App\Models\InquiryDesign;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The Layouts badge counts the designs on the artist's own desk.
 *
 * It counted INQUIRIES, matched on layout_artist_id — the column from when a
 * brief had one artist and one layout. A brief now carries designs and each
 * design carries its own artist, so an artist handed three designs on a brief
 * that stands in somebody else's name matched nothing: Mick had three to draw
 * and no badge beside Layouts at all. On live the number was wrong for six of
 * the seven artists.
 *
 * The badge counts what the page it points at lists, and only what can be
 * picked up: a design already handed back is waiting on the client.
 */
class TheLayoutsBadgeCountsTheArtistsOwnWorkTest extends TestCase
{
    use RefreshDatabase;

    private function artist(string $name): User
    {
        return User::factory()->create([
            'job_role' => User::JOB_ARTIST, 'name' => $name, 'is_active' => true,
        ]);
    }

    /** A sent brief standing in $owner's name, whoever draws the designs on it. */
    private function brief(User $officer, ?User $owner = null): Inquiry
    {
        $client = Client::create([
            'name' => 'Consignee', 'last_name' => 'Co',
            'contact_number' => '0917-000-0000', 'created_by' => $officer->id,
        ]);

        return Inquiry::create([
            'client_id' => $client->id,
            'what_they_want' => 'Cotton shirts',
            'created_by' => $officer->id,
            'status' => Inquiry::STATUS_OPEN,
            'layout_artist_id' => $owner?->id,
            'layout_status' => Inquiry::LAYOUT_WITH_ARTIST,
            'layout_sent_at' => now()->subDay(),
        ]);
    }

    private function design(Inquiry $inquiry, User $artist, string $status, int $position): InquiryDesign
    {
        return InquiryDesign::create([
            'inquiry_id' => $inquiry->id,
            'position' => $position,
            'label' => 'COTTON SHIRT '.($position + 1),
            'artist_id' => $artist->id,
            'status' => $status,
            'sent_at' => now()->subDay(),
        ]);
    }

    /**
     * The pill as it is actually drawn beside Layouts in the sidebar.
     *
     * Read off the rendered page rather than out of the view data: the count
     * is attached by a view composer on the layout, so the page's own data
     * never carries it, and what matters is the number the artist sees.
     */
    private function badgeFor(User $artist): int
    {
        $html = $this->actingAs($artist)->get(route('tasks.mine'))
            ->assertOk()
            ->getContent();

        return preg_match('#Layouts\s*<span class="count-pill">(\d+)</span>#', $html, $m)
            ? (int) $m[1]
            : 0;
    }

    /* ---------------- the bug itself ---------------- */

    /**
     * Mick. Three designs to draw on a brief that is not in his name, and the
     * badge said nothing at all.
     */
    public function test_designs_on_another_artists_brief_are_counted(): void
    {
        $officer = User::factory()->create(['job_role' => User::ROLE_SALES, 'is_active' => true]);
        $maru = $this->artist('Maru');
        $mick = $this->artist('Mick');

        // The brief stands in Maru's name; three of its designs are Mick's.
        $brief = $this->brief($officer, $maru);
        foreach (range(0, 2) as $i) {
            $this->design($brief, $mick, InquiryDesign::STATUS_WITH_ARTIST, $i);
        }

        $this->assertSame(3, $this->badgeFor($mick),
            'the badge missed work handed to him on somebody else\'s brief');
    }

    /** And it shows on the page it points at, so the two agree. */
    public function test_the_badge_matches_what_the_layouts_page_lists(): void
    {
        $officer = User::factory()->create(['job_role' => User::ROLE_SALES, 'is_active' => true]);
        $maru = $this->artist('Maru');
        $mick = $this->artist('Mick');

        $brief = $this->brief($officer, $maru);
        foreach (range(0, 2) as $i) {
            $this->design($brief, $mick, InquiryDesign::STATUS_WITH_ARTIST, $i);
        }

        $html = $this->actingAs($mick)->get(route('inquiries.layouts'))->assertOk()->getContent();

        // The three rows the page draws for him: one hand-back button each.
        $this->assertSame(3, substr_count($html, 'Hand back COTTON SHIRT'));
        $this->assertStringContainsString('to draw', $html);

        $this->assertSame(3, $this->badgeFor($mick),
            'the badge and the page disagree about how much is on his desk');
    }

    /* ---------------- what it must not count ---------------- */

    /** Handed back is waiting on the client — nothing for the artist to pick up. */
    public function test_a_design_already_handed_back_is_not_counted(): void
    {
        $officer = User::factory()->create(['job_role' => User::ROLE_SALES, 'is_active' => true]);
        $mick = $this->artist('Mick');

        $brief = $this->brief($officer, $mick);
        $this->design($brief, $mick, InquiryDesign::STATUS_WITH_ARTIST, 0);
        $this->design($brief, $mick, InquiryDesign::STATUS_SUBMITTED, 1);
        $this->design($brief, $mick, InquiryDesign::STATUS_SUBMITTED, 2);

        $this->assertSame(1, $this->badgeFor($mick));
    }

    /** Another artist's designs on the same brief are not his. */
    public function test_another_artists_designs_are_not_counted(): void
    {
        $officer = User::factory()->create(['job_role' => User::ROLE_SALES, 'is_active' => true]);
        $mick = $this->artist('Mick');
        $port = $this->artist('Port');

        $brief = $this->brief($officer, $mick);
        $this->design($brief, $mick, InquiryDesign::STATUS_WITH_ARTIST, 0);
        $this->design($brief, $port, InquiryDesign::STATUS_WITH_ARTIST, 1);
        $this->design($brief, $port, InquiryDesign::STATUS_WITH_ARTIST, 2);

        $this->assertSame(1, $this->badgeFor($mick));
        $this->assertSame(2, $this->badgeFor($port));
    }

    /** Nothing on the desk, no badge — the pill is hidden, not a zero. */
    public function test_an_empty_desk_shows_no_pill(): void
    {
        $officer = User::factory()->create(['job_role' => User::ROLE_SALES, 'is_active' => true]);
        $mick = $this->artist('Mick');
        $brief = $this->brief($officer, $mick);
        $this->design($brief, $mick, InquiryDesign::STATUS_SUBMITTED, 0);

        $this->assertSame(0, $this->badgeFor($mick));

        $this->actingAs($mick)->get(route('tasks.mine'))
            ->assertOk()
            ->assertDontSee('<span class="count-pill">0</span>', false);
    }

    /** An approved design is finished with, whoever drew it. */
    public function test_an_approved_design_is_not_counted(): void
    {
        $officer = User::factory()->create(['job_role' => User::ROLE_SALES, 'is_active' => true]);
        $mick = $this->artist('Mick');

        $brief = $this->brief($officer, $mick);
        $this->design($brief, $mick, InquiryDesign::STATUS_WITH_ARTIST, 0);
        $this->design($brief, $mick, InquiryDesign::STATUS_APPROVED, 1);

        $this->assertSame(1, $this->badgeFor($mick));
    }
}
