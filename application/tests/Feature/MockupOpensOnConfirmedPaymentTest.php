<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\ProductionOrder;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Finance confirms the downpayment, and the artist's mockup opens.
 *
 * It used to wait for the officer to send the job order as well, which held
 * the artist's own next piece of work behind somebody else's paperwork — the
 * mockup is drawn from the approved layout, not from the tech pack.
 */
class MockupOpensOnConfirmedPaymentTest extends TestCase
{
    use RefreshDatabase;

    private function orderWithApprovedLayout(?float $total = 5000, float $discount = 0): array
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
        $order->refresh();

        // The artist draws the layout and the client approves it.
        $layout = $order->tasks()->where('stage', ProductionOrder::STAGE_LAYOUT)->get();
        foreach ($layout as $task) {
            $task->update(['assigned_to' => $artist->id, 'status' => 'complete', 'released_at' => now()]);
        }

        return [$order->fresh(), $officer, $artist];
    }

    private function mockup(ProductionOrder $order): ?Task
    {
        return $order->tasks()->where('department', 'like', 'Final mockup%')->first();
    }

    public function test_the_mockup_stays_shut_until_the_money_is_confirmed(): void
    {
        [$order, $officer, ] = $this->orderWithApprovedLayout();

        // Recorded by the officer, not yet confirmed by Finance.
        $order->payments()->create([
            'amount' => 2500, 'kind' => 'downpayment', 'recorded_by' => $officer->id,
        ]);

        $order->handleTaskCompleted(
            $order->tasks()->where('stage', ProductionOrder::STAGE_LAYOUT)->first()
        );

        $this->assertSame('todo', $this->mockup($order->fresh())->status,
            'a claim of payment is not payment');
    }

    public function test_confirming_the_downpayment_opens_the_mockup(): void
    {
        [$order, $officer, $artist] = $this->orderWithApprovedLayout();
        $finance = User::factory()->create(['job_role' => 'finance', 'is_active' => true]);

        $order->payments()->create([
            'amount' => 2500, 'kind' => 'downpayment', 'recorded_by' => $officer->id,
            'confirmed_at' => now(), 'confirmed_by' => $finance->id,
        ]);

        $order->fresh()->unlockStage(ProductionOrder::STAGE_MOCKUP);

        $mockup = $this->mockup($order->fresh());

        $this->assertNotSame('todo', $mockup->status, 'it is open to the artist now');
        $this->assertNotNull($mockup->released_at);
    }

    public function test_the_artist_does_not_wait_for_the_tech_pack_to_be_sent(): void
    {
        [$order, $officer, ] = $this->orderWithApprovedLayout();
        $finance = User::factory()->create(['job_role' => 'finance', 'is_active' => true]);

        $order->payments()->create([
            'amount' => 2500, 'kind' => 'downpayment', 'recorded_by' => $officer->id,
            'confirmed_at' => now(), 'confirmed_by' => $finance->id,
        ]);

        $order = $order->fresh();

        // No job order has been sent — that used to be the second gate.
        $this->assertNotSame('sent_to_artist', $order->jobOrder?->status);

        $order->handleTaskCompleted(
            $order->tasks()->where('stage', ProductionOrder::STAGE_LAYOUT)->first()
        );

        $this->assertNotSame('todo', $this->mockup($order->fresh())->status);
    }

    public function test_a_sponsored_job_opens_without_a_payment_at_all(): void
    {
        // Nothing is owed, so no payment will ever arrive to confirm.
        [$order, , ] = $this->orderWithApprovedLayout(0, 5000);

        $order->handleTaskCompleted(
            $order->tasks()->where('stage', ProductionOrder::STAGE_LAYOUT)->first()
        );

        $this->assertNotSame('todo', $this->mockup($order->fresh())->status);
    }

    public function test_the_tech_pack_waits_for_the_mockup_to_be_approved(): void
    {
        [$order, $officer, $artist] = $this->orderWithApprovedLayout();
        $finance = User::factory()->create(['job_role' => 'finance', 'is_active' => true]);

        $order->payments()->create([
            'amount' => 2500, 'kind' => 'downpayment', 'recorded_by' => $officer->id,
            'confirmed_at' => now(), 'confirmed_by' => $finance->id,
        ]);

        $order->fresh()->unlockStage(ProductionOrder::STAGE_MOCKUP);
        $order = $order->fresh();

        $mockup = $this->mockup($order);
        $techPack = $order->tasks()->where('department', 'Tech pack')->first();

        // Both live in stage 2, but the pack is built FROM the approved design.
        $this->assertNotSame('todo', $mockup->status, 'the mockup is open');
        $this->assertSame('todo', $techPack->status, 'the tech pack is not, yet');

        // The client approves the mockup. The pack still does not open on its
        // own: the officer fills their half and SENDS it, and that is what
        // hands the artist's tech pack step over. Mockup approval is the first
        // of those two, not the only one.
        $mockup->update(['status' => 'complete']);
        $order->fresh()->handleTaskCompleted($mockup->fresh());

        $this->assertSame('todo', $order->fresh()->tasks()
            ->where('department', 'Tech pack')->first()->status,
            'approved, but the officer has not sent the pack yet');

        // The officer's half of the pack, sent.
        \App\Models\JobOrder::updateOrCreate(
            ['production_order_id' => $order->id],
            ['status' => 'sent_to_artist', 'created_by' => $officer->id]
        );
        $order->fresh()->handleTaskCompleted($mockup->fresh());

        $this->assertNotSame('todo', $order->fresh()->tasks()
            ->where('department', 'Tech pack')->first()->status,
            'sent — now it is the artist step');
    }

    public function test_an_unpriced_job_still_waits(): void
    {
        [$order, , ] = $this->orderWithApprovedLayout(null);

        $order->handleTaskCompleted(
            $order->tasks()->where('stage', ProductionOrder::STAGE_LAYOUT)->first()
        );

        $this->assertSame('todo', $this->mockup($order->fresh())->status,
            '"For quotation" is not the same as paid');
    }
}
