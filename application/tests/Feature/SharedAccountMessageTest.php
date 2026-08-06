<?php

namespace Tests\Feature;

use App\Models\Message;
use App\Models\ProductionOrder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Several movers walk the floor under one login, the way the machine stations
 * are shared. A message signed "Mover" tells nobody who asked, so whoever is
 * typing signs their own name — and that is also what puts a name in the
 * Mover row of the job order, since a mover closes no step to be read off.
 */
class SharedAccountMessageTest extends TestCase
{
    use RefreshDatabase;

    private function mover(): User
    {
        return User::factory()->create(['job_role' => 'Mover', 'name' => 'Mover', 'is_active' => true]);
    }

    private function order(): ProductionOrder
    {
        $sales = User::factory()->create(['job_role' => User::ROLE_SALES, 'is_active' => true]);

        return ProductionOrder::create([
            'order_number' => 'IC2026-05500',
            'customer_name' => 'Shared Co',
            'product_type' => 'round_neck',
            'quantity' => 10,
            'due_date' => now()->addWeeks(2),
            'status' => 'active',
            'created_by' => $sales->id,
        ]);
    }

    public function test_the_mover_account_is_known_to_be_shared(): void
    {
        $this->assertTrue($this->mover()->sharesAccount());

        $artist = User::factory()->create(['job_role' => User::JOB_ARTIST, 'is_active' => true]);
        $this->assertFalse($artist->sharesAccount(), 'a personal login needs no name typed');
    }

    public function test_a_message_needs_a_name_before_it_can_be_sent(): void
    {
        $order = $this->order();

        $this->actingAs($this->mover())
            ->post("/messages/{$order->id}", ['body' => 'why is this one taking so long?'])
            ->assertSessionHasErrors('sender_name');

        $this->assertSame(0, Message::count(), 'an unsigned message must not be posted');
    }

    public function test_the_message_carries_the_name_that_was_typed(): void
    {
        $order = $this->order();

        $this->actingAs($this->mover())->post("/messages/{$order->id}", [
            'sender_name' => 'Louiza',
            'body' => 'why is this one taking so long?',
        ])->assertRedirect();

        $message = Message::firstOrFail();
        $this->assertSame('Louiza', $message->sender_name);
        $this->assertSame('Louiza (Mover)', $message->senderLabel());
    }

    public function test_the_name_is_remembered_for_the_shift(): void
    {
        $order = $this->order();
        $mover = $this->mover();

        $this->actingAs($mover)->post("/messages/{$order->id}", [
            'sender_name' => 'Louiza', 'body' => 'first',
        ]);

        // Typed once, then offered back — nobody wants to retype it every time.
        $this->actingAs($mover)->get("/messages/{$order->id}")
            ->assertOk()
            ->assertSee('value="Louiza"', false);
    }

    public function test_two_movers_on_one_login_stay_apart(): void
    {
        $order = $this->order();
        $mover = $this->mover();

        $this->actingAs($mover)->post("/messages/{$order->id}", ['sender_name' => 'Louiza', 'body' => 'chasing the print']);
        $this->actingAs($mover)->post("/messages/{$order->id}", ['sender_name' => 'Carla', 'body' => 'sewing says tomorrow']);

        $this->assertSame(
            ['Louiza', 'Carla'],
            Message::orderBy('id')->pluck('sender_name')->all()
        );
    }

    public function test_a_personal_login_is_not_asked_for_a_name(): void
    {
        $order = $this->order();
        $artist = User::factory()->create(['job_role' => User::JOB_ARTIST, 'name' => 'Maru', 'is_active' => true]);
        $order->tasks()->create([
            'sequence' => 1, 'stage' => 1, 'department' => 'Layout',
            'status' => 'ready', 'approver_role' => 'leader', 'assigned_to' => $artist->id,
        ]);

        $this->actingAs($artist)->post("/messages/{$order->id}", ['body' => 'layout is up'])
            ->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame('Maru', Message::firstOrFail()->senderLabel());
    }

    // ---- The job order sheet ----------------------------------------------

    public function test_the_job_order_names_the_movers_who_followed_it(): void
    {
        $order = $this->order();
        $mover = $this->mover();

        $this->actingAs($mover)->post("/messages/{$order->id}", ['sender_name' => 'Louiza', 'body' => 'chasing']);
        $this->actingAs($mover)->post("/messages/{$order->id}", ['sender_name' => 'Carla', 'body' => 'chasing too']);
        $this->actingAs($mover)->post("/messages/{$order->id}", ['sender_name' => 'Louiza', 'body' => 'again']);

        // Each name once, in the order they first spoke.
        $this->assertSame('Louiza, Carla', $order->fresh()->moverNames());
    }

    public function test_nobody_else_lands_in_the_mover_row(): void
    {
        $order = $this->order();
        $sales = User::find($order->created_by);

        $this->actingAs($sales)->post("/messages/{$order->id}", ['body' => 'client rang']);

        $this->assertSame('', $order->fresh()->moverNames(), 'only movers belong in that row');
    }

    public function test_an_untouched_job_has_an_empty_mover_row(): void
    {
        $this->assertSame('', $this->order()->moverNames());
    }
}
