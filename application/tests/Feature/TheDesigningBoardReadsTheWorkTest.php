<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Inquiry;
use App\Models\InquiryDesign;
use App\Models\Payment;
use App\Models\ProductionOrder;
use App\Models\User;
use App\Support\DesignLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The designing board, read off the work rather than typed in.
 *
 * The artists' team kept this as a spreadsheet: a row per design under the day
 * it came in, with the client, the agent, the artist, the two dates and where
 * it had got to. Every column was something the system already knew, so the
 * sheet was a second copy of the truth kept by hand — and a second copy is
 * wrong from the first time somebody forgets to update it.
 *
 * Nothing on this board is entered. These tests are about the two columns that
 * are worked out rather than read: whether a brief is a new design or a mock up
 * for a job already written, and the status, which starts life as a drawing and
 * finishes as a delivery.
 */
class TheDesigningBoardReadsTheWorkTest extends TestCase
{
    use RefreshDatabase;

    private int $made = 0;

    private function officer(string $team, string $name): User
    {
        return User::factory()->create([
            'job_role' => User::ROLE_SALES, 'team' => $team, 'name' => $name, 'is_active' => true,
        ]);
    }

    private function artist(string $name): User
    {
        return User::factory()->create(['job_role' => User::JOB_ARTIST, 'name' => $name, 'is_active' => true]);
    }

    /** A brief with one design on it, drawn by $artist. */
    private function design(User $officer, User $artist, string $client, array $extra = []): InquiryDesign
    {
        $inquiry = Inquiry::create([
            'client_id' => Client::create(['name' => $client, 'last_name' => 'Client'])->id,
            'created_by' => $officer->id,
            'team' => $officer->team,
            'status' => Inquiry::STATUS_OPEN,
            'what_they_want' => 'Shirts',
        ]);

        return $inquiry->designs()->create(array_merge([
            'position' => 1,
            'artist_id' => $artist->id,
            'status' => InquiryDesign::STATUS_WITH_ARTIST,
            'sent_at' => now(),
        ], $extra));
    }

    /** The job written for a design, once somebody writes it. */
    private function orderFor(InquiryDesign $design, array $extra = []): ProductionOrder
    {
        $inquiry = $design->inquiry;

        $order = ProductionOrder::create(array_merge([
            'order_number' => 'IC2026-DL'.str_pad((string) (++$this->made), 3, '0', STR_PAD_LEFT),
            'client_id' => $inquiry->client_id,
            'customer_name' => 'Board Client',
            'product_type' => 'round_neck',
            'quantity' => 10,
            'unit_price' => 500,
            'due_date' => now()->addWeeks(2),
            'status' => 'active',
            'created_by' => $inquiry->created_by,
            'inquiry_id' => $inquiry->id,
            'inquiry_design_id' => $design->id,
        ], $extra));

        $order->items()->create(['size' => 'M', 'quantity' => 10]);
        $order->refresh()->buildPipeline([], 'manual');
        $inquiry->update(['production_order_id' => $order->id]);

        return $order->refresh();
    }

    private function rowFor(InquiryDesign $design): array
    {
        $row = DesignLog::rows()->first(fn ($r) => $r['design']->id === $design->id);

        $this->assertNotNull($row, 'the design never reached the board');

        return $row;
    }

    public function test_the_board_names_the_agent_the_way_the_sheet_does(): void
    {
        $design = $this->design($this->officer('vip', 'Pau'), $this->artist('Mick'), 'Rob');

        $row = $this->rowFor($design);

        $this->assertSame('VIP / Pau', $row['agent']);
        $this->assertSame('Mick', $row['artist']);
        $this->assertSame('Rob Client', $row['client']);
    }

    /** The two dates the sheet keeps: when the artist got it, when they finished. */
    public function test_the_two_dates_are_the_artists_own(): void
    {
        $design = $this->design($this->officer('meta', 'Kyson'), $this->artist('JC'), 'Belle', [
            'sent_at' => now()->subDays(2),
            'submitted_at' => now()->subDay(),
            'status' => InquiryDesign::STATUS_SUBMITTED,
        ]);

        $row = $this->rowFor($design);

        $this->assertSame(now()->subDays(2)->toDateString(), $row['received']->toDateString());
        $this->assertSame(now()->subDay()->toDateString(), $row['finished']->toDateString());
    }

    /* ---------------- new design, or a mock up ---------------- */

