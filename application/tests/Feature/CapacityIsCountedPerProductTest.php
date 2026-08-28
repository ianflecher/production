<?php

namespace Tests\Feature;

use App\Models\ProductionOrder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A day's capacity is counted per product.
 *
 * Five hundred shirts and five hundred riding jerseys are not the same day's
 * work and do not compete for the same bench. Counting every product together
 * meant a date full of shirts refused a jersey — and the hint under the due
 * date said "216 of 500" without ever saying 216 of WHAT.
 */
class CapacityIsCountedPerProductTest extends TestCase
{
    use RefreshDatabase;

    private function officer(): User
    {
        return User::factory()->create(['job_role' => User::ROLE_SALES, 'is_active' => true]);
    }

    private function booked(User $officer, string $product, int $qty, string $number): void
    {
        $order = ProductionOrder::create([
            'order_number' => $number, 'customer_name' => 'Booked Co',
            'product_type' => $product, 'quantity' => $qty,
            'due_date' => now()->addWeeks(6), 'created_by' => $officer->id, 'status' => 'active',
        ]);

        $order->items()->create(['size' => 'M', 'quantity' => $qty]);
    }

    public function test_a_day_full_of_shirts_still_has_room_for_jerseys(): void
    {
        $officer = $this->officer();
        $this->booked($officer, 'round_neck', 480, 'IC2026-SHIRT');

        $date = now()->addWeeks(6)->toDateString();

        $this->assertSame(480, ProductionOrder::bookedQtyForDate($date, null, 'round_neck'));
        $this->assertSame(0, ProductionOrder::bookedQtyForDate($date, null, 'riding_jersey'),
            'the shirts were counted against the jerseys');
    }

    public function test_the_whole_day_is_still_countable(): void
    {
        // No product named means every product, the way it read before.
        $officer = $this->officer();
        $this->booked($officer, 'round_neck', 300, 'IC2026-MIXED1');
        $this->booked($officer, 'riding_jersey', 100, 'IC2026-MIXED2');

        $this->assertSame(400, ProductionOrder::bookedQtyForDate(now()->addWeeks(6)->toDateString()));
    }

    public function test_the_live_hint_says_how_many_of_what(): void
    {
        $officer = $this->officer();
        $this->booked($officer, 'riding_jersey', 216, 'IC2026-JERSEY');

        $this->actingAs($officer)
            ->getJson(route('orders.capacity', [
                'date' => now()->addWeeks(6)->toDateString(),
                'product_type' => 'riding_jersey',
            ]))
            ->assertOk()
            ->assertJson([
                'booked' => 216,
                'capacity' => 500,
                'remaining' => 284,
                'product' => 'Riding Jersey',
            ]);
    }

    public function test_the_hint_counts_only_the_product_asked_about(): void
    {
        $officer = $this->officer();
        $this->booked($officer, 'round_neck', 480, 'IC2026-SHIRT2');

        $this->actingAs($officer)
            ->getJson(route('orders.capacity', [
                'date' => now()->addWeeks(6)->toDateString(),
                'product_type' => 'riding_jersey',
            ]))
            ->assertOk()
            ->assertJson(['booked' => 0, 'remaining' => 500]);
    }

    public function test_the_refusal_names_the_product(): void
    {
        // "already has 480 of 500 Riding Jersey booked" — a message that says
        // which bench is full, rather than leaving somebody to guess.
        $officer = $this->officer();
        $this->booked($officer, 'riding_jersey', 480, 'IC2026-FULLJ');

        $this->actingAs($officer)->post(route('orders.store'), [
            'client_name' => 'Late', 'client_last_name' => 'Client',
            'client_contact' => '0917 555 0000',
            'client_office_address' => 'Cebu City',
            'client_delivery_address' => 'Cebu City',
            'order_number' => 'IC2026-LATEJ',
            'product_type' => 'riding_jersey',
            'due_date' => now()->addWeeks(6)->toDateString(),
            'sizes' => ['M' => 50],
        ])->assertInvalid(['due_date']);

        $this->assertStringContainsString('Riding Jersey', session('errors')->first('due_date'));
    }
}
