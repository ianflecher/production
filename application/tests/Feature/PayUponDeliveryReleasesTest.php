<?php

namespace Tests\Feature;

use App\Models\ProductionOrder;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A pay-upon-delivery job goes out of the door, and the balance is written down.
 *
 * Nothing leaves unpaid - that is the last safeguard before the client has the
 * goods. But some clients are taken on delivery terms: no money down, they pay
 * as they receive. Those orders reached the counter and stopped there, over a
 * balance nobody had ever intended to collect first, and the only way past was
 * a leader override written up as though something had gone wrong.
 *
 * The downpayment waiver is what says a client is on those terms - a client
 * trusted to start without money down is the client who pays on delivery, and
 * there is no third arrangement in the shop.
 *
 * The money still matters: what is owed is written onto the order's
 * conversation as the goods go out, which is the thread the people who chase
 * it actually read.
 */
class PayUponDeliveryReleasesTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: User, 1: ProductionOrder, 2: Task} */
    private function orderAtTheCounter(bool $onDeliveryTerms): array
    {
        $officer = User::factory()->create(['job_role' => User::ROLE_SALES, 'is_active' => true]);
        $desk = User::factory()->create(['job_role' => 'Inventory', 'is_active' => true]);

        $order = ProductionOrder::create([
            'order_number' => 'IC2026-COD'.random_int(100, 999),
            'customer_name' => 'Delivery Terms Client',
            'product_type' => 'round_neck',
            'quantity' => 20,
            'due_date' => now()->addWeek(),
            'created_by' => $officer->id,
            'status' => 'active',
            'total_price' => 12000,
            'downpayment_waived' => $onDeliveryTerms,
            'downpayment_waiver_note' => $onDeliveryTerms ? 'Pays on delivery, long-standing client.' : null,
        ]);

        $release = Task::create([
            'production_order_id' => $order->id,
            'department' => 'Release to client',
            'sequence' => 20,
            'stage' => 16,
            'status' => 'for_checking',
            'approver_role' => 'inventory',
            'submitted_at' => now(),
        ]);

        return [$desk, $order->fresh(), $release];
    }

    public function test_a_delivery_terms_order_can_be_handed_over_unpaid(): void
    {
        [$desk, $order, $release] = $this->orderAtTheCounter(onDeliveryTerms: true);

        $this->assertFalse($order->isFullyPaid());
        $this->assertTrue($order->paysOnDelivery());

        $this->actingAs($desk)->post(route('products.release', $release), [
            'operator_name' => 'Aling Nena',
        ])->assertRedirect();

        $this->assertSame('complete', $release->fresh()->status);
    }

    public function test_what_is_owed_is_written_where_the_money_is_chased(): void
    {
        [$desk, $order, $release] = $this->orderAtTheCounter(onDeliveryTerms: true);

        $this->actingAs($desk)->post(route('products.release', $release), [
            'operator_name' => 'Aling Nena',
        ])->assertRedirect();

        $note = $order->fresh()->messages()->latest('id')->first();

        $this->assertNotNull($note, 'the counter needs to know there is money to collect');
        $this->assertStringContainsString('PAY-UPON-DELIVERY', $note->body);
        $this->assertStringContainsString('12,000.00', $note->body);
        $this->assertStringContainsString('Aling Nena', $note->body);
    }

    public function test_an_ordinary_order_is_still_held_until_it_is_paid(): void
    {
        // The safeguard is untouched for everybody else.
        [$desk, $order, $release] = $this->orderAtTheCounter(onDeliveryTerms: false);

        $this->actingAs($desk)->post(route('products.release', $release), [
            'operator_name' => 'Aling Nena',
        ])->assertRedirect();

        $this->assertSame('for_checking', $release->fresh()->status, 'it must not go out');
        $this->assertStringContainsString('still unpaid', session('error'));
    }

    public function test_a_paid_delivery_terms_order_records_nothing_extra(): void
    {
        // Paid before collection, which happens: no note, nothing to chase.
        [$desk, $order, $release] = $this->orderAtTheCounter(onDeliveryTerms: true);

        $order->payments()->create([
            'amount' => 12000,
            'status' => 'confirmed',
            'confirmed_at' => now(),
        ]);

        $this->assertTrue($order->fresh()->isFullyPaid());

        $this->actingAs($desk)->post(route('products.release', $release), [
            'operator_name' => 'Aling Nena',
        ])->assertRedirect();

        $this->assertSame('complete', $release->fresh()->status);
        $this->assertSame(0, $order->fresh()->messages()->count());
    }
}
