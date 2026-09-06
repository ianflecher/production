<?php

namespace Tests\Feature;

use App\Models\ProductionOrder;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * "Open tech pack", from a message, has to open for the person reading it.
 *
 * The sheet lives on two roads. orders.job-order is the office one, and the
 * floor cannot walk it — an artist is an agent, and that route admits sales,
 * leader, super_admin, mover and the artist leader only. The thread pointed
 * everybody at the office road, so every artist reached their own sheet, on a
 * job they were working, through the one link that answered 403.
 */
class TheMessageThreadOpensTheArtistsOwnPackTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0:User,1:User,2:ProductionOrder,3:Task} */
    private function shop(): array
    {
        $officer = User::factory()->create(['job_role' => User::ROLE_SALES, 'is_active' => true]);
        $artist = User::factory()->create(['job_role' => User::JOB_ARTIST, 'is_active' => true]);

        $order = ProductionOrder::create([
            'order_number' => 'IC2026-MSG',
            'customer_name' => 'Thread Client',
            'product_type' => 'round_neck',
            'quantity' => 12,
            'due_date' => now()->addWeeks(2),
            'created_by' => $officer->id,
            'status' => 'active',
        ]);
        $order->jobOrder()->create(['status' => 'sent_to_artist', 'created_by' => $officer->id]);

        Task::create([
            'production_order_id' => $order->id,
            'sequence' => 1,
            'stage' => ProductionOrder::STAGE_MOCKUP,
            'department' => 'Final mockup',
            'team' => User::JOB_ARTIST,
            'assigned_to' => $artist->id,
            'status' => 'complete',
            'approver_role' => 'sales',
            'approved_at' => now(),
        ]);

        $pack = Task::create([
            'production_order_id' => $order->id,
            'sequence' => 2,
            'stage' => ProductionOrder::STAGE_MOCKUP,
            'department' => 'Tech pack',
            'team' => User::JOB_ARTIST,
            'assigned_to' => $artist->id,
            'status' => 'in_progress',
            'approver_role' => 'sales',
        ]);

        return [$officer, $artist, $order->fresh(), $pack];
    }

    public function test_the_artist_is_sent_to_their_own_task_not_the_office_route(): void
    {
        [, $artist, $order, $pack] = $this->shop();
        $this->assertTrue($order->mockupApproved());

        $this->actingAs($artist)->get(route('messages.show', $order))
            ->assertOk()
            ->assertSee(route('tasks.job-order', $pack), false)
            ->assertDontSee(route('orders.job-order', $order), false);
    }

    public function test_the_link_the_thread_offers_the_artist_actually_opens(): void
    {
        // The regression: the thread handed the artist a link that answered 403.
        [, $artist, $order, $pack] = $this->shop();

        $this->actingAs($artist)->get(route('orders.job-order', $order))->assertForbidden();
        $this->actingAs($artist)->get(route('tasks.job-order', $pack))->assertOk();
    }

    public function test_the_account_officer_still_gets_the_office_sheet(): void
    {
        [$officer, , $order] = $this->shop();

        $this->actingAs($officer)->get(route('messages.show', $order))
            ->assertOk()
            ->assertSee(route('orders.job-order', $order), false);
    }

    public function test_an_artist_with_no_task_here_is_offered_nothing(): void
    {
        // Better no button than one that turns them away.
        [, , $order] = $this->shop();
        $stranger = User::factory()->create(['job_role' => User::JOB_ARTIST, 'is_active' => true]);

        $response = $this->actingAs($stranger)->get(route('messages.show', $order));

        if ($response->status() === 200) {
            $response->assertDontSee('Open tech pack');
        } else {
            $this->assertSame(403, $response->status());
        }
    }
}
