<?php

namespace Tests\Feature;

use App\Models\Message;
use App\Models\ProductionOrder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** @mentions inside a job order conversation. */
class MessageMentionTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $name, string $jobRole = 'sewing'): User
    {
        return User::factory()->create(['name' => $name, 'job_role' => $jobRole, 'is_active' => true]);
    }

    private function order(User $sales): ProductionOrder
    {
        $this->actingAs($sales)->post('/orders', [
            'order_number' => 'IC2026-03333',
            'client_name' => 'Mention Co',
            'client_last_name' => 'Cruz',
            'client_contact' => '0917-000-0000',
            'client_address' => 'Angeles City',
            'due_date' => now()->addWeeks(3)->toDateString(),
            'product_type' => 'round_neck',
            'sizes' => ['M' => 10],
        ]);

        return ProductionOrder::where('order_number', 'IC2026-03333')->firstOrFail();
    }

    public function test_mentioning_someone_records_the_mention(): void
    {
        $sales = $this->user('Paula', User::ROLE_SALES);
        $order = $this->order($sales);
        $worker = $this->user('Geneline');
        $order->tasks()->first()->update(['assigned_to' => $worker->id]);

        $this->actingAs($sales)->post("/messages/{$order->id}", [
            'body' => '@Geneline can you start the sewing?',
        ])->assertRedirect();

        $message = Message::first();
        $this->assertTrue($message->mentions->contains('id', $worker->id));
    }

    public function test_a_mention_notification_says_you_were_mentioned(): void
    {
        $sales = $this->user('Paula', User::ROLE_SALES);
        $order = $this->order($sales);
        $worker = $this->user('Geneline');
        $order->tasks()->first()->update(['assigned_to' => $worker->id]);

        $this->actingAs($sales)->post("/messages/{$order->id}", ['body' => '@Geneline please check']);

        $this->assertDatabaseHas('app_notifications', [
            'user_id' => $worker->id,
            'title' => 'Paula mentioned you on IC2026-03333',
        ]);
    }

    public function test_someone_not_mentioned_gets_the_ordinary_notification(): void
    {
        $sales = $this->user('Paula', User::ROLE_SALES);
        $order = $this->order($sales);
        $worker = $this->user('Geneline');
        $order->tasks()->first()->update(['assigned_to' => $worker->id]);

        $this->actingAs($sales)->post("/messages/{$order->id}", ['body' => 'general update']);

        $this->assertDatabaseHas('app_notifications', [
            'user_id' => $worker->id,
            'title' => 'Paula on IC2026-03333',
        ]);
    }

    public function test_you_cannot_mention_someone_who_is_not_in_the_conversation(): void
    {
        $sales = $this->user('Paula', User::ROLE_SALES);
        $order = $this->order($sales);
        $outsider = $this->user('Nobody');

        $this->actingAs($sales)->post("/messages/{$order->id}", ['body' => '@Nobody hello']);

        $this->assertSame(0, Message::first()->mentions()->count());
        $this->assertDatabaseMissing('app_notifications', ['user_id' => $outsider->id]);
    }

    public function test_a_longer_name_wins_over_a_shorter_one_inside_it(): void
    {
        $sales = $this->user('Maam Carla', User::ROLE_LEADER);
        $order = $this->order($this->user('Paula', User::ROLE_SALES));
        $short = $this->user('Maam', User::ROLE_LEADER);

        $ids = Message::detectMentions('@Maam Carla please review', collect([$sales, $short]));

        $this->assertSame([$sales->id], $ids, 'the full name should match, not the shorter one inside it');
    }

    public function test_mentions_are_highlighted_in_the_thread(): void
    {
        $sales = $this->user('Paula', User::ROLE_SALES);
        $order = $this->order($sales);
        $worker = $this->user('Geneline');
        $order->tasks()->first()->update(['assigned_to' => $worker->id]);

        $this->actingAs($sales)->post("/messages/{$order->id}", ['body' => '@Geneline start please']);

        $this->actingAs($sales)->get("/messages/{$order->id}")
            ->assertOk()
            ->assertSee('<span class="mention">@Geneline</span>', false);
    }

    public function test_a_message_cannot_inject_html(): void
    {
        $sales = $this->user('Paula', User::ROLE_SALES);
        $order = $this->order($sales);

        $this->actingAs($sales)->post("/messages/{$order->id}", [
            'body' => '<script>alert(1)</script> hi',
        ]);

        $this->actingAs($sales)->get("/messages/{$order->id}")
            ->assertOk()
            ->assertDontSee('<script>alert(1)</script>', false)
            ->assertSee('&lt;script&gt;', false);
    }

    public function test_mention_matching_ignores_case(): void
    {
        $sales = $this->user('Paula', User::ROLE_SALES);
        $order = $this->order($sales);
        $worker = $this->user('Geneline');
        $order->tasks()->first()->update(['assigned_to' => $worker->id]);

        $this->actingAs($sales)->post("/messages/{$order->id}", ['body' => 'hey @geneline']);

        $this->assertTrue(Message::first()->mentions->contains('id', $worker->id));
    }
}
