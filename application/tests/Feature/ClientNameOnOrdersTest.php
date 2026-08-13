<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\ProductionOrder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Whose name an order shows.
 *
 * customer_name on the order is a COPY, written when the order was taken. The
 * client record it was copied from is shared across all of that client's
 * orders, so correcting a name — on the client, or on any one order — left
 * every other order still showing the old spelling, with nothing on screen to
 * say it was out of date. One live order was headed "Mamangun" for a client
 * the same page named as Cecilia Villanueva.
 */
class ClientNameOnOrdersTest extends TestCase
{
    use RefreshDatabase;

    private function sales(): User
    {
        return User::factory()->create(['job_role' => User::ROLE_SALES, 'is_active' => true]);
    }

    private function order(Client $client = null, string $copy = 'Mamangun'): ProductionOrder
    {
        return ProductionOrder::create([
            'order_number' => 'IC2026-0'.random_int(1000, 9999),
            'customer_name' => $copy,
            'client_id' => $client?->id,
            'product_type' => 'round_neck',
            'quantity' => 55,
            'due_date' => now()->addWeeks(4),
            'created_by' => $this->sales()->id,
            'status' => 'active',
        ]);
    }

    public function test_the_client_record_wins_over_the_copy_on_the_order(): void
    {
        $client = Client::create(['name' => 'Cecilia', 'last_name' => 'Villanueva']);

        $this->assertSame('Cecilia Villanueva', $this->order($client)->clientName());
    }

    public function test_the_whole_name_is_used_not_just_the_first(): void
    {
        $client = Client::create(['name' => 'Cecilia', 'last_name' => 'Villanueva']);

        // Half the app read ->name and printed "Cecilia" for a client whose
        // surname is on the quotation and the delivery receipt.
        $this->assertStringContainsString('Villanueva', $this->order($client)->clientName());
    }

    public function test_an_order_with_no_client_attached_still_has_a_name(): void
    {
        // Orders old enough to predate the client list have only the copy.
        // (The order title-cases what it stores, hence the capital I.)
        $this->assertSame('Walk-In Customer', $this->order(null, 'Walk-in Customer')->clientName());
    }

    public function test_a_client_with_no_surname_does_not_gain_a_stray_space(): void
    {
        $client = Client::create(['name' => 'Cecilia']);

        $this->assertSame('Cecilia', $this->order($client)->clientName());
    }

    public function test_correcting_the_client_reaches_the_order_header(): void
    {
        $client = Client::create(['name' => 'Cecilia', 'last_name' => 'Villanueva']);
        $order = $this->order($client);
        $sales = User::find($order->created_by);

        $this->actingAs($sales)->get("/orders/{$order->id}")
            ->assertOk()
            ->assertSee('Cecilia Villanueva')
            ->assertDontSee('Mamangun');
    }

    public function test_the_orders_list_names_the_client_too(): void
    {
        $client = Client::create(['name' => 'Cecilia', 'last_name' => 'Villanueva']);
        $order = $this->order($client);

        $this->actingAs(User::find($order->created_by))->get('/orders')
            ->assertOk()
            ->assertSee('Cecilia Villanueva')
            ->assertDontSee('Mamangun');
    }
}
