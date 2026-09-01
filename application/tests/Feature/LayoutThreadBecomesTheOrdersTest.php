<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Inquiry;
use App\Models\Message;
use App\Models\ProductionOrder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A layout can be talked about before there is a job order, and what was said
 * becomes the job order's thread the moment it exists.
 */
class LayoutThreadBecomesTheOrdersTest extends TestCase
{
    use RefreshDatabase;

    private function layout(): array
    {
        $officer = User::factory()->create(['job_role' => User::ROLE_SALES, 'is_active' => true]);
        $artist = User::factory()->create(['job_role' => 'artist', 'is_active' => true]);

        $client = Client::create([
            'name' => 'Juan', 'last_name' => 'Dela Cruz', 'contact_number' => '0917',
            'office_address' => 'Angeles City', 'delivery_address' => 'Angeles City',
            'created_by' => $officer->id,
        ]);

        $inquiry = Inquiry::create([
            'client_id' => $client->id,
            'created_by' => $officer->id,
            'status' => Inquiry::STATUS_OPEN,
            'layout_status' => Inquiry::LAYOUT_WITH_ARTIST,
            'layout_artist_id' => $artist->id,
            'layout_sent_at' => now()->subDay(),
        ]);

        return [$officer, $artist, $inquiry];
    }

    public function test_the_officer_and_the_artist_can_talk_before_the_order_exists(): void
    {
        [$officer, $artist, $inquiry] = $this->layout();

        $this->actingAs($officer)->post(route('inquiries.messages.store', $inquiry), [
            'body' => 'Client wants the logo bigger — can you try it?',
        ])->assertRedirect();

        $this->actingAs($artist)->post(route('inquiries.messages.store', $inquiry), [
            'body' => 'Sending it in an hour.',
        ])->assertRedirect();

        $this->assertSame(2, $inquiry->messages()->count());
        $this->assertNull(Message::first()->production_order_id, 'there is no order yet');
    }

    public function test_the_layout_thread_is_listed_in_messages(): void
    {
        [$officer, $artist, $inquiry] = $this->layout();

        $this->actingAs($officer)->post(route('inquiries.messages.store', $inquiry), [
            'body' => 'Use the darker red please.',
        ])->assertRedirect();

        foreach ([$officer, $artist] as $person) {
            $this->actingAs($person)->get(route('messages.index'))
                ->assertOk()
                ->assertSee('Layouts being drawn')
                ->assertSee('Use the darker red please.');
        }
    }

    public function test_both_sides_can_open_the_thread_from_messages(): void
    {
        [$officer, $artist, $inquiry] = $this->layout();

        $this->actingAs($artist)->post(route('inquiries.messages.store', $inquiry), [
            'body' => 'Which font for the back?',
        ])->assertRedirect();

        $this->actingAs($officer)->get(route('messages.layout', $inquiry))
            ->assertOk()
            ->assertSee('Which font for the back?')
            ->assertSee('no job order yet');

        $this->actingAs($artist)->get(route('messages.layout', $inquiry))
            ->assertOk()
            ->assertSee('Which font for the back?');
    }

    public function test_the_thread_is_not_on_the_layout_pages_any_more(): void
    {
        [$officer, $artist, $inquiry] = $this->layout();

        $this->actingAs($officer)->post(route('inquiries.messages.store', $inquiry), [
            'body' => 'Said in Messages, not on the layout page.',
        ])->assertRedirect();

        $this->actingAs($officer)->get(route('inquiries.layout', $inquiry))
            ->assertOk()->assertDontSee('Said in Messages, not on the layout page.');

        $this->actingAs($artist)->get(route('inquiries.layouts'))
            ->assertOk()->assertDontSee('Said in Messages, not on the layout page.');
    }

    public function test_a_stranger_cannot_open_the_thread_page(): void
    {
        [, , $inquiry] = $this->layout();
        $other = User::factory()->create(['job_role' => 'artist', 'is_active' => true]);

        $this->actingAs($other)->get(route('messages.layout', $inquiry))->assertForbidden();
    }

    public function test_a_layout_leaves_the_inbox_once_it_has_a_job_order(): void
    {
        [$officer, , $inquiry] = $this->layout();

        $this->actingAs($officer)->post(route('inquiries.messages.store', $inquiry), [
            'body' => 'Before the order.',
        ])->assertRedirect();

        $inquiry->update([
            'layout_status' => Inquiry::LAYOUT_APPROVED,
            'layout_submitted_at' => now()->subHour(),
            'layout_approved_at' => now(),
        ]);

        $this->actingAs($officer)->post('/orders', [
            'inquiry_id' => $inquiry->id,
            'order_number' => 'IC2026-09103',
            'due_date' => now()->addWeeks(3)->toDateString(),
            'product_type' => 'round_neck',
            'sizes' => ['M' => 10],
        ])->assertRedirect();

        // It is a job order row now, not a layout row — the same conversation
        // in the place it belongs.
        $this->actingAs($officer)->get(route('messages.index'))
            ->assertOk()
            ->assertDontSee('Layouts being drawn')
            ->assertSee('Before the order.');
    }

