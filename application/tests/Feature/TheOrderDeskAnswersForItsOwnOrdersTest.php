<?php

namespace Tests\Feature;

use App\Models\ProductionOrder;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Whoever may take an order must be able to answer for it.
 *
 * Carla holds the order desk by name without being in the sales ROLE. The
 * approval gate asked "are you sales?", which she can never answer yes to, so
 * she could write an order and then nobody could approve its mockup: not her,
 * because she is not sales, and not the account officers, because an officer
 * only acts on their own orders and this one was hers. The job stopped dead at
 * its first approval with no way forward for anybody.
 *
 * Taking an order and answering for it are the same job. Whoever can do the
 * first must be able to do the second.
 */
class TheOrderDeskAnswersForItsOwnOrdersTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: ProductionOrder, 1: Task} */
    private function orderAwaitingMockupApproval(User $takenBy): array
    {
        $artist = User::factory()->create(['job_role' => User::JOB_ARTIST, 'is_active' => true]);

        $order = ProductionOrder::create([
            'order_number' => 'IC2026-DESK'.random_int(100, 999),
            'customer_name' => 'Desk Client',
            'product_type' => 'round_neck',
            'quantity' => 20,
            'due_date' => now()->addWeeks(3),
            'created_by' => $takenBy->id,
            'status' => 'active',
        ]);

        $order->jobOrder()->create(['status' => 'draft', 'created_by' => $takenBy->id]);

        $mockup = Task::create([
            'production_order_id' => $order->id,
            'department' => 'Final mockup',
            'sequence' => 2,
            'stage' => ProductionOrder::STAGE_MOCKUP,
            'status' => 'for_checking',
            'team' => User::JOB_ARTIST,
            'assigned_to' => $artist->id,
            'approver_role' => 'sales',
            'submitted_at' => now(),
        ]);

        return [$order->fresh(), $mockup];
    }

    private function carla(): User
    {
        return User::factory()->create([
            'name' => 'Maam Carla',
            'job_role' => 'leader',
            'is_active' => true,
            'can_create_orders' => true,
        ]);
    }

    public function test_she_approves_the_mockup_on_an_order_she_took(): void
    {
        $carla = $this->carla();
        [, $mockup] = $this->orderAwaitingMockupApproval($carla);

        $this->actingAs($carla)->post(route('tasks.approve', $mockup))
            ->assertRedirect();

        $this->assertSame('complete', $mockup->fresh()->status);
    }

    public function test_an_officers_own_orders_still_work_as_before(): void
    {
        $officer = User::factory()->create(['job_role' => User::ROLE_SALES, 'is_active' => true]);
        [, $mockup] = $this->orderAwaitingMockupApproval($officer);

        $this->actingAs($officer)->post(route('tasks.approve', $mockup))
            ->assertRedirect();

        $this->assertSame('complete', $mockup->fresh()->status);
    }

    public function test_somebody_elses_order_is_still_not_yours_to_answer(): void
    {
        // The rule that made the deadlock is kept, not removed: whoever holds
        // the desk answers for THEIR orders.
        $carla = $this->carla();
        $otherOfficer = User::factory()->create(['job_role' => User::ROLE_SALES, 'is_active' => true]);

        [, $mockup] = $this->orderAwaitingMockupApproval($otherOfficer);

        $this->actingAs($carla)->post(route('tasks.approve', $mockup))
            ->assertForbidden();

        $this->assertSame('for_checking', $mockup->fresh()->status);
    }

    public function test_a_leader_without_the_grant_still_cannot(): void
    {
        $carla = $this->carla();
        [, $mockup] = $this->orderAwaitingMockupApproval($carla);

        $plainLeader = User::factory()->create(['job_role' => 'leader', 'is_active' => true]);

        $this->actingAs($plainLeader)->post(route('tasks.approve', $mockup))
            ->assertForbidden();
    }

    public function test_a_supervisor_still_cannot(): void
    {
        $carla = $this->carla();
        [, $mockup] = $this->orderAwaitingMockupApproval($carla);

        $supervisor = User::factory()->create(['job_role' => 'supervisor', 'is_active' => true]);

        $this->actingAs($supervisor)->post(route('tasks.approve', $mockup))
            ->assertForbidden();
    }

    public function test_no_order_can_end_up_with_nobody_able_to_approve_it(): void
    {
        // The shape of the bug itself: an order taken by the desk holder, with
        // an officer who is not its creator standing by. Somebody must be able
        // to move it.
        $carla = $this->carla();
        User::factory()->create(['job_role' => User::ROLE_SALES, 'is_active' => true]);

        [, $mockup] = $this->orderAwaitingMockupApproval($carla);

        $canApprove = User::where('is_active', true)->get()->filter(function ($user) use ($mockup) {
            return $this->actingAs($user)
                ->post(route('tasks.approve', $mockup))
                ->getStatusCode() !== 403;
        });

        $this->assertTrue($canApprove->isNotEmpty(), 'the job would stop dead here');
    }
}
