<?php

namespace Tests\Feature;

use App\Models\ProductionOrder;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The people listed under a conversation say what they are doing on it.
 *
 * The list was bare first names — who is listening, but not who to ask. On a
 * shop with two people called Jully it could not even do that: the same chip
 * appeared twice with nothing to tell them apart.
 */
class ConversationParticipantsTest extends TestCase
{
    use RefreshDatabase;

    private function order(): array
    {
        $sales = User::factory()->create([
            'job_role' => User::ROLE_SALES, 'is_active' => true, 'name' => 'Nasser',
        ]);

        $order = ProductionOrder::create([
            'order_number' => 'IC2026-06001', 'customer_name' => 'Talkative Co',
            'product_type' => 'round_neck', 'quantity' => 20,
            'due_date' => now()->addWeek(), 'created_by' => $sales->id, 'status' => 'active',
        ]);

        return [$sales, $order];
    }

    /** (order, sequence) is unique, so hand out numbers rather than guess them. */
    private int $seq = 0;

    private function assignedTo(ProductionOrder $order, User $who, string $department): void
    {
        Task::create([
            'production_order_id' => $order->id,
            'department' => $department,
            'sequence' => ++$this->seq,
            'stage' => 3,
            'status' => 'ready',
            'assigned_to' => $who->id,
        ]);
    }

    public function test_a_participant_is_shown_the_step_they_hold(): void
    {
        [$sales, $order] = $this->order();
        $sewer = User::factory()->create(['job_role' => 'Sewing', 'is_active' => true, 'name' => 'Jully']);
        $this->assignedTo($order, $sewer, 'Sewing');

        $this->actingAs($sales)->get("/messages/{$order->id}")
            ->assertOk()
            ->assertSee('Jully')
            ->assertSee('Sewing');
    }

    public function test_two_people_with_the_same_name_can_be_told_apart(): void
    {
        [$sales, $order] = $this->order();
        $a = User::factory()->create(['job_role' => 'Sewing', 'is_active' => true, 'name' => 'Jully']);
        $b = User::factory()->create(['job_role' => 'Quality control', 'is_active' => true, 'name' => 'Jully']);

        $this->assignedTo($order, $a, 'Sewing');
        $this->assignedTo($order, $b, 'Quality control');

        $this->actingAs($sales)->get("/messages/{$order->id}")
            ->assertOk()
            ->assertSee('Sewing')
            ->assertSee('Quality control');
    }

    public function test_the_officer_who_took_the_order_is_named_as_such(): void
    {
        [$sales, $order] = $this->order();

        $this->actingAs($sales)->get("/messages/{$order->id}")
            ->assertOk()
            ->assertSee('Account officer for this order');
    }

    public function test_someone_on_every_conversation_shows_their_job_instead(): void
    {
        // A leader is here by role, not because of this order — so there is no
        // step to name, and their job is the honest answer.
        [$sales, $order] = $this->order();
        User::factory()->create([
            'job_role' => User::ROLE_LEADER, 'is_active' => true, 'name' => 'Maam Carla',
        ]);

        $this->actingAs($sales)->get("/messages/{$order->id}")
            ->assertOk()
            ->assertSee('Maam Carla')
            ->assertSee('Leader');
    }

    public function test_holding_several_steps_lists_them_all(): void
    {
        [$sales, $order] = $this->order();
        $busy = User::factory()->create(['job_role' => 'Production', 'is_active' => true, 'name' => 'Rommie']);

        $this->assignedTo($order, $busy, 'Pairing');
        $this->assignedTo($order, $busy, 'Sewing');

        $this->actingAs($sales)->get("/messages/{$order->id}")
            ->assertOk()
            ->assertSee('Pairing, Sewing');
    }

    public function test_the_list_does_not_cost_a_query_per_person(): void
    {
        [$sales, $order] = $this->order();

        foreach (range(1, 12) as $n) {
            $who = User::factory()->create(['job_role' => 'Sewing', 'is_active' => true, 'name' => "Sewer $n"]);
            $this->assignedTo($order, $who, 'Sewing');
        }

        \DB::enableQueryLog();
        $this->actingAs($sales)->get("/messages/{$order->id}")->assertOk();
        $count = count(\DB::getQueryLog());
        \DB::disableQueryLog();

        $this->assertLessThan(25, $count,
            'the step each person holds must come from one query, not one each');
    }
}
