<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Inquiry;
use App\Models\ProductionOrder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The artist leader's two lists, sorted the way he works them.
 *
 * He reads both of these pages to decide who draws what, and both of them ran
 * every client together in one pile. Two things sort that pile for him: which
 * team's book a job came out of, META or VIP, and whether a drawing is new or
 * one that has come back with changes on it. A revision has a client already
 * waiting and a note saying what is wrong, which is not the same work as a
 * blank brief — and until now the page would not tell the two apart.
 *
 * The office's own copy of the follow-up page is left as it was. It is worth a
 * test of its own here: the filter is his, and adding it must not quietly
 * rearrange the page an account officer works all day.
 */
class TheArtistLeaderSortsHisQueueTest extends TestCase
{
    use RefreshDatabase;

    private function lead(): User
    {
        return User::factory()->create(['job_role' => User::JOB_ARTIST_LEAD, 'is_active' => true]);
    }

    private function officer(string $team): User
    {
        return User::factory()->create([
            'job_role' => User::ROLE_SALES, 'team' => $team, 'is_active' => true,
        ]);
    }

    /** A client waiting, on one team, either fresh or already sent back. */
    private function waiting(string $team, string $name, int $revisions = 0): Inquiry
    {
        $client = Client::create(['name' => $name, 'last_name' => 'Waiting']);

        return Inquiry::create([
            'client_id' => $client->id,
            'created_by' => $this->officer($team)->id,
            'team' => $team,
            'status' => Inquiry::STATUS_OPEN,
            'what_they_want' => 'Shirts',
            'layout_revision_count' => $revisions,
        ]);
    }

    public function test_the_filter_tells_a_new_brief_from_one_that_came_back(): void
    {
        $this->waiting('vip', 'Fresh');
        $this->waiting('vip', 'Returned', revisions: 1);

        $lead = $this->lead();

        $this->actingAs($lead)->get(route('inquiries.index', ['kind' => Inquiry::KIND_REVISION]))
            ->assertOk()->assertSee('Returned')->assertDontSee('Fresh');

        $this->actingAs($lead)->get(route('inquiries.index', ['kind' => Inquiry::KIND_NEW]))
            ->assertOk()->assertSee('Fresh')->assertDontSee('Returned');

        $this->actingAs($lead)->get(route('inquiries.index'))
            ->assertOk()->assertSee('Fresh')->assertSee('Returned');
    }

    /**
     * A single design sent back on its own only moves that design's own count,
     * not the inquiry's. Reading the inquiry's number alone called half the
     * revisions on the board new work.
     */
    public function test_one_design_sent_back_makes_the_whole_brief_a_revision(): void
    {
        $inquiry = $this->waiting('vip', 'Partly');
        $inquiry->designs()->create([
            'label' => 'Front', 'position' => 1, 'revision_count' => 1,
        ]);

        $this->actingAs($this->lead())
            ->get(route('inquiries.index', ['kind' => Inquiry::KIND_REVISION]))
            ->assertOk()->assertSee('Partly');
    }

    public function test_the_two_teams_get_their_own_heading(): void
    {
        $this->waiting('meta', 'Metaclient');
        $this->waiting('vip', 'Vipclient');

        $html = $this->actingAs($this->lead())->get(route('inquiries.index'))
            ->assertOk()->assertSee('Metaclient')->assertSee('Vipclient')
            ->getContent();

        $this->assertMatchesRegularExpression('/<h2>\s*META\s*</', $html, 'the META heading is missing');
        $this->assertMatchesRegularExpression('/<h2>\s*VIP\s*</', $html, 'the VIP heading is missing');
    }

    public function test_he_can_ask_for_one_teams_follow_ups(): void
    {
        $this->waiting('meta', 'Metaclient');
        $this->waiting('vip', 'Vipclient');

        $lead = $this->lead();

        $this->actingAs($lead)->get(route('inquiries.index', ['team' => 'vip']))
            ->assertOk()->assertSee('Vipclient')->assertDontSee('Metaclient');

        $this->actingAs($lead)->get(route('inquiries.index', ['team' => 'meta']))
            ->assertOk()->assertSee('Metaclient')->assertDontSee('Vipclient');
    }

    /**
     * The two filters are two separate questions. Answering one must not throw
     * away the other, or narrowing twice is impossible.
     */
    public function test_the_two_filters_hold_at_the_same_time(): void
    {
        $this->waiting('vip', 'Vipreturned', revisions: 1);
        $this->waiting('vip', 'Vipfresh');
        $this->waiting('meta', 'Metareturned', revisions: 1);

        $this->actingAs($this->lead())
            ->get(route('inquiries.index', ['team' => 'vip', 'kind' => Inquiry::KIND_REVISION]))
            ->assertOk()
            ->assertSee('Vipreturned')
            ->assertDontSee('Vipfresh')
            ->assertDontSee('Metareturned');
    }

    /** An empty page has to say which switch emptied it. */
    public function test_an_empty_team_says_which_filter_is_on(): void
    {
        $this->waiting('vip', 'Vipclient');

        $this->actingAs($this->lead())->get(route('inquiries.index', ['team' => 'meta']))
            ->assertOk()
            ->assertSee('Nobody on META is waiting')
            ->assertSee('Show everything');
    }

    /** A stale or hand-typed filter shows the list, not an empty page. */
    public function test_a_filter_that_means_nothing_shows_everybody(): void
    {
        $this->waiting('vip', 'Stillhere');

        $this->actingAs($this->lead())->get(route('inquiries.index', ['kind' => 'nonsense']))
            ->assertOk()->assertSee('Stillhere');
    }

