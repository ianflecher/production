<?php

namespace Tests\Feature;

use App\Models\ProductionOrder;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The artist leader hands out the artists' work, and nothing else.
 *
 * He already checks their tech packs and manages their accounts. Giving a step
 * to somebody sat behind the leader's own permission, so the one person who
 * knows which artist is free had to send a message to have it done.
 *
 * The line is the same one drawn everywhere else he appears: the artists are
 * his, the rest of the floor is not. A sewing step still belongs to the leader,
 * and so does the sewer he would be handing it to.
 */
class ArtistLeaderAssignsTheArtistsTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: User, 1: ProductionOrder} */
    private function shop(): array
    {
        $sales = User::factory()->create(['job_role' => User::ROLE_SALES, 'is_active' => true]);
        $lead = User::factory()->create(['job_role' => User::JOB_ARTIST_LEAD, 'is_active' => true]);

        $order = ProductionOrder::create([
            'order_number' => 'IC2026-LEADS', 'customer_name' => 'Lead Co',
            'product_type' => 'round_neck', 'quantity' => 10,
            'due_date' => now()->addWeeks(2), 'unit_price' => 350,
            'created_by' => $sales->id, 'status' => 'active',
        ]);

        $order->items()->create(['size' => 'M', 'quantity' => 10]);
        $order->jobOrder()->create(['status' => 'draft', 'created_by' => $sales->id]);
        $order->refresh()->buildPipeline([], 'manual');

        return [$lead, $order->refresh()];
    }

    private function artistStep(ProductionOrder $order): Task
    {
        return $order->tasks()->where('team', User::JOB_ARTIST)->firstOrFail();
    }

    public function test_he_gives_an_artist_step_to_an_artist(): void
    {
        [$lead, $order] = $this->shop();
        $artist = User::factory()->create(['job_role' => User::JOB_ARTIST, 'is_active' => true]);

        $step = $this->artistStep($order);

        $this->actingAs($lead)
            ->post(route('tasks.assign', $step), ['assigned_to' => $artist->id])
            ->assertRedirect();

        $this->assertSame($artist->id, $step->fresh()->assigned_to);
    }

    public function test_he_can_take_it_back_off_them(): void
    {
        [$lead, $order] = $this->shop();
        $artist = User::factory()->create(['job_role' => User::JOB_ARTIST, 'is_active' => true]);

        $step = $this->artistStep($order);
        $step->update(['assigned_to' => $artist->id]);

        $this->actingAs($lead)
            ->post(route('tasks.assign', $step), ['assigned_to' => null])
            ->assertRedirect();

        $this->assertNull($step->fresh()->assigned_to);
    }

    public function test_the_rest_of_the_floor_is_not_his_to_hand_out(): void
    {
        // A sewing step belongs to the leader, and so does the sewer.
        [$lead, $order] = $this->shop();
        $sewer = User::factory()->create(['job_role' => 'Sewing', 'is_active' => true]);

        $step = $order->tasks()->where('department', 'Sewing')->firstOrFail();

        $this->actingAs($lead)
            ->post(route('tasks.assign', $step), ['assigned_to' => $sewer->id])
            ->assertForbidden();

        $this->assertNull($step->fresh()->assigned_to);
    }

    public function test_the_leader_still_hands_out_anything(): void
    {
        // Widening the door for one person must not narrow it for another.
        [, $order] = $this->shop();
        $leader = User::factory()->create(['job_role' => User::ROLE_LEADER, 'is_active' => true]);
        $sewer = User::factory()->create(['job_role' => 'Sewing', 'is_active' => true]);

        $step = $order->tasks()->where('department', 'Sewing')->firstOrFail();

        $this->actingAs($leader)
            ->post(route('tasks.assign', $step), ['assigned_to' => $sewer->id])
            ->assertRedirect();

        $this->assertSame($sewer->id, $step->fresh()->assigned_to);
    }

    public function test_an_artist_cannot_hand_work_to_themselves(): void
    {
        // Being one of the artists is not the same as leading them.
        [, $order] = $this->shop();
        $artist = User::factory()->create(['job_role' => User::JOB_ARTIST, 'is_active' => true]);

        $step = $this->artistStep($order);

        $this->actingAs($artist)
            ->post(route('tasks.assign', $step), ['assigned_to' => $artist->id])
            ->assertForbidden();

        $this->assertNull($step->fresh()->assigned_to);
    }

    public function test_the_control_is_on_a_page_he_can_actually_open(): void
    {
        // The permission was useless without this. The only assign control was
        // on the order page, and the order page is not his to open — he is not
        // in its role list and never has been.
        [$lead, $order] = $this->shop();
        $artist = User::factory()->create(['job_role' => User::JOB_ARTIST, 'is_active' => true, 'name' => 'Mick']);

        $step = $this->artistStep($order);
        $step->update(['status' => 'ready', 'assigned_to' => $artist->id]);

        $this->actingAs($lead)->get(route('approvals'))
            ->assertOk()
            ->assertSee("The artists' work", false)
            ->assertSee($order->order_number)
            ->assertSee('Mick');

        // And the page he cannot open is still shut.
        $this->actingAs($lead)->get(route('orders.show', $order))->assertForbidden();
    }

    public function test_his_own_step_stays_off_that_page(): void
    {
        // The same line his checking queue draws: his own work is not shown to
        // him there. A page for handing work out is not where he looks at his.
        [$lead, $order] = $this->shop();

        $step = $this->artistStep($order);
        $step->update(['status' => 'ready', 'assigned_to' => $lead->id]);

        $this->actingAs($lead)->get(route('approvals'))
            ->assertOk()
            ->assertDontSee($order->order_number);
    }

    public function test_sales_still_cannot_hand_out_work(): void
    {
        [, $order] = $this->shop();
        $sales = User::factory()->create(['job_role' => User::ROLE_SALES, 'is_active' => true]);
        $artist = User::factory()->create(['job_role' => User::JOB_ARTIST, 'is_active' => true]);

        $this->actingAs($sales)
            ->post(route('tasks.assign', $this->artistStep($order)), ['assigned_to' => $artist->id])
            ->assertForbidden();
    }
}
