<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\ProductionOrder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The calendar counts a day's capacity per product.
 *
 * Order intake has refused overbooking per product since capacity was made per
 * product — five hundred shirts and five hundred riding jerseys are not the
 * same day's work and do not compete for the same bench. The grid still added
 * everything into one flat 500, so a day holding 300 shirts and 300 jerseys
 * read as overbooked while neither bench was near its own ceiling.
 */
class CalendarCapacityPerProductTest extends TestCase
{
    use RefreshDatabase;

    private function leader(): User
    {
        return User::factory()->create(['job_role' => 'leader', 'is_active' => true]);
    }

    private function order(string $product, int $qty, string $date): ProductionOrder
    {
        $user = User::factory()->create(['job_role' => User::ROLE_SALES, 'is_active' => true]);

        return ProductionOrder::create([
            'order_number' => 'IC-'.$product.'-'.$qty.'-'.uniqid(),
            'client_id' => Client::create([
                'name' => 'Juan', 'last_name' => 'Dela Cruz', 'contact_number' => '0917',
                'office_address' => 'Angeles City', 'delivery_address' => 'Angeles City',
                'created_by' => $user->id,
            ])->id,
            'customer_name' => 'Juan Dela Cruz',
            'product_type' => $product,
            'quantity' => $qty,
            'due_date' => $date,
            'status' => 'active',
            'created_by' => $user->id,
        ]);
    }

    public function test_two_products_do_not_add_up_against_one_ceiling(): void
    {
        $when = now()->addDays(4);

        // 300 + 300 = 600, which the old flat total called overbooked. Neither
        // bench is past its own 500.
        $this->order('round_neck', 300, $when->toDateString());
        $this->order('riding_jersey', 300, $when->toDateString());

        $response = $this->actingAs($this->leader())
            ->get(route('calendar', ['month' => $when->format('Y-m')]))
            ->assertOk();

        $load = $response->viewData('productLoadByDay')->get($when->toDateString());

        $this->assertCount(2, $load, 'each product gets its own line');
        $this->assertSame(60, collect($load)->max('percent'), 'the fullest bench is 300 of 500');
        $this->assertFalse(collect($load)->contains('over', true), 'neither product is full');

        // The day is 60% full, not 120%.
        $this->assertSame(60, (int) $response->viewData('fullestByDay')->get($when->toDateString()));
    }

    public function test_one_product_at_its_ceiling_makes_the_day_full(): void
    {
        $when = now()->addDays(6);

        $this->order('round_neck', 500, $when->toDateString());
        $this->order('riding_jersey', 20, $when->toDateString());

        $response = $this->actingAs($this->leader())
            ->get(route('calendar', ['month' => $when->format('Y-m')]))
            ->assertOk();

        $load = collect($response->viewData('productLoadByDay')->get($when->toDateString()));

        $shirts = $load->firstWhere('type', 'round_neck');
        $jerseys = $load->firstWhere('type', 'riding_jersey');

        $this->assertTrue($shirts['over'], 'the shirts bench is full');
        $this->assertFalse($jerseys['over'], 'the jersey bench is not');
        $this->assertSame(100, (int) $response->viewData('fullestByDay')->get($when->toDateString()));
    }

    public function test_the_cell_names_the_product_it_is_reporting(): void
    {
        $when = now()->addDays(3);
        $this->order('round_neck', 450, $when->toDateString());

        $this->actingAs($this->leader())
            ->get(route('calendar', ['month' => $when->format('Y-m')]))
            ->assertOk()
            ->assertSee('450/500');
    }

    public function test_a_cancelled_order_frees_its_bench(): void
    {
        $when = now()->addDays(8);

        $this->order('round_neck', 500, $when->toDateString())->update(['status' => 'cancelled']);
        $this->order('round_neck', 100, $when->toDateString());

        $response = $this->actingAs($this->leader())
            ->get(route('calendar', ['month' => $when->format('Y-m')]))
            ->assertOk();

        $load = collect($response->viewData('productLoadByDay')->get($when->toDateString()));

        $this->assertSame(100, (int) $load->firstWhere('type', 'round_neck')['qty']);
        $this->assertSame(20, (int) $response->viewData('fullestByDay')->get($when->toDateString()));
    }

    public function test_an_empty_day_reports_nothing_booked(): void
    {
        $when = now()->addDays(9);

        $response = $this->actingAs($this->leader())
            ->get(route('calendar', ['month' => $when->format('Y-m')]))
            ->assertOk();

        $this->assertNull($response->viewData('productLoadByDay')->get($when->toDateString()));
        $this->assertSame(0, (int) $response->viewData('fullestByDay')->get($when->toDateString(), 0));
    }
}
