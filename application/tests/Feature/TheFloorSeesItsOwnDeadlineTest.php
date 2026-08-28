<?php

namespace Tests\Feature;

use App\Models\ProductionOrder;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The bench sees the date for ITS OWN step.
 *
 * The board and the work sheet both showed the order's due date — the day the
 * client expects the goods. A sewer eight steps out cannot tell from that
 * whether they are early or three days behind, which is the whole reason the
 * steps carry their own dates.
 */
class TheFloorSeesItsOwnDeadlineTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: User, 1: ProductionOrder, 2: Task} */
    private function jobAtSewing(?\Carbon\CarbonInterface $stepDue = null): array
    {
        $sales = User::factory()->create(['job_role' => User::ROLE_SALES, 'is_active' => true]);
        $sewer = User::factory()->create(['job_role' => 'Sewing', 'is_active' => true]);

        $order = ProductionOrder::create([
            'order_number' => 'IC2026-FLOOR', 'customer_name' => 'Floor Co',
            'product_type' => 'round_neck', 'quantity' => 20,
            'due_date' => now()->addDays(20), 'created_by' => $sales->id, 'status' => 'active',
        ]);

        $order->jobOrder()->create(['status' => 'sent_to_artist', 'created_by' => $sales->id]);

        $step = Task::create([
            'production_order_id' => $order->id,
            'department' => 'Sewing', 'sequence' => 7, 'stage' => 7,
            'status' => 'ready', 'team' => User::JOB_PRODUCTION,
            'due_at' => $stepDue ?? now()->addDays(5),
        ]);

        return [$sewer, $order->refresh(), $step];
    }

    public function test_the_board_says_when_this_step_is_wanted(): void
    {
        [$sewer, , $step] = $this->jobAtSewing();

        $this->actingAs($sewer)->get('/stations')
            ->assertOk()
            ->assertSee('DUE '.strtoupper($step->due_at->format('M j')));
    }

    public function test_a_late_step_says_so_on_the_board(): void
    {
        [$sewer, , $step] = $this->jobAtSewing(now()->subDays(2));

        $this->actingAs($sewer)->get('/stations')
            ->assertOk()
            ->assertSee('DELAYED · was due '.$step->due_at->format('M j'), false);
    }

    public function test_the_order_due_date_is_still_there_too(): void
    {
        // Both matter: the client's day and this bench's day are different
        // questions, and the board should not answer one by hiding the other.
        [$sewer, $order] = $this->jobAtSewing();

        $this->actingAs($sewer)->get('/stations')
            ->assertOk()
            ->assertSee($order->order_number);
    }

    public function test_a_step_with_no_date_says_nothing(): void
    {
        // Orders whose money is not confirmed have no dates yet, and an
        // invented one would be worse than none.
        [$sewer, , $step] = $this->jobAtSewing();
        $step->update(['due_at' => null]);

        $this->actingAs($sewer)->get('/stations')
            ->assertOk()
            ->assertDontSee('step-due-chip');
    }
}
