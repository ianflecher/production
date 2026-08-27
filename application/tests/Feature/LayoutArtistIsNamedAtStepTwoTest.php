<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Inquiry;
use App\Models\ProductionOrder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The officer is told which artist has the layout, and it is true.
 *
 * The artist used to be picked when the job order was written, one page after
 * the officer had already sent the brief — so on the page where you hand the
 * work over there was nobody to name. Naming one there and then letting the
 * rotation choose somebody else at the order would be worse than naming none,
 * so the person shown on step 2 is the person the task is handed to.
 */
class LayoutArtistIsNamedAtStepTwoTest extends TestCase
{
    use RefreshDatabase;

    private function officer(): User
    {
        return User::factory()->create(['job_role' => User::ROLE_SALES, 'is_active' => true]);
    }

    private function artist(string $name): User
    {
        $artist = User::factory()->create([
            'job_role' => User::JOB_ARTIST, 'name' => $name, 'is_active' => true,
        ]);

        // The rotation only hands work to whoever is in today.
        $artist->attendances()->create(['date' => now()->toDateString(), 'status' => 'present']);

        return $artist;
    }

    private function inquiryOf(User $officer): Inquiry
    {
        return Inquiry::create([
            'client_id' => Client::create(['name' => 'Mike', 'last_name' => 'Calaramo'])->id,
            'created_by' => $officer->id,
            'team' => $officer->team,
            'status' => Inquiry::STATUS_OPEN,
        ]);
    }

    public function test_sending_the_brief_names_an_artist(): void
    {
        $officer = $this->officer();
        $artist = $this->artist('Maru');
        $inquiry = $this->inquiryOf($officer);

        $this->actingAs($officer)->post(route('inquiries.layout.complete', $inquiry), [
            'reference_note' => 'Keep the team colours',
        ])->assertSessionHasNoErrors();

        $inquiry->refresh();

        $this->assertSame($artist->id, $inquiry->layout_artist_id);
        $this->assertSame(Inquiry::LAYOUT_WITH_ARTIST, $inquiry->layout_status);
        $this->assertNotNull($inquiry->layout_sent_at);
    }

    public function test_that_same_artist_gets_the_layout_task(): void
    {
        $officer = $this->officer();
        $named = $this->artist('Maru');
        // A second artist, free and present, so the rotation has a choice to
        // make and could pick the other one.
        $this->artist('Mick');

        $inquiry = $this->inquiryOf($officer);

        $this->actingAs($officer)->post(route('inquiries.layout.complete', $inquiry), [
            'reference_note' => 'Keep the team colours',
        ]);

        $this->assertSame($named->id, $inquiry->fresh()->layout_artist_id);

        $this->actingAs($officer)->post(route('orders.store'), [
            'inquiry_id' => $inquiry->id,
            'order_number' => 'IC2026-L001',
            'product_type' => 'round_neck',
            'quantity' => 10,
            'sizes' => ['M' => 10],
            'due_date' => now()->addWeeks(3)->toDateString(),
        ])->assertSessionHasNoErrors();

        $layout = ProductionOrder::firstOrFail()
            ->tasks()->where('stage', ProductionOrder::STAGE_LAYOUT)->firstOrFail();

        $this->assertSame($named->id, $layout->assigned_to,
            'the artist promised on step 2 must be the one holding the task');
    }

    public function test_the_page_says_who_has_it(): void
    {
        $officer = $this->officer();
        $this->artist('Maru');
        $inquiry = $this->inquiryOf($officer);

        $this->actingAs($officer)->post(route('inquiries.layout.complete', $inquiry), [
            'reference_note' => 'Keep the team colours',
        ]);

        $this->actingAs($officer)->get(route('inquiries.layout', $inquiry))
            ->assertOk()
            ->assertSee('Maru', false)
            ->assertSee('has the layout', false);
    }

    public function test_once_sent_you_wait_on_the_artist(): void
    {
        $officer = $this->officer();
        $this->artist('Maru');
        $inquiry = $this->inquiryOf($officer);

        $this->actingAs($officer)->post(route('inquiries.layout.complete', $inquiry), [
            'reference_note' => 'Keep the team colours',
        ]);

        $this->actingAs($officer)->get(route('inquiries.layout', $inquiry))
            ->assertOk()
            ->assertSee('Waiting on Maru', false)
            ->assertDontSee('Send to artist for layout', false)
            ->assertDontSee('Create the job order', false);
    }

