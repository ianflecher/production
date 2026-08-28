<?php

namespace Tests\Feature;

use App\Models\Message;
use App\Models\ProductionOrder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Conversations live on a job order. Everyone connected to the order shares the
 * thread; anyone else is shut out entirely.
 */
class MessagingTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $jobRole = 'sewing'): User
    {
        return User::factory()->create(['job_role' => $jobRole, 'is_active' => true]);
    }

    private function order(?User $sales = null): ProductionOrder
    {
        $sales ??= $this->user(User::ROLE_SALES);

        $this->actingAs($sales)->post('/orders', [
            'order_number' => 'IC2026-02222',
            'client_name' => 'Chat Co',
            'client_last_name' => 'Cruz',
            'client_contact' => '0917-000-0000',
            'client_address' => 'Angeles City',
            'due_date' => now()->addWeeks(3)->toDateString(),
            'product_type' => 'round_neck',
            'sizes' => ['M' => 10],
        ]);

        return ProductionOrder::where('order_number', 'IC2026-02222')->firstOrFail();
    }

    /** Put someone on the order by assigning them one of its tasks. */
    private function assignTo(ProductionOrder $order, User $user): void
    {
        $order->tasks()->first()->update(['assigned_to' => $user->id]);
    }

    // ---- Posting -----------------------------------------------------------

    public function test_the_owning_officer_can_post_on_their_order(): void
    {
        $sales = $this->user(User::ROLE_SALES);
        $order = $this->order($sales);

        $this->actingAs($sales)->post("/messages/{$order->id}", ['body' => 'Client wants navy.'])
            ->assertRedirect();

        $this->assertDatabaseHas('messages', [
            'production_order_id' => $order->id,
            'sender_id' => $sales->id,
            'body' => 'Client wants navy.',
        ]);
    }

    public function test_an_assigned_worker_can_post_on_the_order(): void
    {
        $order = $this->order();
        $worker = $this->user();
        $this->assignTo($order, $worker);

        $this->actingAs($worker)->post("/messages/{$order->id}", ['body' => 'Sewing done.'])
            ->assertRedirect();

        $this->assertSame(1, Message::where('production_order_id', $order->id)->count());
    }

    public function test_a_leader_can_post_on_any_order(): void
    {
        $order = $this->order();
        $leader = $this->user(User::ROLE_LEADER);

        $this->actingAs($leader)->post("/messages/{$order->id}", ['body' => 'Prioritise this.'])
            ->assertRedirect();

        $this->assertSame(1, Message::count());
    }

    public function test_someone_not_on_the_order_cannot_post(): void
    {
        $order = $this->order();
        $stranger = $this->user();

        $this->actingAs($stranger)->post("/messages/{$order->id}", ['body' => 'butting in'])
            ->assertForbidden();

        $this->assertSame(0, Message::count());
    }

    public function test_an_empty_message_is_rejected(): void
    {
        $sales = $this->user(User::ROLE_SALES);
        $order = $this->order($sales);

        $this->actingAs($sales)->post("/messages/{$order->id}", ['body' => ''])
            ->assertInvalid(['body']);

        $this->assertSame(0, Message::count());
    }

    // ---- Reading -----------------------------------------------------------

    public function test_everyone_on_the_order_sees_the_same_thread(): void
    {
        $sales = $this->user(User::ROLE_SALES);
        $order = $this->order($sales);
        $worker = $this->user();
        $this->assignTo($order, $worker);

        $this->actingAs($sales)->post("/messages/{$order->id}", ['body' => 'Please start cutting.']);

        // The worker sees what the officer wrote.
        $this->actingAs($worker)->get("/messages/{$order->id}")
            ->assertOk()->assertSee('Please start cutting.');
    }

    public function test_someone_not_on_the_order_cannot_read_the_thread(): void
    {
        $sales = $this->user(User::ROLE_SALES);
        $order = $this->order($sales);
        $this->actingAs($sales)->post("/messages/{$order->id}", ['body' => 'internal note']);

        $this->actingAs($this->user())->get("/messages/{$order->id}")->assertForbidden();
    }

    public function test_a_guest_cannot_reach_messages(): void
    {
        $this->get('/messages')->assertRedirect('/login');
    }

    // ---- Unread ------------------------------------------------------------

    public function test_a_message_is_unread_for_the_others_but_not_the_sender(): void
    {
        $sales = $this->user(User::ROLE_SALES);
        $order = $this->order($sales);
        $worker = $this->user();
        $this->assignTo($order, $worker);

        $this->actingAs($sales)->post("/messages/{$order->id}", ['body' => 'update please']);

        $this->assertSame(1, Message::unreadInOrder($worker->fresh(), $order->id));
        $this->assertSame(0, Message::unreadInOrder($sales->fresh(), $order->id), 'your own message is not unread');
    }

    public function test_opening_the_thread_clears_unread(): void
    {
        $sales = $this->user(User::ROLE_SALES);
        $order = $this->order($sales);
        $worker = $this->user();
        $this->assignTo($order, $worker);

        $this->actingAs($sales)->post("/messages/{$order->id}", ['body' => 'ping']);
        $this->assertSame(1, Message::unreadInOrder($worker->fresh(), $order->id));

        $this->actingAs($worker)->get("/messages/{$order->id}")->assertOk();

        $this->assertSame(0, Message::unreadInOrder($worker->fresh(), $order->id));
    }

    public function test_the_nav_badge_counts_only_threads_you_are_on(): void
    {
        $sales = $this->user(User::ROLE_SALES);
        $order = $this->order($sales);
        $this->actingAs($sales)->post("/messages/{$order->id}", ['body' => 'hello']);

        $stranger = $this->user();
        $this->assertSame(0, Message::unreadFor($stranger->id), 'outsiders must not be counted');

        $worker = $this->user();
        $this->assignTo($order, $worker);
        $this->assertSame(1, Message::unreadFor($worker->id));
    }

    // ---- Inbox -------------------------------------------------------------

    public function test_the_inbox_lists_the_order_thread(): void
    {
        $sales = $this->user(User::ROLE_SALES);
        $order = $this->order($sales);
        $this->actingAs($sales)->post("/messages/{$order->id}", ['body' => 'about the print']);

        $this->actingAs($sales)->get('/messages')
            ->assertOk()
            ->assertSee($order->order_number)
            ->assertSee('about the print');
    }

    public function test_the_inbox_does_not_show_orders_you_are_not_on(): void
    {
        $sales = $this->user(User::ROLE_SALES);
        $order = $this->order($sales);
        $stranger = $this->user();

        // Drop the "order created" flash from the officer's session, otherwise
        // it echoes the order number back on the next page for any user.
        $this->flushSession();

        $this->assertSame([], Message::accessibleOrderIds($stranger)->all());

        $this->actingAs($stranger)->get('/messages')
            ->assertOk()
            ->assertDontSee($order->order_number)
            ->assertSee('not on any job orders');
    }

    public function test_everyone_else_on_the_order_gets_notified(): void
    {
        $sales = $this->user(User::ROLE_SALES);
        $order = $this->order($sales);
        $worker = $this->user();
        $this->assignTo($order, $worker);

        $this->actingAs($sales)->post("/messages/{$order->id}", ['body' => 'heads up']);

        $this->assertDatabaseHas('app_notifications', ['user_id' => $worker->id]);
        $this->assertDatabaseMissing('app_notifications', ['user_id' => $sales->id]);
    }
}