    public function test_a_brief_with_no_job_behind_it_is_a_new_design(): void
    {
        $design = $this->design($this->officer('vip', 'Pau'), $this->artist('Maru'), 'Fresh');

        $this->assertSame('New Design', $this->rowFor($design)['description']);
    }

    /** Its own job does not make it a mock up — that is the job it became. */
    public function test_the_design_that_became_the_job_is_still_a_new_design(): void
    {
        $design = $this->design($this->officer('vip', 'Pau'), $this->artist('Maru'), 'Became');
        $this->orderFor($design);

        $this->assertSame('New Design', $this->rowFor($design->refresh())['description']);
    }

    public function test_a_second_design_asked_for_against_a_written_job_is_a_mock_up(): void
    {
        $officer = $this->officer('vip', 'Pau');
        $first = $this->design($officer, $this->artist('Maru'), 'Repeat');
        $this->orderFor($first);

        // Asked for afterwards, on a brief that already carries a job.
        $second = $first->inquiry->designs()->create([
            'position' => 2,
            'artist_id' => $this->artist('Port')->id,
            'status' => InquiryDesign::STATUS_WITH_ARTIST,
            'sent_at' => now(),
        ]);

        $this->assertSame('For Mock Up', $this->rowFor($second)['description']);
        $this->assertSame('New Design', $this->rowFor($first->refresh())['description']);
    }

    /* ---------------- where it has got to ---------------- */

    public function test_a_drawing_handed_back_is_waiting_for_approval(): void
    {
        $design = $this->design($this->officer('vip', 'Pau'), $this->artist('Mick'), 'Handed', [
            'status' => InquiryDesign::STATUS_SUBMITTED,
            'submitted_at' => now(),
        ]);

        $row = $this->rowFor($design);

        $this->assertSame('Waiting For Approval', $row['status']);
        $this->assertSame('Waiting For Approval', $row['notes']);
    }

    /**
     * The gap the sheet calls "waiting for orderlist": the client said yes and
     * nobody has written the job yet. It is the place work quietly stops.
     */
    public function test_an_approved_drawing_with_no_job_is_waiting_for_the_orderlist(): void
    {
        $design = $this->design($this->officer('meta', 'Ysabhel'), $this->artist('Cristal'), 'Approved', [
            'status' => InquiryDesign::STATUS_APPROVED,
            'submitted_at' => now()->subDay(),
            'approved_at' => now(),
        ]);

        $row = $this->rowFor($design);

        $this->assertSame('Approved', $row['status']);
        $this->assertSame('Waiting For Orderlist', $row['notes']);
    }

    /** Written up, but the money has not landed. The other place it stops. */
    public function test_a_job_with_no_downpayment_is_waiting_dp(): void
    {
        $design = $this->design($this->officer('vip', 'Patricia'), $this->artist('Mick'), 'Unpaid', [
            'status' => InquiryDesign::STATUS_APPROVED,
        ]);
        $this->orderFor($design);

        $row = $this->rowFor($design->refresh());

        $this->assertSame('Sample', $row['status']);
        $this->assertSame('Waiting DP', $row['notes']);
    }

    public function test_a_paid_job_is_work_in_progress(): void
    {
        $design = $this->design($this->officer('vip', 'Patricia'), $this->artist('Mick'), 'Paid', [
            'status' => InquiryDesign::STATUS_APPROVED,
        ]);
        $order = $this->orderFor($design);

        Payment::create([
            'production_order_id' => $order->id,
            'amount' => 2500,
            'kind' => 'downpayment',
            'status' => 'confirmed',
            'confirmed_at' => now(),
        ]);

        $this->assertSame('Work in progress', $this->rowFor($design->refresh())['notes']);
    }

    public function test_a_batch_that_has_started_reads_massprod(): void
    {
        $design = $this->design($this->officer('vip', 'Pau'), $this->artist('Mick'), 'Batch', [
            'status' => InquiryDesign::STATUS_APPROVED,
        ]);
        $order = $this->orderFor($design);

        // Something in the mass production half is no longer locked.
        $order->tasks()->where('stage', '>=', 10)->first()?->update(['status' => 'ready']);

        $this->assertSame('Massprod', $this->rowFor($design->refresh())['status']);
    }

    public function test_a_finished_job_reads_delivered(): void
    {
        $design = $this->design($this->officer('vip', 'Pau'), $this->artist('Port'), 'Done', [
            'status' => InquiryDesign::STATUS_APPROVED,
        ]);
        $order = $this->orderFor($design);
        $order->update(['status' => 'complete', 'completed_at' => now()]);

        $row = $this->rowFor($design->refresh());

        $this->assertSame('Delivered', $row['status']);
        $this->assertSame('Delivered', $row['notes']);
    }