    /** The artist draws it, the officer approves it, and only then the order. */
    public function test_the_job_order_opens_only_once_the_layout_is_approved(): void
    {
        Storage::fake('local');

        $officer = $this->officer();
        $artist = $this->artist('Maru');
        $inquiry = $this->inquiryOf($officer);

        $this->actingAs($officer)->post(route('inquiries.layout.complete', $inquiry), [
            'reference_note' => 'Keep the team colours',
        ]);

        // Still with the artist: typing the URL gets you sent back.
        $this->actingAs($officer)->get(route('orders.create', ['inquiry' => $inquiry->id]))
            ->assertRedirect(route('inquiries.layout', $inquiry));

        $this->actingAs($artist)->post(route('inquiries.layout.submit', $inquiry), [
            'layout_files' => [UploadedFile::fake()->image('layout.png')],
        ])->assertSessionHasNoErrors();

        $this->assertSame(Inquiry::LAYOUT_SUBMITTED, $inquiry->fresh()->layout_status);

        // Drawn, but the client has not said yes yet.
        $this->actingAs($officer)->get(route('orders.create', ['inquiry' => $inquiry->id]))
            ->assertRedirect(route('inquiries.layout', $inquiry));

        $this->actingAs($officer)->post(route('inquiries.layout.approve', $inquiry))
            ->assertRedirect(route('orders.create', ['inquiry' => $inquiry->id]));

        $this->assertTrue($inquiry->fresh()->layoutApproved());
        $this->actingAs($officer)->get(route('orders.create', ['inquiry' => $inquiry->id]))->assertOk();
    }

    public function test_an_approved_layout_lands_on_the_order_already_done(): void
    {
        Storage::fake('local');

        $officer = $this->officer();
        $artist = $this->artist('Maru');
        $inquiry = $this->inquiryOf($officer);

        $this->actingAs($officer)->post(route('inquiries.layout.complete', $inquiry), ['reference_note' => 'x']);
        $this->actingAs($artist)->post(route('inquiries.layout.submit', $inquiry), [
            'layout_files' => [UploadedFile::fake()->image('layout.png')],
        ]);
        $this->actingAs($officer)->post(route('inquiries.layout.approve', $inquiry));

        $this->actingAs($officer)->post(route('orders.store'), [
            'inquiry_id' => $inquiry->id,
            'order_number' => 'IC2026-L002',
            'product_type' => 'round_neck',
            'quantity' => 10,
            'sizes' => ['M' => 10],
            'due_date' => now()->addWeeks(3)->toDateString(),
        ])->assertSessionHasNoErrors();

        $order = ProductionOrder::firstOrFail();
        $layout = $order->tasks()->where('stage', ProductionOrder::STAGE_LAYOUT)->firstOrFail();

        // Drawn and approved before the order existed. Leaving it READY put
        // finished work back on the artist's board and stalled the pipeline on
        // its first line.
        $this->assertSame('complete', $layout->status);
        $this->assertNotNull($layout->approved_at);
        $this->assertTrue($order->fresh()->layoutApproved());

        // And the job is no longer SITTING on the layout: the artist's card
        // said "Current task: 1. Layout — ready to start" for work they had
        // already handed in.
        $sittingOn = $order->fresh()->tasks()
            ->whereNotIn('status', ['complete', 'cancelled'])
            ->orderBy('sequence')
            ->first();

        $this->assertNotSame(ProductionOrder::STAGE_LAYOUT, $sittingOn?->stage,
            'the order should have moved past the layout');
    }

    public function test_the_artist_sees_it_in_their_queue_and_nobody_elses(): void
    {
        $officer = $this->officer();
        $mine = $this->artist('Maru');
        $other = $this->artist('Mick');
        $inquiry = $this->inquiryOf($officer);

        $this->actingAs($officer)->post(route('inquiries.layout.complete', $inquiry), [
            'reference_note' => 'Keep the team colours',
        ]);

        $holder = $inquiry->fresh()->layout_artist_id === $mine->id ? $mine : $other;
        $notHolder = $holder->is($mine) ? $other : $mine;

        $this->actingAs($holder)->get(route('inquiries.layouts'))
            ->assertOk()->assertSee('Mike Calaramo', false);

        $this->actingAs($notHolder)->get(route('inquiries.layouts'))
            ->assertOk()->assertDontSee('Mike Calaramo', false);
    }