    public function test_a_stranger_cannot_read_or_post(): void
    {
        [, , $inquiry] = $this->layout();
        $otherArtist = User::factory()->create(['job_role' => 'artist', 'is_active' => true]);

        $this->actingAs($otherArtist)->post(route('inquiries.messages.store', $inquiry), [
            'body' => 'butting in',
        ])->assertForbidden();

        $this->assertSame(0, Message::count());
    }

    public function test_a_leader_can_join_in(): void
    {
        [, , $inquiry] = $this->layout();
        $leader = User::factory()->create(['job_role' => 'leader', 'is_active' => true]);

        $this->actingAs($leader)->post(route('inquiries.messages.store', $inquiry), [
            'body' => 'Priority job, please get on it today.',
        ])->assertRedirect();

        $this->assertSame(1, $inquiry->messages()->count());
    }

    public function test_an_empty_message_is_refused(): void
    {
        [$officer, , $inquiry] = $this->layout();

        $this->actingAs($officer)->post(route('inquiries.messages.store', $inquiry), ['body' => ''])
            ->assertInvalid(['body']);

        $this->assertSame(0, Message::count());
    }

    public function test_writing_the_job_order_carries_the_whole_conversation_over(): void
    {
        [$officer, $artist, $inquiry] = $this->layout();

        $this->actingAs($officer)->post(route('inquiries.messages.store', $inquiry), [
            'body' => 'Client wants the logo bigger.',
        ])->assertRedirect();
        $this->actingAs($artist)->post(route('inquiries.messages.store', $inquiry), [
            'body' => 'Done, sending now.',
        ])->assertRedirect();

        // The client approves and the job order is written from the inquiry.
        $inquiry->update([
            'layout_status' => Inquiry::LAYOUT_APPROVED,
            'layout_submitted_at' => now()->subHour(),
            'layout_approved_at' => now(),
        ]);

        $this->actingAs($officer)->post('/orders', [
            'inquiry_id' => $inquiry->id,
            'order_number' => 'IC2026-09100',
            'due_date' => now()->addWeeks(3)->toDateString(),
            'product_type' => 'round_neck',
            'sizes' => ['M' => 10],
        ])->assertRedirect();

        $order = ProductionOrder::firstOrFail();

        // Both messages now belong to the order's thread…
        $this->assertSame(2, $order->messages()->count());

        // …and still remember they were said about the layout.
        $this->assertSame(2, Message::where('inquiry_id', $inquiry->id)->count());

        // The order's own thread page shows them, with nothing retyped.
        $this->actingAs($officer)->get(route('messages.show', $order))
            ->assertOk()
            ->assertSee('Client wants the logo bigger.')
            ->assertSee('Done, sending now.');
    }

    public function test_the_thread_is_carried_once_not_duplicated(): void
    {
        [$officer, , $inquiry] = $this->layout();

        $this->actingAs($officer)->post(route('inquiries.messages.store', $inquiry), [
            'body' => 'One message.',
        ])->assertRedirect();

        $inquiry->update([
            'layout_status' => Inquiry::LAYOUT_APPROVED,
            'layout_submitted_at' => now()->subHour(),
            'layout_approved_at' => now(),
        ]);

        $this->actingAs($officer)->post('/orders', [
            'inquiry_id' => $inquiry->id,
            'order_number' => 'IC2026-09101',
            'due_date' => now()->addWeeks(3)->toDateString(),
            'product_type' => 'round_neck',
            'sizes' => ['M' => 10],
        ])->assertRedirect();

        $order = ProductionOrder::firstOrFail();

        // Running the carry-over again changes nothing: the message already
        // has its order and is no longer looking for one.
        $this->assertSame(0, Message::carryLayoutThreadTo($inquiry->refresh(), $order));
        $this->assertSame(1, Message::count());
    }

    public function test_a_message_sent_after_the_order_exists_still_lands_on_it(): void
    {
        [$officer, , $inquiry] = $this->layout();

        $inquiry->update([
            'layout_status' => Inquiry::LAYOUT_APPROVED,
            'layout_submitted_at' => now()->subHour(),
            'layout_approved_at' => now(),
        ]);

        $this->actingAs($officer)->post('/orders', [
            'inquiry_id' => $inquiry->id,
            'order_number' => 'IC2026-09102',
            'due_date' => now()->addWeeks(3)->toDateString(),
            'product_type' => 'round_neck',
            'sizes' => ['M' => 10],
        ])->assertRedirect();

        $order = ProductionOrder::firstOrFail();

        $this->actingAs($officer)->post(route('inquiries.messages.store', $inquiry->refresh()), [
            'body' => 'Late thought about the collar.',
        ])->assertRedirect();

        $this->assertSame(1, $order->messages()->count(),
            'once the order exists a layout message goes straight onto it');
    }
}
