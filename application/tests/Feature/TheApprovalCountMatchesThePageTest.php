<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\ProductionOrder;
use App\Models\Task;
use App\Models\User;
use App\Support\ApprovalQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The number on the badge is the number on the page.
 *
 * The sidebar badge, the dashboard card and the Approvals page all answer the
 * same question, and the first two are links to the third. They did not agree.
 * The page asks for approver_role = leader; the dashboard counted everything
 * sitting at "for checking" whoever it was waiting on, so a tech pack still
 * with the account officer was counted as the leader's and opened a page
 * saying "nothing to check". A badge that disagrees with the page it opens is
 * worse than no badge: people stop believing both.
 *
 * The count was also worked out from a list cut to five for display, so it
 * could never say more than five however much was really waiting.
 *
 * And once the count was honest, work the officer DOES owe stopped showing
 * anywhere for a leader who holds the order desk — the Reviews page carried
 * it, and only the sales sidebar had a link to that page.
 */
class TheApprovalCountMatchesThePageTest extends TestCase
{
    use RefreshDatabase;

    private int $made = 0;

    private function leader(): User
    {
        return User::factory()->create(['job_role' => User::ROLE_LEADER, 'is_active' => true]);
    }

    /** An order with its pipeline built, taken by $officer. */
    private function order(User $officer): ProductionOrder
    {
        $order = ProductionOrder::create([
            'order_number' => 'IC2026-AP'.str_pad((string) (++$this->made), 3, '0', STR_PAD_LEFT),
            'client_id' => Client::create(['name' => 'Approval', 'last_name' => 'Client'])->id,
            'customer_name' => 'Approval Client',
            'product_type' => 'round_neck',
            'quantity' => 10,
            'due_date' => now()->addWeeks(2),
            'status' => 'active',
            'created_by' => $officer->id,
        ]);

        $order->items()->create(['size' => 'M', 'quantity' => 10]);
        $order->refresh()->buildPipeline([], 'manual');

        return $order->refresh();
    }

    /** Put this order's tech pack into "for checking", waiting on $role. */
    private function techPackWaitingOn(ProductionOrder $order, string $role, ?User $drawnBy = null): void
    {
        $order->tasks()
            ->where('stage', ProductionOrder::STAGE_MOCKUP)
            ->where('department', 'Tech pack')
            ->get()
            ->each(fn (Task $t) => $t->forceFill([
                'assigned_to' => $drawnBy?->id ?? User::factory()->create(['job_role' => User::JOB_ARTIST])->id,
                'status' => 'for_checking',
                'submitted_at' => now(),
                'approver_role' => $role,
                'officer_approved_by' => $role === 'leader' ? $order->created_by : null,
                'officer_approved_at' => $role === 'leader' ? now() : null,
            ])->save());
    }

    public function test_work_still_with_the_account_officer_is_not_the_leaders(): void
    {
        $officer = User::factory()->create(['job_role' => User::ROLE_SALES, 'is_active' => true]);
        $this->techPackWaitingOn($this->order($officer), 'sales');

        $leader = $this->leader();

        $this->assertSame(0, ApprovalQueue::countFor($leader),
            'a pack waiting on the account officer was counted as the leader\'s');

        // And the page it links to agrees.
        $this->actingAs($leader)->get(route('approvals'))
            ->assertOk()
            ->assertSee('Nothing to check right now');
    }

    public function test_the_badge_and_the_page_agree_once_it_reaches_the_leader(): void
    {
        $officer = User::factory()->create(['job_role' => User::ROLE_SALES, 'is_active' => true]);
        $order = $this->order($officer);
        $this->techPackWaitingOn($order, 'leader');

        $leader = $this->leader();

        $this->assertSame(1, ApprovalQueue::countFor($leader));

        $this->actingAs($leader)->get(route('approvals'))
            ->assertOk()
            ->assertDontSee('Nothing to check right now')
            ->assertSee($order->order_number);

        $this->actingAs($leader)->get(route('dashboard'))
            ->assertOk()
            ->assertSee('1 awaiting approval');
    }

    /** The list under the card shows five; the number must not stop there. */
    public function test_the_count_is_not_capped_by_the_list_beneath_it(): void
    {
        $officer = User::factory()->create(['job_role' => User::ROLE_SALES, 'is_active' => true]);

        for ($i = 0; $i < 7; $i++) {
            $this->techPackWaitingOn($this->order($officer), 'leader');
        }

        $leader = $this->leader();

        $this->assertSame(7, ApprovalQueue::countFor($leader));

        $this->actingAs($leader)->get(route('dashboard'))
            ->assertOk()
            ->assertSee('7 awaiting approval')
            ->assertDontSee('5 awaiting approval');
    }

    /** Nobody checks their own work, and the badge already knew that. */
    public function test_the_artist_leaders_own_pack_is_not_counted_for_him(): void
    {
        $officer = User::factory()->create(['job_role' => User::ROLE_SALES, 'is_active' => true]);
        $lead = User::factory()->create(['job_role' => User::JOB_ARTIST_LEAD, 'is_active' => true]);

        $this->techPackWaitingOn($this->order($officer), 'leader', drawnBy: $lead);

        $this->assertSame(0, ApprovalQueue::countFor($lead),
            'he was counted the pack he drew himself');

        $this->actingAs($lead)->get(route('approvals'))
            ->assertOk()
            ->assertSee('Nothing to check right now');
    }

    /**
     * A leader who holds the order desk approves samples as the officer. That
     * is a different queue on a different page, and only the sales sidebar
     * linked to it — so the work sat somewhere she could not reach.
     */
    public function test_a_leader_on_the_order_desk_is_shown_the_review_queue(): void
    {
        $deskLeader = User::factory()->create([
            'job_role' => User::ROLE_LEADER, 'is_active' => true, 'can_create_orders' => true,
        ]);

        $this->techPackWaitingOn($this->order($deskLeader), 'sales');

        $this->actingAs($deskLeader)->get(route('dashboard'))
            ->assertOk()
            ->assertSee(route('sample.review'))
            ->assertSee('Reviews');

        $this->actingAs($deskLeader)->get(route('sample.review'))
            ->assertOk();
    }

    /** A leader without the order desk has no such queue and no such link. */
    public function test_a_plain_leader_is_not_given_the_review_link(): void
    {
        $this->actingAs($this->leader())->get(route('dashboard'))
            ->assertOk()
            ->assertDontSee(route('sample.review'));
    }
}