    public function test_the_artist_can_open_the_reference_they_are_drawing_from(): void
    {
        Storage::fake('local');

        $officer = $this->officer();
        $artist = $this->artist('Maru');
        $inquiry = $this->inquiryOf($officer);

        $this->actingAs($officer)->post(route('inquiries.layout.upload', $inquiry), [
            'reference_files' => [UploadedFile::fake()->image('artemis.png')],
        ])->assertSessionHasNoErrors();

        $this->actingAs($officer)->post(route('inquiries.layout.complete', $inquiry), ['reference_note' => 'x']);

        // The thumbnail on the artist's queue is this URL. A 403 here is a
        // broken image on the page they work from.
        $this->actingAs($artist)
            ->get(route('inquiries.layout.file', [$inquiry, 'index' => 0]))
            ->assertOk();

        // Somebody else's artist still cannot.
        $stranger = $this->artist('Mick');

        $this->actingAs($stranger)
            ->get(route('inquiries.layout.file', [$inquiry, 'index' => 0]))
            ->assertForbidden();
    }

    public function test_the_client_can_ask_for_changes_and_it_goes_back(): void
    {
        Storage::fake('local');

        $officer = $this->officer();
        $artist = $this->artist('Maru');
        $inquiry = $this->inquiryOf($officer);

        $this->actingAs($officer)->post(route('inquiries.layout.complete', $inquiry), ['reference_note' => 'x']);
        $this->actingAs($artist)->post(route('inquiries.layout.submit', $inquiry), [
            'layout_files' => [UploadedFile::fake()->image('layout.png')],
        ]);

        $this->actingAs($officer)->post(route('inquiries.layout.revise', $inquiry), [
            'layout_revision_note' => 'Client wants the logo bigger',
        ])->assertSessionHasNoErrors();

        $inquiry->refresh();

        $this->assertSame(Inquiry::LAYOUT_WITH_ARTIST, $inquiry->layout_status);
        $this->assertSame('Client wants the logo bigger', $inquiry->layout_revision_note);
        $this->assertSame($artist->id, $inquiry->layout_artist_id, 'the same artist redraws it');

        // And the order is shut again until it comes back approved.
        $this->actingAs($officer)->get(route('orders.create', ['inquiry' => $inquiry->id]))
            ->assertRedirect(route('inquiries.layout', $inquiry));
    }

    public function test_sending_twice_does_not_reroll_the_artist(): void
    {
        $officer = $this->officer();
        $first = $this->artist('Maru');
        $this->artist('Mick');
        $inquiry = $this->inquiryOf($officer);

        $this->actingAs($officer)->post(route('inquiries.layout.complete', $inquiry), [
            'reference_note' => 'Keep the team colours',
        ]);

        $sentAt = $inquiry->fresh()->layout_sent_at;

        // A stale tab, the back button, a double click.
        $this->actingAs($officer)->post(route('inquiries.layout.complete', $inquiry), [
            'reference_note' => 'something else entirely',
        ])->assertRedirect(route('orders.create', ['inquiry' => $inquiry->id]));

        $inquiry->refresh();

        $this->assertSame($first->id, $inquiry->layout_artist_id);
        $this->assertSame('Keep the team colours', $inquiry->layout_reference_note);
        $this->assertEquals($sentAt, $inquiry->layout_sent_at);
    }

    public function test_with_nobody_in_it_still_sends_and_says_so(): void
    {
        $officer = $this->officer();
        $inquiry = $this->inquiryOf($officer);

        // No artists at all: the brief must still save rather than dead-end.
        $this->actingAs($officer)->post(route('inquiries.layout.complete', $inquiry), [
            'reference_note' => 'Keep the team colours',
        ])->assertSessionHasNoErrors();

        $this->assertNull($inquiry->fresh()->layout_artist_id);
        $this->assertNotNull($inquiry->fresh()->layout_brief_completed_at);
    }
}
