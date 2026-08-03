<?php

namespace Tests\Feature;

use App\Models\Message;
use App\Models\ProductionOrder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Staff-to-staff direct messages, and the job orders they can carry. */
class MessagingTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $jobRole = 'sewing'): User
    {
        return User::factory()->create(['job_role' => $jobRole, 'is_active' => true]);
    }

    private function orderOwnedBy(User $sales): ProductionOrder
    {
        $this->actingAs($sales)->post('/orders', [
            'order_number' => 'IC2026-02222',
            'client_name' => 'Chat Co',
            'due_date' => now()->addWeeks(3)->toDateString(),
            'product_type' => 'round_neck',
            'sizes' => ['M' => 10],
        ]);

        return ProductionOrder::where('order_number', 'IC2026-02222')->firstOrFail();
    }

    // ---- Sending -----------------------------------------------------------

    public function test_a_user_can_message_another_user(): void
    {
        $me = $this->user();
        $them = $this->user();

        $this->actingAs($me)->post('/messages', [
            'recipient_id' => $them->id,
            'body' => 'Is the sewing done?',
        ])->assertRedirect();

        $this->assertDatabaseHas('messages', [
            'sender_id' => $me->id,
            'recipient_id' => $them->id,
            'body' => 'Is the sewing done?',
        ]);
    }

    public function test_an_empty_message_is_rejected(): void
    {
        $me = $this->user();

        $this->actingAs($me)->post('/messages', [
            'recipient_id' => $this->user()->id,
        ])->assertInvalid(['body']);

        $this->assertSame(0, Message::count());
    }

    public function test_you_cannot_message_yourself(): void
    {
        $me = $this->user();

        $this->actingAs($me)->post('/messages', [
            'recipient_id' => $me->id,
            'body' => 'hello me',
        ])->assertStatus(422);

        $this->assertSame(0, Message::count());
    }

    public function test_the_recipient_gets_a_notification(): void
    {
        $me = $this->user();
        $them = $this->user();

        $this->actingAs($me)->post('/messages', ['recipient_id' => $them->id, 'body' => 'ping']);

        $this->assertDatabaseHas('app_notifications', ['user_id' => $them->id]);
    }

    // ---- Reading -----------------------------------------------------------

    public function test_opening_a_conversation_marks_their_messages_read(): void
    {
        $me = $this->user();
        $them = $this->user();

        $this->actingAs($them)->post('/messages', ['recipient_id' => $me->id, 'body' => 'yo']);
        $this->assertSame(1, Message::unreadFor($me->id));

        $this->actingAs($me)->get("/messages/{$them->id}")->assertOk();

        $this->assertSame(0, Message::unreadFor($me->id), 'opening the chat should clear unread');
    }

    public function test_the_inbox_lists_a_conversation(): void
    {
        $me = $this->user();
        $them = $this->user();
        $this->actingAs($them)->post('/messages', ['recipient_id' => $me->id, 'body' => 'about the order']);

        $this->actingAs($me)->get('/messages')->assertOk()->assertSee('about the order');
    }

    public function test_a_third_party_cannot_read_someone_elses_conversation(): void
    {
        $a = $this->user();
        $b = $this->user();
        $this->actingAs($a)->post('/messages', ['recipient_id' => $b->id, 'body' => 'private stuff']);

        // A third person opening a chat with B sees their OWN empty thread.
        $c = $this->user();
        $this->actingAs($c)->get("/messages/{$b->id}")->assertOk()->assertDontSee('private stuff');
    }

    public function test_a_guest_cannot_reach_messages(): void
    {
        $this->get('/messages')->assertRedirect('/login');
    }

    // ---- Attaching a job order --------------------------------------------

    public function test_an_officer_can_send_their_own_order(): void
    {
        $sales = $this->user(User::ROLE_SALES);
        $order = $this->orderOwnedBy($sales);
        $mate = $this->user();

        $this->actingAs($sales)->post('/messages', [
            'recipient_id' => $mate->id,
            'production_order_id' => $order->id,
        ])->assertRedirect();

        $this->assertDatabaseHas('messages', [
            'sender_id' => $sales->id,
            'production_order_id' => $order->id,
        ]);
    }

    public function test_an_order_alone_is_a_valid_message(): void
    {
        $sales = $this->user(User::ROLE_SALES);
        $order = $this->orderOwnedBy($sales);

        $this->actingAs($sales)->post('/messages', [
            'recipient_id' => $this->user()->id,
            'production_order_id' => $order->id,
        ])->assertRedirect();

        $this->assertNull(Message::first()->body);
    }

    public function test_you_cannot_send_an_order_you_are_not_on(): void
    {
        $owner = $this->user(User::ROLE_SALES);
        $order = $this->orderOwnedBy($owner);

        // A different officer has no claim on that order.
        $outsider = $this->user(User::ROLE_SALES);

        $this->actingAs($outsider)->post('/messages', [
            'recipient_id' => $this->user()->id,
            'production_order_id' => $order->id,
        ])->assertForbidden();

        $this->assertSame(0, Message::count());
    }

    public function test_the_recipient_can_open_the_order_when_they_are_on_it(): void
    {
        $sales = $this->user(User::ROLE_SALES);
        $order = $this->orderOwnedBy($sales);

        $worker = $this->user();
        $order->tasks()->first()->update(['assigned_to' => $worker->id]);

        $this->actingAs($sales)->post('/messages', [
            'recipient_id' => $worker->id,
            'production_order_id' => $order->id,
        ]);

        $this->assertTrue(Message::first()->canSeeOrder($worker->fresh()));
    }

    public function test_a_recipient_not_on_the_order_cannot_open_it(): void
    {
        $sales = $this->user(User::ROLE_SALES);
        $order = $this->orderOwnedBy($sales);
        $stranger = $this->user();

        $this->actingAs($sales)->post('/messages', [
            'recipient_id' => $stranger->id,
            'production_order_id' => $order->id,
        ]);

        $message = Message::first();
        $this->assertFalse($message->canSeeOrder($stranger));

        // The thread says so rather than exposing the order.
        $this->actingAs($stranger)->get("/messages/{$sales->id}")
            ->assertOk()
            ->assertSee('cannot be opened');
    }

    public function test_a_leader_can_open_any_attached_order(): void
    {
        $sales = $this->user(User::ROLE_SALES);
        $order = $this->orderOwnedBy($sales);
        $leader = $this->user(User::ROLE_LEADER);

        $this->actingAs($sales)->post('/messages', [
            'recipient_id' => $leader->id,
            'production_order_id' => $order->id,
        ]);

        $this->assertTrue(Message::first()->canSeeOrder($leader));
    }
}