    /** The search and the filter are two questions; answering one keeps the other. */
    public function test_searching_inside_a_filter_keeps_the_filter(): void
    {
        $this->waiting('vip', 'Returned', revisions: 1);
        $this->waiting('vip', 'Fresh');

        $this->actingAs($this->lead())
            ->get(route('inquiries.index', ['kind' => Inquiry::KIND_REVISION, 'q' => 'Waiting']))
            ->assertOk()->assertSee('Returned')->assertDontSee('Fresh');
    }

    public function test_the_office_page_is_left_as_it_was(): void
    {
        $officer = $this->officer('vip');
        Inquiry::create([
            'client_id' => Client::create(['name' => 'Theirs', 'last_name' => 'Own'])->id,
            'created_by' => $officer->id, 'team' => 'vip',
            'status' => Inquiry::STATUS_OPEN, 'what_they_want' => 'Shirts',
        ]);

        $this->actingAs($officer)->get(route('inquiries.index'))
            ->assertOk()
            ->assertSee('Theirs')
            // The filter is the artist leader's. An account officer chases
            // clients; the drawing is not hers to sort.
            ->assertDontSee('New designs')
            ->assertDontSee('All teams');
    }

    /* ---------------- the tech packs he has to check ---------------- */

    /** An order on a team, with its tech pack waiting for him. */
    private function packWaiting(string $team, string $number, string $client): ProductionOrder
    {
        $officer = $this->officer($team);

        $order = ProductionOrder::create([
            'order_number' => $number,
            'client_id' => Client::create(['name' => $client, 'last_name' => 'Co'])->id,
            'customer_name' => $client.' Co',
            'product_type' => 'round_neck',
            'quantity' => 10,
            'due_date' => now()->addWeeks(2),
            'status' => 'active',
            'created_by' => $officer->id,
        ]);

        $order->items()->create(['size' => 'M', 'quantity' => 10]);
        $order->refresh()->buildPipeline([], 'manual');

        $order->tasks()
            ->where('stage', ProductionOrder::STAGE_MOCKUP)
            ->where('department', 'Tech pack')
            ->get()
            ->each(fn ($t) => $t->forceFill([
                'assigned_to' => User::factory()->create(['job_role' => User::JOB_ARTIST])->id,
                'status' => 'for_checking',
                'submitted_at' => now(),
                'approver_role' => 'leader',
                'officer_approved_by' => $officer->id,
                'officer_approved_at' => now(),
            ])->save());

        return $order;
    }

    public function test_the_checking_queue_is_split_into_the_two_teams(): void
    {
        $this->packWaiting('meta', 'IC2026-07001', 'Metabuyer');
        $this->packWaiting('vip', 'IC2026-07002', 'Vipbuyer');

        $html = $this->actingAs($this->lead())->get(route('approvals'))
            ->assertOk()->assertSee('Metabuyer')->assertSee('Vipbuyer')
            ->getContent();

        $this->assertMatchesRegularExpression('/tbl-group[^>]*>\s*<th colspan="6">\s*META\s*</', $html);
        $this->assertMatchesRegularExpression('/tbl-group[^>]*>\s*<th colspan="6">\s*VIP\s*</', $html);
    }

    public function test_he_can_ask_for_one_teams_packs(): void
    {
        $this->packWaiting('meta', 'IC2026-07003', 'Metabuyer');
        $this->packWaiting('vip', 'IC2026-07004', 'Vipbuyer');

        $lead = $this->lead();

        $this->actingAs($lead)->get(route('approvals', ['team' => 'vip']))
            ->assertOk()->assertSee('Vipbuyer')->assertDontSee('Metabuyer');

        $this->actingAs($lead)->get(route('approvals', ['team' => 'meta']))
            ->assertOk()->assertSee('Metabuyer')->assertDontSee('Vipbuyer');
    }

    /**
     * An empty page under a filter has to say which filter emptied it, or the
     * other team's packs sit unchecked behind a switch he has forgotten is on.
     */
    public function test_an_empty_team_says_so_and_offers_the_way_back(): void
    {
        $this->packWaiting('vip', 'IC2026-07005', 'Vipbuyer');

        $this->actingAs($this->lead())->get(route('approvals', ['team' => 'meta']))
            ->assertOk()
            ->assertSee('Nothing from META to check')
            ->assertSee('Show both teams');
    }

    /**
     * A job taken over the counter has no brief to carry a team, so the
     * officer who wrote it answers for it.
     */
    public function test_a_walk_in_takes_the_team_of_whoever_wrote_it(): void
    {
        $order = $this->packWaiting('meta', 'IC2026-07006', 'Counter');
        $this->assertNull($order->inquiry, 'this order was meant to have no brief');
        $this->assertSame('meta', $order->salesTeam());
    }

    public function test_the_leaders_own_approvals_page_is_left_as_it_was(): void
    {
        $this->packWaiting('vip', 'IC2026-07007', 'Vipbuyer');

        $leader = User::factory()->create(['job_role' => User::ROLE_LEADER, 'is_active' => true]);

        $this->actingAs($leader)->get(route('approvals'))
            ->assertOk()
            ->assertSee('Vipbuyer')
            // Her page carries sewing, printing and QC as well, which do not
            // belong to a sales team.
            ->assertDontSee('All teams')
            ->assertDontSee('tbl-group');
    }
}