    /* ---------------- the page ---------------- */

    /**
     * The whole board is every team's work at once, which is an oversight view
     * rather than anybody's own queue — so the page is the leader's.
     */
    public function test_the_board_page_belongs_to_the_leader(): void
    {
        $officer = $this->officer('vip', 'Pau');
        $artist = $this->artist('Maru');
        $this->design($officer, $artist, 'Shared');

        foreach ([User::ROLE_LEADER, User::ROLE_SUPER_ADMIN] as $role) {
            $this->actingAs(User::factory()->create(['job_role' => $role, 'is_active' => true]))
                ->get(route('design.log'))
                ->assertOk()
                ->assertSee('Shared Client');
        }

        foreach ([$officer, $artist, User::factory()->create(['job_role' => User::ROLE_FINANCE, 'is_active' => true])] as $person) {
            $this->actingAs($person)->get(route('design.log'))->assertForbidden();
        }
    }

    /**
     * The summary of it stays everybody's. That is the half the shop floor
     * used to read over somebody's shoulder, and it costs them no page.
     */
    public function test_the_dashboard_summary_is_still_everybodys(): void
    {
        $officer = $this->officer('vip', 'Pau');
        $artist = $this->artist('Maru');
        $this->design($officer, $artist, 'Shared');

        foreach ([$officer, $artist] as $person) {
            $this->actingAs($person)->get(route('dashboard'))
                ->assertOk()
                ->assertSee('Designing board')
                ->assertSee('Shared Client')
                // A button that answers Forbidden is worse than no button.
                ->assertDontSee('Open the board');
        }

        $this->actingAs(User::factory()->create(['job_role' => User::ROLE_LEADER, 'is_active' => true]))
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Open the board');
    }

    /**
     * The cost of the board must not follow the number of rows on it.
     *
     * This is the mistake the page is shaped to make — a status worked out per
     * design, each one asking after its own order — and a query budget cannot
     * catch it here, because the seeded data the budget test runs against has
     * no designs at all to multiply.
     */
    public function test_the_board_costs_the_same_however_many_designs_are_on_it(): void
    {
        $officer = $this->officer('vip', 'Pau');
        $artist = $this->artist('Mick');

        $this->design($officer, $artist, 'Only');
        $one = $this->countQueries(fn () => DesignLog::rows());

        for ($i = 0; $i < 12; $i++) {
            $design = $this->design($officer, $artist, 'Client'.$i);

            // Half of them carry a job, so the order side is exercised too.
            if ($i % 2 === 0) {
                $this->orderFor($design);
            }
        }

        $many = $this->countQueries(fn () => DesignLog::rows());

        $this->assertGreaterThan(10, DesignLog::rows()->count(), 'the board did not actually fill up');
        $this->assertSame($one, $many,
            "the board asked {$many} queries for thirteen designs and {$one} for one — something is being asked per row");
    }

    /* ---------------- moving a design to another artist ---------------- */

