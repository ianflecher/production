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
 * A message about a layout has to raise a badge.
 *
 * Without one it is the quietest kind of message in the shop: the artist asks
 * a question and nothing anywhere says it was asked.
 */
class LayoutThreadBadgeTest extends TestCase
{
    use RefreshDatabase;

    private function layout(): array
    {
        $officer = User::factory()->create(['job_role' => User::ROLE_SALES, 'is_active' => true]);
        $artist = User::factory()->create(['job_role' => 'artist', 'is_active' => true]);

        $inquiry = Inquiry::create([
            'client_id' => Client::create([
                'name' => 'Juan', 'last_name' => 'Dela Cruz', 'contact_number' => '0917',
                'office_address' => 'Angeles City', 'delivery_address' => 'Angeles City',
                'created_by' => $officer->id,
            ])->id,
            'created_by' => $officer->id,
            'status' => Inquiry::STATUS_OPEN,
            'layout_status' => Inquiry::LAYOUT_WITH_ARTIST,
            'layout_artist_id' => $artist->id,
            'layout_sent_at' => now()->subDay(),
        ]);

        return [$officer, $artist, $inquiry];
    }

    private function say(User $who, Inquiry $inquiry, string $body): void
    {
        $this->actingAs($who)->post(route('inquiries.messages.store', $inquiry), ['body' => $body])
            ->assertRedirect();
    }

    public function test_the_sidebar_counts_a_layout_message(): void
    {
        [$officer, $artist, $inquiry] = $this->layout();

        $this->assertSame(0, Message::unreadFor($officer->id));

        $this->say($artist, $inquiry, 'Which red should I use?');

        $this->assertSame(1, Message::unreadFor($officer->id), 'the officer is owed an answer');
        $this->assertSame(0, Message::unreadFor($artist->id), 'your own message is not unread');
    }

    public function test_the_row_carries_its_own_count(): void
    {
        [$officer, $artist, $inquiry] = $this->layout();

        $this->say($artist, $inquiry, 'First question.');
        $this->say($artist, $inquiry, 'Second question.');

        $counts = Message::unreadCountsForInquiries($officer, [$inquiry->id]);

        $this->assertSame(2, (int) $counts[$inquiry->id]);
    }

    public function test_opening_the_thread_clears_it(): void
    {
        [$officer, $artist, $inquiry] = $this->layout();

        $this->say($artist, $inquiry, 'Which red should I use?');
        $this->assertSame(1, Message::unreadFor($officer->id));

        $this->actingAs($officer)->get(route('messages.layout', $inquiry))->assertOk();

        $this->assertSame(0, Message::unreadFor($officer->id), 'reading it is reading it');
        $this->assertSame(
            0,
            (int) (Message::unreadCountsForInquiries($officer, [$inquiry->id])[$inquiry->id] ?? 0)
        );
    }

    public function test_a_reply_after_reading_counts_again(): void
    {
        [$officer, $artist, $inquiry] = $this->layout();

        $this->say($artist, $inquiry, 'Which red?');
        $this->actingAs($officer)->get(route('messages.layout', $inquiry))->assertOk();

        // A second later the artist says something else.
        $this->travel(2)->seconds();
        $this->say($artist, $inquiry, 'Never mind, found it.');

        $this->assertSame(1, Message::unreadFor($officer->id));
    }

    public function test_the_badge_shows_on_the_inbox_row(): void
    {
        [$officer, $artist, $inquiry] = $this->layout();

        $this->say($artist, $inquiry, 'Which red should I use?');

        $this->actingAs($officer)->get(route('messages.index'))
            ->assertOk()
            ->assertSee('msg-badge', false);
    }

    public function test_a_stranger_is_owed_nothing(): void
    {
        [, $artist, $inquiry] = $this->layout();
        $other = User::factory()->create(['job_role' => 'artist', 'is_active' => true]);

        $this->say($artist, $inquiry, 'Which red should I use?');

        $this->assertSame(0, Message::unreadFor($other->id),
            'a layout you are not on owes you nothing');
    }

    public function test_the_count_survives_the_job_order_being_written(): void
    {
        [$officer, $artist, $inquiry] = $this->layout();

        $this->say($artist, $inquiry, 'Said before the order existed.');

        $inquiry->update([
            'layout_status' => Inquiry::LAYOUT_APPROVED,
            'layout_submitted_at' => now()->subHour(),
            'layout_approved_at' => now(),
        ]);

        $this->actingAs($officer)->post('/orders', [
            'inquiry_id' => $inquiry->id,
            'order_number' => 'IC2026-09200',
            'due_date' => now()->addWeeks(3)->toDateString(),
            'product_type' => 'round_neck',
            'sizes' => ['M' => 10],
        ])->assertRedirect();

        // It is the order's message now, and still unread — counted once, on
        // the order, not twice across both.
        $this->assertSame(1, Message::unreadFor($officer->id));

        $order = ProductionOrder::firstOrFail();
        $this->actingAs($officer)->get(route('messages.show', $order))->assertOk();

        $this->assertSame(0, Message::unreadFor($officer->id));
    }
}
