<?php

namespace Tests\Feature;

use App\Models\Message;
use App\Models\ProductionOrder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The mover follows work through the machines — the printer through to the
 * finished pieces being counted in. What happens before that is the account
 * officer's and the artist's (the enquiry, the layout, the leader's sign-off),
 * and what happens after is the handover to the client. She sees neither, in
 * the pipeline or in the conversation.
 */
class MoverSeesTheFloorOnlyTest extends TestCase
{
    use RefreshDatabase;

    private function mover(): User
    {
        return User::factory()->create(['job_role' => 'Mover', 'name' => 'Mover', 'is_active' => true]);
    }

    /** An order with the real shape of steps, and a floor window that is open. */
    private function order(?\Closure $tweak = null): ProductionOrder
    {
        $sales = User::factory()->create(['job_role' => User::ROLE_SALES, 'is_active' => true]);

        $order = ProductionOrder::create([
            'order_number' => 'IC2026-08800',
            'customer_name' => 'Floor Co',
            'product_type' => 'round_neck',
            'quantity' => 10,
            'due_date' => now()->addWeeks(2),
            'status' => 'active',
            'created_by' => $sales->id,
        ]);

        $steps = [
            [1, 'Layout'], [2, 'Final mockup'], [3, 'Export'],
            [3, 'Printer'], [5, 'Manual cutting'], [7, 'Sewing'],
            [8, 'Quality control'], [15, 'Inventory'], [16, 'Release to client'],
        ];

        foreach ($steps as $i => [$stage, $dept]) {
            $order->tasks()->create([
                'sequence' => $i + 1, 'stage' => $stage, 'department' => $dept,
                'status' => 'todo', 'approver_role' => 'leader',
            ]);
        }

        $order = $order->fresh();
        $tweak && $tweak($order);

        return $order->fresh();
    }

    /** Post a message at a chosen moment (created_at is not fillable). */
    private function said(ProductionOrder $order, User $who, string $body, $at): Message
    {
        $m = Message::create([
            'production_order_id' => $order->id,
            'sender_id' => $who->id,
            'body' => $body,
        ]);
        $m->forceFill(['created_at' => $at])->save();

        return $m;
    }

    /** Open the floor window at a given moment. */
    private function reachedTheFloor(ProductionOrder $order, $at): void
    {
        // A real order cannot reach production before the final mockup has
        // been approved. Keep the fixture on that same path so the Tech Pack
        // link is available to the floor without weakening the approval gate.
        $order->tasks()->where('department', 'Final mockup')
            ->update([
                'status' => 'complete',
                'approved_at' => $at,
            ]);

        $order->tasks()->where('department', 'Printer')
            ->update(['status' => 'in_progress', 'released_at' => $at]);
    }

    // ---- The pipeline panel ------------------------------------------------

    public function test_she_sees_the_floor_steps_only(): void
    {
        $order = $this->order();

        $seen = $order->stepsVisibleTo($this->mover())->pluck('department')->all();

        $this->assertSame(
            ['Printer', 'Manual cutting', 'Sewing', 'Quality control', 'Inventory'],
            $seen
        );
    }

    public function test_the_design_and_the_handover_are_not_hers(): void
    {
        $order = $this->order();

        $seen = $order->stepsVisibleTo($this->mover())->pluck('department');

        foreach (['Layout', 'Final mockup', 'Export', 'Release to client'] as $notHers) {
            $this->assertFalse($seen->contains($notHers), "$notHers should not be on her board");
        }
    }

    public function test_everyone_else_still_sees_the_whole_line(): void
    {
        $order = $this->order();
        $leader = User::factory()->create(['job_role' => User::ROLE_LEADER, 'is_active' => true]);

        $this->assertCount(9, $order->stepsVisibleTo($leader));
        $this->assertCount(9, $order->stepsVisibleTo(null));
    }

    public function test_the_thread_counts_only_her_steps(): void
    {
        $order = $this->order();
        $this->reachedTheFloor($order, now()->subHour());

        // Five steps on her slice, not the nine the order actually has.
        $this->actingAs($this->mover())->get("/messages/{$order->id}")
            ->assertOk()
            ->assertSee('0 of 5 steps done', false)
            ->assertDontSee('of 9 steps done', false);
    }

    // ---- The conversation --------------------------------------------------

    public function test_a_job_is_not_hers_until_it_reaches_the_printer(): void
    {
        $order = $this->order();

        // Still with the artist and the account officer.
        $this->actingAs($this->mover())->get("/messages/{$order->id}")->assertForbidden();
    }

    public function test_once_it_is_hers_she_reads_the_whole_conversation(): void
    {
        $order = $this->order();
        $sales = User::find($order->created_by);

        $this->said($order, $sales, 'client still deciding on the colour', now()->subDays(3));
        $this->said($order, $sales, 'printer says the batch is running', now()->subMinutes(30));

        $this->reachedTheFloor($order, now()->subHour());

        // Including what was said before it got to her — that is the background
        // to whatever she is chasing.
        $this->actingAs($this->mover())->get("/messages/{$order->id}")
            ->assertOk()
            ->assertSee('client still deciding on the colour', false)
            ->assertSee('printer says the batch is running', false);
    }

    public function test_everyone_else_reads_the_whole_conversation(): void
    {
        $order = $this->order();
        $sales = User::find($order->created_by);

        $this->said($order, $sales, 'client still deciding on the colour', now()->subDays(3));

        $this->actingAs($sales)->get("/messages/{$order->id}")
            ->assertOk()
            ->assertSee('client still deciding on the colour', false);
    }

    public function test_a_job_not_yet_at_the_printer_is_not_in_her_inbox(): void
    {
        $order = $this->order();
        $sales = User::find($order->created_by);

        Message::create([
            'production_order_id' => $order->id, 'sender_id' => $sales->id,
            'body' => 'waiting on the downpayment',
        ]);

        // Listing it would only open onto a thread with nothing in it.
        $this->actingAs($this->mover())->get('/messages')
            ->assertOk()
            ->assertDontSee($order->order_number, false);
    }

    public function test_it_appears_once_it_reaches_the_printer(): void
    {
        $order = $this->order();
        $this->reachedTheFloor($order, now()->subHour());

        $this->actingAs($this->mover())->get('/messages')
            ->assertOk()
            ->assertSee($order->order_number, false);
    }

    public function test_everyone_else_still_sees_it_in_their_inbox(): void
    {
        $order = $this->order();
        $sales = User::find($order->created_by);

        $this->actingAs($sales)->get('/messages')
            ->assertOk()
            ->assertSee($order->order_number, false);
    }

    public function test_open_tech_pack_goes_to_the_pack_not_the_money(): void
    {
        $order = $this->order();
        $this->reachedTheFloor($order, now()->subHour());

        // The order admin page opens on payments and pricing — the account
        // officer's business, not the floor's.
        $this->actingAs($this->mover())->get("/messages/{$order->id}")
            ->assertOk()
            ->assertSee(route('orders.job-order', $order), false);
    }

    // ---- The unread badge --------------------------------------------------

    public function test_the_badge_does_not_count_jobs_that_are_not_hers(): void
    {
        $order = $this->order();
        $sales = User::find($order->created_by);
        $mover = $this->mover();

        $this->said($order, $sales, 'still waiting on the downpayment', now()->subDay());

        // Promising unread messages on a job she cannot open is worse than no
        // badge at all — she clicks it and finds nothing.
        $this->assertSame(0, Message::unreadFor($mover->id));
    }

    public function test_the_badge_counts_it_once_it_reaches_the_printer(): void
    {
        $order = $this->order();
        $sales = User::find($order->created_by);
        $mover = $this->mover();

        $this->said($order, $sales, 'printer has it now', now()->subMinutes(10));
        $this->reachedTheFloor($order, now()->subHour());

        $this->assertSame(1, Message::unreadFor($mover->id));
    }

    public function test_the_badge_matches_what_her_inbox_lists(): void
    {
        $hers = $this->order();
        $sales = User::find($hers->created_by);
        $mover = $this->mover();

        $notHers = ProductionOrder::create([
            'order_number' => 'IC2026-08801', 'customer_name' => 'Not Hers',
            'product_type' => 'round_neck', 'quantity' => 5,
            'due_date' => now()->addWeek(), 'status' => 'active', 'created_by' => $sales->id,
        ]);

        $this->said($hers, $sales, 'on the floor', now()->subMinutes(5));
        $this->said($notHers, $sales, 'still with the artist', now()->subMinutes(5));
        $this->reachedTheFloor($hers, now()->subHour());

        $this->assertSame(1, Message::unreadFor($mover->id), 'one thread, one unread');

        $this->actingAs($mover)->get('/messages')
            ->assertOk()
            ->assertSee($hers->order_number, false)
            ->assertDontSee($notHers->order_number, false);
    }

    public function test_everyone_else_is_counted_as_before(): void
    {
        $order = $this->order();
        $sales = User::find($order->created_by);
        $leader = User::factory()->create(['job_role' => User::ROLE_LEADER, 'is_active' => true]);

        $this->said($order, $sales, 'anything', now());

        // Not on the floor yet, but a leader sees the whole shop regardless.
        $this->assertSame(1, Message::unreadFor($leader->id));
    }
}
