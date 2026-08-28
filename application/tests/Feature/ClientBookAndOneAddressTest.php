<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Inquiry;
use App\Models\ProductionOrder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The client book, the follow-up list, and the single address box.
 *
 * The book is new: until now a client could be saved and never looked up
 * again. The follow-up list already existed and already handed a leader every
 * inquiry — it was the route gate that kept leaders and supervisors out.
 */
class ClientBookAndOneAddressTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $jobRole): User
    {
        return User::factory()->create(['job_role' => $jobRole, 'is_active' => true]);
    }

    private function inquiryPayload(array $o = []): array
    {
        return array_merge([
            'client_name' => 'Juan',
            'client_last_name' => 'Dela Cruz',
            'client_contact' => '0917-555-1234',
            'client_address' => '12 Rizal St., Angeles City',
            'what_they_want' => '50 round neck shirts',
        ], $o);
    }

    // ---- who gets in --------------------------------------------------

    #[DataProvider('oversightRoles')]
    public function test_the_client_book_opens_for_the_people_who_oversee(string $jobRole): void
    {
        $this->actingAs($this->user($jobRole))->get('/clients')->assertOk();
    }

    #[DataProvider('oversightRoles')]
    public function test_the_follow_up_list_opens_for_the_people_who_oversee(string $jobRole): void
    {
        $this->actingAs($this->user($jobRole))->get('/inquiries')->assertOk();
    }

    public static function oversightRoles(): array
    {
        return [
            'super admin' => ['super_admin'],
            'leader' => ['leader'],
            'supervisor' => ['Supervisor'],
        ];
    }

    public function test_sales_keeps_the_follow_up_list_but_not_the_book(): void
    {
        $sales = $this->user(User::ROLE_SALES);

        $this->actingAs($sales)->get('/inquiries')->assertOk();
        $this->actingAs($sales)->get('/clients')->assertForbidden();
    }

    public function test_the_floor_gets_neither(): void
    {
        $printer = $this->user('printer');

        $this->actingAs($printer)->get('/clients')->assertForbidden();
        $this->actingAs($printer)->get('/inquiries')->assertForbidden();
    }

    // ---- they see everybody's work, not just their own -----------------

    public function test_an_overseer_sees_every_officers_clients_and_follow_ups(): void
    {
        $one = $this->user(User::ROLE_SALES);
        $two = $this->user(User::ROLE_SALES);

        $this->actingAs($one)->post('/inquiries', $this->inquiryPayload())->assertRedirect();
        $this->actingAs($two)->post('/inquiries', $this->inquiryPayload([
            'client_name' => 'Maria', 'client_last_name' => 'Santos',
        ]))->assertRedirect();

        $this->assertSame(2, Client::count());

        foreach (['Supervisor', 'leader', 'super_admin'] as $jobRole) {
            $this->actingAs($this->user($jobRole))->get('/clients')
                ->assertOk()->assertSee('Dela Cruz, Juan')->assertSee('Santos, Maria');

            $this->actingAs($this->user($jobRole))->get('/inquiries')
                ->assertOk()->assertSee('Juan Dela Cruz')->assertSee('Maria Santos');
        }
    }

    public function test_an_officer_still_only_chases_their_own(): void
    {
        $mine = $this->user(User::ROLE_SALES);
        $theirs = $this->user(User::ROLE_SALES);

        // The other officer's inquiry goes in FIRST: saving one flashes the
        // client's name, and a flash left in the session would show up in the
        // page being asserted against and pass for the wrong reason.
        $this->actingAs($theirs)->post('/inquiries', $this->inquiryPayload([
            'client_name' => 'Maria', 'client_last_name' => 'Santos',
        ]))->assertRedirect();
        $this->actingAs($mine)->post('/inquiries', $this->inquiryPayload())->assertRedirect();

        $this->actingAs($mine)->get('/inquiries')
            ->assertOk()->assertSee('Juan Dela Cruz')->assertDontSee('Maria Santos');
    }

    public function test_the_book_shows_who_wrote_the_client_down(): void
    {
        $officer = $this->user(User::ROLE_SALES);
        $this->actingAs($officer)->post('/inquiries', $this->inquiryPayload())->assertRedirect();

        $this->actingAs($this->user('super_admin'))->get('/clients')
            ->assertOk()->assertSee($officer->name);
    }

    // ---- one address box ----------------------------------------------

    public function test_the_inquiry_form_asks_for_one_address(): void
    {
        $this->actingAs($this->user(User::ROLE_SALES))->get('/inquiries/create')
            ->assertOk()
            ->assertSee('name="client_address"', false)
            ->assertDontSee('name="client_office_address"', false)
            ->assertDontSee('name="client_delivery_address"', false);
    }

    public function test_one_address_fills_both_columns(): void
    {
        $this->actingAs($this->user(User::ROLE_SALES))
            ->post('/inquiries', $this->inquiryPayload())->assertRedirect();

        $client = Client::firstOrFail();

        $this->assertSame('12 Rizal St., Angeles City', $client->office_address);
        $this->assertSame('12 Rizal St., Angeles City', $client->delivery_address);
    }

    public function test_the_address_is_still_required(): void
    {
        $this->actingAs($this->user(User::ROLE_SALES))
            ->post('/inquiries', $this->inquiryPayload(['client_address' => '']))
            ->assertInvalid(['client_address']);

        $this->assertSame(0, Client::count());
    }

    public function test_an_order_edit_changes_the_one_address_everywhere(): void
    {
        $sales = $this->user(User::ROLE_SALES);
        $this->actingAs($sales)->post('/inquiries', $this->inquiryPayload())->assertRedirect();

        $this->actingAs($sales)->post('/orders', [
            'inquiry_id' => Inquiry::firstOrFail()->id,
            'order_number' => 'IC2026-09001',
            'due_date' => now()->addWeeks(3)->toDateString(),
            'product_type' => 'round_neck',
            'sizes' => ['M' => 10],
        ])->assertRedirect();

        $order = ProductionOrder::firstOrFail();

        $this->actingAs($sales)->get('/orders/'.$order->id.'/edit')
            ->assertOk()
            ->assertSee('name="client_address"', false)
            ->assertDontSee('name="client_delivery_address"', false);

        $this->actingAs($sales)->post('/orders/'.$order->id, [
            'client_name' => 'Juan',
            'client_last_name' => 'Dela Cruz',
            'client_contact' => '0917-555-1234',
            'client_address' => '99 New St., Cebu City',
            'due_date' => now()->addWeeks(3)->toDateString(),
            'product_type' => 'round_neck',
            'sizes' => ['M' => 10],
        ])->assertRedirect();

        $client = $order->refresh()->client;
        $this->assertSame('99 New St., Cebu City', $client->office_address);
        $this->assertSame('99 New St., Cebu City', $client->delivery_address);

        // And the order sheet prints the address once, not twice.
        $this->actingAs($sales)->get('/orders/'.$order->id)
            ->assertOk()
            ->assertSee('99 New St., Cebu City')
            ->assertDontSee('Delivery address');
    }
}