    public function test_the_leader_can_hand_a_design_to_another_artist_from_the_board(): void
    {
        $officer = $this->officer('vip', 'Pau');
        $mick = $this->artist('Mick');
        $maru = $this->artist('Maru');
        $design = $this->design($officer, $mick, 'Moved', ['sent_at' => now()]);
        $design->inquiry->update(['layout_sent_at' => now()]);

        $leader = User::factory()->create(['job_role' => User::ROLE_LEADER, 'is_active' => true]);

        // The board offers the bench in the cell that names the artist.
        $this->actingAs($leader)->get(route('design.log'))
            ->assertOk()
            ->assertSee('dl-artist-form')
            ->assertSee(route('inquiries.designs.artist', [$design->inquiry_id, $design->id]));

        $this->actingAs($leader)
            ->post(route('inquiries.designs.artist', [$design->inquiry_id, $design->id]), [
                'artist_id' => $maru->id,
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame($maru->id, $design->fresh()->artist_id);
        $this->assertSame('Maru', $this->rowFor($design->fresh())['artist']);
    }

    /** The artist whose desk it lands on is told, which is the point. */
    public function test_the_new_artist_is_told_the_design_moved_to_them(): void
    {
        $officer = $this->officer('vip', 'Pau');
        $design = $this->design($officer, $this->artist('Mick'), 'Told', ['sent_at' => now()]);
        $design->inquiry->update(['layout_sent_at' => now()]);
        $maru = $this->artist('Maru');

        $this->actingAs(User::factory()->create(['job_role' => User::ROLE_LEADER, 'is_active' => true]))
            ->post(route('inquiries.designs.artist', [$design->inquiry_id, $design->id]), [
                'artist_id' => $maru->id,
            ])->assertRedirect();

        $this->assertDatabaseHas('app_notifications', ['user_id' => $maru->id]);
    }

    /** Only somebody who draws. The board's list is the bench for that reason. */
    public function test_a_design_cannot_be_handed_to_somebody_who_does_not_draw(): void
    {
        $officer = $this->officer('vip', 'Pau');
        $mick = $this->artist('Mick');
        $design = $this->design($officer, $mick, 'Refused');

        $this->actingAs(User::factory()->create(['job_role' => User::ROLE_LEADER, 'is_active' => true]))
            ->post(route('inquiries.designs.artist', [$design->inquiry_id, $design->id]), [
                'artist_id' => $officer->id,
            ])
            ->assertStatus(422);

        $this->assertSame($mick->id, $design->fresh()->artist_id);
    }

    private function countQueries(callable $work): int
    {
        \Illuminate\Support\Facades\DB::flushQueryLog();
        \Illuminate\Support\Facades\DB::enableQueryLog();

        $work();

        $count = count(\Illuminate\Support\Facades\DB::getQueryLog());
        \Illuminate\Support\Facades\DB::disableQueryLog();

        return $count;
    }

    /* ---------------- how long it has been sitting ---------------- */

    /**
     * The question the sheet could never answer.
     *
     * Two designs both read "Waiting For Approval". One was handed back
     * yesterday and one has been sitting for a fortnight, and nothing on the
     * board told them apart - which is exactly the row somebody needed to see.
     */
    public function test_the_clock_starts_when_the_design_last_moved(): void
    {
        $officer = $this->officer('vip', 'Pau');

        $fresh = $this->design($officer, $this->artist('Mick'), 'Yesterday', [
            'status' => InquiryDesign::STATUS_SUBMITTED,
            'submitted_at' => now()->subDay(),
        ]);
        $old = $this->design($officer, $this->artist('Mick'), 'Fortnight', [
            'status' => InquiryDesign::STATUS_SUBMITTED,
            'submitted_at' => now()->subDays(14),
        ]);

        $this->assertSame(1, $this->rowFor($fresh)['waiting']);
        $this->assertSame(14, $this->rowFor($old)['waiting']);
    }

    /** Each state has its own moment of last movement, not one shared date. */
    public function test_each_place_it_stops_is_timed_from_its_own_moment(): void
    {
        $officer = $this->officer('meta', 'Kyson');

        // Approved a week ago and still nobody has written the job.
        $approved = $this->design($officer, $this->artist('JC'), 'Orderlist', [
            'status' => InquiryDesign::STATUS_APPROVED,
            'submitted_at' => now()->subDays(20),
            'approved_at' => now()->subDays(7),
        ]);
        $this->assertSame(7, $this->rowFor($approved)['waiting'],
            'timed from when it was handed back rather than from when it was approved');

        // On the artist's desk for five days.
        $drawing = $this->design($officer, $this->artist('JC'), 'Drawing', [
            'sent_at' => now()->subDays(5),
        ]);
        $this->assertSame(5, $this->rowFor($drawing)['waiting']);
    }

    /** A job the money has not landed on is timed from when it was written. */
    public function test_a_job_waiting_on_the_downpayment_is_timed_from_the_job(): void
    {
        $design = $this->design($this->officer('vip', 'Patricia'), $this->artist('Mick'), 'Unpaid', [
            'status' => InquiryDesign::STATUS_APPROVED,
        ]);
        $order = $this->orderFor($design);
        $order->forceFill(['created_at' => now()->subDays(6)])->save();

        $row = $this->rowFor($design->refresh());

        $this->assertSame('Waiting DP', $row['notes']);
        $this->assertSame(6, $row['waiting']);
    }

    /**
     * A job that is moving is not waiting. It has a pipeline of its own with
     * its own dates to be late against; a second clock here would call every
     * job on the floor overdue for the crime of being underway.
     */
    public function test_a_job_that_is_moving_has_no_clock_on_this_board(): void
    {
        $design = $this->design($this->officer('vip', 'Pau'), $this->artist('Mick'), 'Moving', [
            'status' => InquiryDesign::STATUS_APPROVED,
        ]);
        $order = $this->orderFor($design);

        Payment::create([
            'production_order_id' => $order->id,
            'amount' => 2500, 'kind' => 'downpayment',
            'status' => 'confirmed', 'confirmed_at' => now(),
        ]);

        $row = $this->rowFor($design->refresh());

        $this->assertSame('Work in progress', $row['notes']);
        $this->assertNull($row['waiting']);
    }

    /** And the board says so, loudly, past the point where somebody should chase. */
    public function test_a_design_sitting_too_long_is_marked_on_the_page(): void
    {
        $officer = $this->officer('vip', 'Pau');
        $artist = $this->artist('Mick');
        $leader = User::factory()->create(['job_role' => User::ROLE_LEADER, 'is_active' => true]);

        $this->design($officer, $artist, 'Chaseme', [
            'status' => InquiryDesign::STATUS_SUBMITTED,
            'submitted_at' => now()->subDays(DesignLog::SITTING_TOO_LONG + 2),
        ]);

        $this->actingAs($leader)->get(route('design.log'))
            ->assertOk()
            ->assertSee('dl-wait is-long', false);

        // A day-old one is ordinary work in hand and is not shouted about.
        InquiryDesign::query()->update(['submitted_at' => now()->subDay()]);

        $this->actingAs($leader)->get(route('design.log'))
            ->assertOk()
            ->assertDontSee('dl-wait is-long', false);
    }

    /**
     * A brief nobody ever sent is timed from the brief, not from its row.
     *
     * Found on the shop's own board: two enquiries whose questionnaire was
     * answered and which were never handed to an artist, both reading five
     * days old. One had been sitting since August. Their design rows were
     * written later than the enquiries they belong to - backfilled when a
     * brief stopped being one design and became several - so the row was
     * younger than the work, and the column that exists to say how long
     * something has been ignored was under-reporting exactly those.
     */
    public function test_a_brief_never_sent_is_timed_from_the_enquiry(): void
    {
        $officer = $this->officer('vip', 'Patricia');

        $inquiry = Inquiry::create([
            'client_id' => Client::create(['name' => 'Never', 'last_name' => 'Sent'])->id,
            'created_by' => $officer->id,
            'team' => $officer->team,
            'status' => Inquiry::STATUS_OPEN,
            'what_they_want' => 'Shirts',
        ]);
        $inquiry->forceFill(['created_at' => now()->subDays(17)])->save();

        // The row itself was written later than the enquiry it sits under.
        $design = $inquiry->designs()->create(['position' => 0, 'status' => 'brief']);
        $design->forceFill(['created_at' => now()->subDays(5)])->save();

        $row = $this->rowFor($design->fresh());

        $this->assertNull($row['artist'], 'nobody was ever put on it');
        $this->assertSame(17, $row['waiting'],
            'the board timed the row rather than the wait');
    }

    /**
     * A second design added to an old brief is not seventeen days late. It
     * was asked for today and nobody has failed at anything yet.
     */
    public function test_a_later_design_on_an_old_brief_starts_its_own_clock(): void
    {
        $officer = $this->officer('vip', 'Patricia');

        $inquiry = Inquiry::create([
            'client_id' => Client::create(['name' => 'Old', 'last_name' => 'Brief'])->id,
            'created_by' => $officer->id,
            'team' => $officer->team,
            'status' => Inquiry::STATUS_OPEN,
            'what_they_want' => 'Shirts',
        ]);
        $inquiry->forceFill(['created_at' => now()->subDays(17)])->save();

        $inquiry->designs()->create(['position' => 0, 'status' => 'brief']);
        $second = $inquiry->designs()->create(['position' => 1, 'status' => 'brief']);

        $this->assertSame(0, $this->rowFor($second->fresh())['waiting'],
            'a design asked for today was called seventeen days late');
    }

    /* ---------------- narrowing the board ---------------- */

    /** The count at the top is the way into the rows behind it. */
    public function test_pressing_a_count_shows_only_those_rows(): void
    {
        $officer = $this->officer('vip', 'Pau');
        $artist = $this->artist('Mick');

        $this->design($officer, $artist, 'Handedback', [
            'status' => InquiryDesign::STATUS_SUBMITTED, 'submitted_at' => now(),
        ]);
        $this->design($officer, $artist, 'Stilldrawing');

        $this->actingAs(User::factory()->create(['job_role' => User::ROLE_LEADER, 'is_active' => true]))
            ->get(route('design.log', ['status' => 'Waiting For Approval']))
            ->assertOk()
            ->assertSee('Handedback')
            ->assertDontSee('Stilldrawing');
    }

    /**
     * And the other counts survive it. Counted after the filter rather than
     * before it, choosing one status would zero every other one and leave no
     * way back to them but the address bar.
     */
    public function test_choosing_one_status_does_not_zero_the_rest(): void
    {
        $officer = $this->officer('vip', 'Pau');
        $artist = $this->artist('Mick');

        $this->design($officer, $artist, 'Handedback', [
            'status' => InquiryDesign::STATUS_SUBMITTED, 'submitted_at' => now(),
        ]);
        $this->design($officer, $artist, 'Stilldrawing');

        $this->actingAs(User::factory()->create(['job_role' => User::ROLE_LEADER, 'is_active' => true]))
            ->get(route('design.log', ['status' => 'Waiting For Approval']))
            ->assertOk()
            // Its row is gone from the table, but its count is still offered.
            ->assertSee('Designing');
    }

    public function test_the_board_can_be_searched_for_a_client(): void
    {
        $officer = $this->officer('vip', 'Pau');
        $artist = $this->artist('Mick');
        $this->design($officer, $artist, 'Stephanie');
        $this->design($officer, $artist, 'Somebodyelse');

        $this->actingAs(User::factory()->create(['job_role' => User::ROLE_LEADER, 'is_active' => true]))
            ->get(route('design.log', ['q' => 'stephanie']))
            ->assertOk()
            ->assertSee('Stephanie Client')
            ->assertDontSee('Somebodyelse');
    }

    /**
     * A note that repeats the status beside it is a column of nothing: twenty
     * rows reading "Waiting For Approval / Waiting For Approval" and one
     * reading "Waiting DP", which is the only one anybody needed to see.
     */
    public function test_a_note_that_repeats_the_status_is_not_printed_twice(): void
    {
        $officer = $this->officer('vip', 'Pau');
        $artist = $this->artist('Mick');
        $leader = User::factory()->create(['job_role' => User::ROLE_LEADER, 'is_active' => true]);

        $this->design($officer, $artist, 'Echoed', [
            'status' => InquiryDesign::STATUS_SUBMITTED, 'submitted_at' => now(),
        ]);

        $page = $this->actingAs($leader)->get(route('design.log'))->assertOk()->getContent();

        // Exactly once: the row's own status cell. Twice would be the status
        // and the note beside it saying the same thing.
        $this->assertSame(1, substr_count($page, '>Waiting For Approval<'),
            'the status and its note said the same thing twice on the same row');

        // The one that earns the column: approved, and nobody has written it up.
        InquiryDesign::query()->update([
            'status' => InquiryDesign::STATUS_APPROVED, 'approved_at' => now(),
        ]);

        $this->actingAs($leader)->get(route('design.log'))
            ->assertOk()
            ->assertSee('Waiting For Orderlist');
    }

    public function test_the_board_can_be_narrowed_to_one_artist_or_one_team(): void
    {
        $vip = $this->officer('vip', 'Pau');
        $meta = $this->officer('meta', 'Kyson');
        $this->design($vip, $this->artist('Mick'), 'Vipclient');
        $this->design($meta, $this->artist('Maru'), 'Metaclient');

        $leader = User::factory()->create(['job_role' => User::ROLE_LEADER, 'is_active' => true]);

        $this->actingAs($leader)->get(route('design.log', ['team' => 'vip']))
            ->assertOk()->assertSee('Vipclient')->assertDontSee('Metaclient');

        $this->actingAs($leader)->get(route('design.log', ['artist' => 'Maru']))
            ->assertOk()->assertSee('Metaclient')->assertDontSee('Vipclient');
    }

    /** A hand-typed range or artist shows the board, not an empty page. */
    public function test_a_filter_that_means_nothing_shows_the_board(): void
    {
        $this->design($this->officer('vip', 'Pau'), $this->artist('Mick'), 'Stillhere');

        $leader = User::factory()->create(['job_role' => User::ROLE_LEADER, 'is_active' => true]);

        $this->actingAs($leader)->get(route('design.log', ['days' => 9999, 'team' => 'nonsense']))
            ->assertOk()->assertSee('Stillhere');
    }
}
