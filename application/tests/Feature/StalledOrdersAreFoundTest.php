<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\ProductionOrder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A job waiting for something that will never come should be found by the
 * shop, not by the client asking where their shirts are.
 */
class StalledOrdersAreFoundTest extends TestCase
{
    use RefreshDatabase;

    private function orderWithFinishedLayout(?float $total, float $discount = 0): ProductionOrder
    {
        $officer = User::factory()->create(['job_role' => User::ROLE_SALES, 'is_active' => true]);
        $artist = User::factory()->create(['job_role' => 'artist', 'is_active' => true]);

        $order = ProductionOrder::create([
            'order_number' => 'IC2026-0'.random_int(1000, 9999),
            'customer_name' => 'Juan Dela Cruz',
            'client_id' => Client::create([
                'name' => 'Juan', 'last_name' => 'Dela Cruz', 'contact_number' => '0917',
                'office_address' => 'Angeles City', 'delivery_address' => 'Angeles City',
                'created_by' => $officer->id,
            ])->id,
            'product_type' => 'round_neck',
            'quantity' => 10,
            'unit_price' => $total === null ? null : 500,
            'total_price' => $total,
            'discount_amount' => $discount,
            'due_date' => now()->addWeeks(3)->toDateString(),
            'status' => 'active',
            'created_by' => $officer->id,
        ]);

        $order->buildPipeline([], 'manual');

        // The layout is finished, and nothing opened after it — the exact
        // state IC2026-00001 sat in.
        foreach ($order->fresh()->tasks()->where('stage', ProductionOrder::STAGE_LAYOUT)->get() as $task) {
            $task->update(['assigned_to' => $artist->id, 'status' => 'complete', 'released_at' => now()]);
        }

        return $order->fresh();
    }

    private function mockupStatus(ProductionOrder $order): string
    {
        return $order->fresh()->tasks()
            ->where('department', 'like', 'Final mockup%')->first()->status;
    }

    public function test_a_sponsored_job_stuck_after_its_layout_is_reported(): void
    {
        $order = $this->orderWithFinishedLayout(0, 5000);

        // Both facts are on one line, and each expectation consumes a line.
        $this->artisan('orders:stalled')
            ->expectsOutputToContain($order->order_number.' — Juan Dela Cruz: stage 2 should be open (nothing owed)')
            ->assertSuccessful();

        $this->assertSame('todo', $this->mockupStatus($order), 'reporting alone changes nothing');
    }

    public function test_fix_opens_the_stage_that_should_have_opened(): void
    {
        $order = $this->orderWithFinishedLayout(0, 5000);

        $this->artisan('orders:stalled --fix')->assertSuccessful();

        $this->assertNotSame('todo', $this->mockupStatus($order));
    }

    public function test_a_job_still_owed_money_is_not_stuck(): void
    {
        $this->orderWithFinishedLayout(5000);

        $this->artisan('orders:stalled')
            ->expectsOutput('Nothing is stuck.')
            ->assertSuccessful();
    }

    public function test_an_unpriced_job_is_not_stuck_either(): void
    {
        $this->orderWithFinishedLayout(null);

        $this->artisan('orders:stalled')
            ->expectsOutput('Nothing is stuck.')
            ->assertSuccessful();
    }

    public function test_a_job_whose_mockup_is_already_open_is_not_stuck(): void
    {
        $order = $this->orderWithFinishedLayout(0, 5000);
        $order->unlockStage(ProductionOrder::STAGE_MOCKUP);

        $this->artisan('orders:stalled')
            ->expectsOutput('Nothing is stuck.')
            ->assertSuccessful();
    }
}
