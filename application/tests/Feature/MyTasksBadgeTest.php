<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\ProductionOrder;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The number beside My Tasks is the number of orders on that page.
 *
 * A badge counting one thing while the page it points at lists another is
 * worse than no badge: the artist clicks a 3, finds two, and stops trusting
 * either. So it counts what My Tasks groups by — an open step of theirs, on an
 * order that is still alive.
 */
class MyTasksBadgeTest extends TestCase
{
    use RefreshDatabase;

    private function artist(): User
    {
        return User::factory()->create(['job_role' => User::JOB_ARTIST, 'is_active' => true]);
    }

    private function orderFor(User $artist, string $number, string $taskStatus = 'ready', string $orderStatus = 'active'): ProductionOrder
    {
        $order = ProductionOrder::create([
            'order_number' => $number,
            'client_id' => Client::create(['name' => 'A', 'last_name' => 'Client'])->id,
            'customer_name' => 'A Client',
            'product_type' => 'round_neck',
            'quantity' => 10,
            'due_date' => now()->addWeeks(2),
            'status' => $orderStatus,
            'created_by' => User::factory()->create(['job_role' => User::ROLE_SALES])->id,
        ]);

        $order->items()->create(['size' => 'M', 'quantity' => 10]);
        $order->refresh()->buildPipeline([], 'manual');

        $order->tasks()->where('team', User::JOB_ARTIST)->orderBy('sequence')->first()
            ->forceFill(['assigned_to' => $artist->id, 'status' => $taskStatus])->save();

        return $order;
    }

    /** What the nav shows for this artist. */
    private function badge(User $artist): ?int
    {
        $html = $this->actingAs($artist)->get(route('tasks.mine'))->assertOk()->getContent();

        return preg_match('#My Tasks\s*<span class="count-pill">(\d+)</span>#s', $html, $m)
            ? (int) $m[1]
            : null;
    }

    public function test_it_counts_the_orders_on_the_page(): void
    {
        $artist = $this->artist();

        $this->orderFor($artist, 'IC2026-B001');
        $this->orderFor($artist, 'IC2026-B002', 'in_progress');

        $this->assertSame(2, $this->badge($artist));
    }

    public function test_two_open_steps_on_one_order_are_still_one_order(): void
    {
        $artist = $this->artist();
        $order = $this->orderFor($artist, 'IC2026-B003');

        // A second artist step of theirs, also open, on the same job.
        $order->tasks()->where('team', User::JOB_ARTIST)->where('status', 'todo')
            ->orderBy('sequence')->first()
            ->forceFill(['assigned_to' => $artist->id, 'status' => 'ready'])->save();

        $this->assertSame(1, $this->badge($artist), 'the page groups by order, so the badge must too');
    }

    public function test_finished_locked_and_cancelled_work_is_not_counted(): void
    {
        $artist = $this->artist();

        $this->orderFor($artist, 'IC2026-B004', 'complete');
        $this->orderFor($artist, 'IC2026-B005', 'todo');
        $this->orderFor($artist, 'IC2026-B006', 'ready', 'cancelled');

        $this->assertNull($this->badge($artist), 'nothing open, so no badge at all');
    }

    public function test_somebody_elses_work_is_not_counted(): void
    {
        $artist = $this->artist();
        $other = $this->artist();

        $this->orderFor($other, 'IC2026-B007');

        $this->assertNull($this->badge($artist));
    }

    public function test_the_badge_matches_what_the_page_lists(): void
    {
        $artist = $this->artist();

        $this->orderFor($artist, 'IC2026-B008');
        $this->orderFor($artist, 'IC2026-B009', 'in_progress');
        $this->orderFor($artist, 'IC2026-B010', 'complete');   // not shown, not counted

        $onThePage = Task::where('assigned_to', $artist->id)
            ->whereNotIn('status', ['todo', 'complete', 'cancelled'])
            ->whereHas('order', fn ($q) => $q->where('status', '!=', 'cancelled'))
            ->distinct()->count('production_order_id');

        $this->assertSame($onThePage, $this->badge($artist));
    }
}
