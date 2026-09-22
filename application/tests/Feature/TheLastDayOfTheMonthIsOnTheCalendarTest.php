<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\ProductionOrder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * A job due on the last day of the month is on that month's calendar.
 *
 * The grid asked for everything due between the first of the month and the
 * last, written as bare dates. due_date is a DATE column on the shop's own
 * database, where a bare date means the whole day, so nobody noticed; anywhere
 * the value carries a time, "2026-09-30 00:00:00" sorts after "2026-09-30" and
 * the last day of the month quietly held nothing.
 *
 * Found on 2026-09-22, when a test booking eight days out landed on the 30th
 * for the first time.
 */
class TheLastDayOfTheMonthIsOnTheCalendarTest extends TestCase
{
    use RefreshDatabase;

    private function leader(): User
    {
        return User::factory()->create(['job_role' => User::ROLE_LEADER, 'is_active' => true]);
    }

    private function orderDue(string $date, int $qty = 120): ProductionOrder
    {
        $user = User::factory()->create(['job_role' => User::ROLE_SALES, 'is_active' => true]);

        return ProductionOrder::create([
            'order_number' => 'IC-LAST-'.uniqid(),
            'client_id' => Client::create([
                'name' => 'Juan', 'last_name' => 'Dela Cruz', 'contact_number' => '0917',
                'created_by' => $user->id,
            ])->id,
            'customer_name' => 'Juan Dela Cruz',
            'product_type' => 'round_neck',
            'quantity' => $qty,
            'due_date' => $date,
            'status' => 'active',
            'created_by' => $user->id,
        ]);
    }

    public function test_a_job_due_on_the_last_day_is_counted(): void
    {
        $last = Carbon::now()->endOfMonth()->toDateString();
        $order = $this->orderDue($last);

        $response = $this->actingAs($this->leader())
            ->get(route('calendar', ['month' => Carbon::now()->format('Y-m')]))
            ->assertOk();

        $load = collect($response->viewData('productLoadByDay')->get($last));

        $this->assertNotNull($load->firstWhere('type', 'round_neck'),
            'the last day of the month holds nothing');
        $this->assertSame(120, (int) $load->firstWhere('type', 'round_neck')['qty']);
        $response->assertSee($order->order_number);
    }

    /** And the first day, which was never in doubt but is the other edge. */
    public function test_a_job_due_on_the_first_day_is_counted(): void
    {
        $first = Carbon::now()->startOfMonth()->toDateString();
        $this->orderDue($first, 40);

        $load = collect($this->actingAs($this->leader())
            ->get(route('calendar', ['month' => Carbon::now()->format('Y-m')]))
            ->assertOk()
            ->viewData('productLoadByDay')->get($first));

        $this->assertSame(40, (int) $load->firstWhere('type', 'round_neck')['qty']);
    }
}
