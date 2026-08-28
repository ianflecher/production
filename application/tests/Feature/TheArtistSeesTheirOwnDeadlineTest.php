<?php

namespace Tests\Feature;

use App\Models\ProductionOrder;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The artist's card says when THEIR step is wanted.
 *
 * It carried the order's due date, which for a layout is weeks out and says
 * nothing about today. The step they are holding has its own date and that is
 * the one they are working to — shown in the same chip the station board uses,
 * so the whole shop reads lateness the same way.
 */
class TheArtistSeesTheirOwnDeadlineTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: User, 1: ProductionOrder, 2: Task} */
    private function artistHolding(?\Carbon\CarbonInterface $stepDue): array
    {
        $sales = User::factory()->create(['job_role' => User::ROLE_SALES, 'is_active' => true]);
        $artist = User::factory()->create(['job_role' => User::JOB_ARTIST, 'is_active' => true]);

        $order = ProductionOrder::create([
            'order_number' => 'IC2026-MINE', 'customer_name' => 'Mine Co',
            'product_type' => 'round_neck', 'quantity' => 12,
            'due_date' => now()->addDays(20), 'created_by' => $sales->id, 'status' => 'active',
        ]);

        $step = Task::create([
            'production_order_id' => $order->id,
            'department' => 'Layout', 'sequence' => 1, 'stage' => 1,
            'status' => 'ready', 'team' => User::JOB_ARTIST,
            'assigned_to' => $artist->id, 'due_at' => $stepDue,
        ]);

        return [$artist, $order->refresh(), $step];
    }

    public function test_the_card_says_when_the_step_is_due(): void
    {
        [$artist, , $step] = $this->artistHolding(now()->addDays(3));

        $this->actingAs($artist)->get('/my-tasks')
            ->assertOk()
            ->assertSee('DUE '.strtoupper($step->due_at->format('M j')));
    }

    public function test_a_late_step_is_called_delayed(): void
    {
        [$artist, , $step] = $this->artistHolding(now()->subDays(2));

        $this->actingAs($artist)->get('/my-tasks')
            ->assertOk()
            ->assertSee('DELAYED · was due '.$step->due_at->format('M j'), false);
    }

    public function test_a_step_due_today_says_so(): void
    {
        [$artist] = $this->artistHolding(now()->addHours(3));

        $this->actingAs($artist)->get('/my-tasks')
            ->assertOk()
            ->assertSee('DUE TODAY');
    }

    public function test_the_orders_own_date_is_still_shown(): void
    {
        // Two different questions: the client's day and the artist's day.
        [$artist, $order] = $this->artistHolding(now()->addDays(3));

        $this->actingAs($artist)->get('/my-tasks')
            ->assertOk()
            ->assertSee('Order due '.$order->due_date->format('M j, Y'));
    }

    public function test_a_step_with_no_date_shows_no_chip(): void
    {
        // Before the money is confirmed there is no date, and an invented one
        // would be worse than none.
        [$artist] = $this->artistHolding(null);

        $this->actingAs($artist)->get('/my-tasks')
            ->assertOk()
            ->assertDontSee('DUE ', false)
            ->assertDontSee('DELAYED');
    }
}
