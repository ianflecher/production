<?php

namespace Tests\Feature;

use App\Models\AppNotification;
use App\Models\ProductionOrder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The officer can send the artist more files after the job has started.
 *
 * The client does not stop sending things when the work begins — a photo of
 * the logo, the right shade, the spelling of a name on a jersey. There was
 * nowhere to put them: the brief's upload box belongs to the brief, and once
 * the order existed the officer sent them somewhere the system cannot see.
 *
 * The endpoint was there and no page posted to it, so nothing could reach it;
 * and nothing told the artist, so a file that did arrive was a file nobody
 * opened.
 *
 * ADDING only. Nothing here removes a file an artist may be working from.
 */
class FilesCanReachTheArtistAfterTheOrderStartsTest extends TestCase
{
    use RefreshDatabase;

    private function officer(): User
    {
        return User::factory()->create(['job_role' => User::ROLE_SALES, 'is_active' => true]);
    }

    private function artist(string $name): User
    {
        return User::factory()->create([
            'name' => $name, 'job_role' => User::JOB_ARTIST, 'is_active' => true,
        ]);
    }

    /** An order whose pack has gone out, with an artist holding a step. */
    private function runningOrder(User $officer, ?User $artist = null, bool $sent = true): ProductionOrder
    {
        $this->actingAs($officer)->post('/orders', [
            'order_number' => 'IC2026-08080',
            'client_name' => 'Late', 'client_last_name' => 'Files',
            'client_contact' => '0917-000-0000', 'client_address' => 'Angeles City',
            'due_date' => now()->addWeeks(3)->toDateString(),
            'product_type' => 'round_neck',
            'sizes' => ['M' => 10],
        ]);

        $order = ProductionOrder::where('order_number', 'IC2026-08080')->firstOrFail();

        if ($sent) {
            $order->jobOrder->update(['sent_to_artist_at' => now()->subDays(2)]);
        }

        if ($artist) {
            $order->tasks()->where('team', User::JOB_ARTIST)->orderBy('sequence')->first()
                ->update(['assigned_to' => $artist->id, 'status' => 'in_progress']);
        }

        return $order->fresh();
    }

    private function send(User $officer, ProductionOrder $order, string $name = 'logo-photo.jpg'): \Illuminate\Testing\TestResponse
    {
        Storage::fake('local');

        return $this->actingAs($officer)->post(route('job-orders.reference', $order), [
            'reference_files' => [UploadedFile::fake()->image($name)],
        ]);
    }

    /* ---------------- there is a way in ---------------- */

    /** The box that was missing: nothing on the order page posted to the route. */
    public function test_the_order_page_offers_a_way_to_send_files(): void
    {
        $officer = $this->officer();
        $order = $this->runningOrder($officer, $this->artist('Cristal'));

        $this->actingAs($officer)->get(route('orders.show', $order))
            ->assertOk()
            ->assertSee('Send files to the artist')
            ->assertSee(route('job-orders.reference', $order), false);
    }

    public function test_a_file_can_be_added_once_the_job_has_started(): void
    {
        $officer = $this->officer();
        $order = $this->runningOrder($officer, $this->artist('Cristal'));

        $this->send($officer, $order)->assertSessionHasNoErrors();

        $this->assertCount(1, $order->jobOrder->fresh()->referenceFiles);
    }

    /* ---------------- the artist hears about it ---------------- */

    public function test_the_artist_holding_the_step_is_told(): void
    {
        $officer = $this->officer();
        $cristal = $this->artist('Cristal');
        $order = $this->runningOrder($officer, $cristal);

        $this->send($officer, $order);

        $note = AppNotification::where('user_id', $cristal->id)->latest('id')->first();

        $this->assertNotNull($note, 'the file landed and nobody was told');
        $this->assertStringContainsString('IC2026-08080', $note->body);
    }

    /** An artist who already finished their step is not chased about it. */
    public function test_an_artist_whose_step_is_done_is_not_told(): void
    {
        $officer = $this->officer();
        $done = $this->artist('Mick');
        $order = $this->runningOrder($officer, $done);

        $order->tasks()->where('assigned_to', $done->id)->update(['status' => 'complete']);

        $this->send($officer, $order);

        $this->assertSame(0, AppNotification::where('user_id', $done->id)->count());
    }

    /**
     * Told while they are working, whatever the tech pack is doing.
     *
     * The first cut waited for sent_to_artist_at. That is stage three, and the
     * artist draws from stage one: an officer sent two links on a live job,
     * the artist was already at Final mockup, and the page showed nothing.
     */
    public function test_the_artist_is_told_even_before_the_pack_goes_out(): void
    {
        $officer = $this->officer();
        $cristal = $this->artist('Cristal');
        $order = $this->runningOrder($officer, $cristal, sent: false);

        $this->send($officer, $order);

        $this->assertSame(1, AppNotification::where('user_id', $cristal->id)->count());
    }

    /* ---------------- and can see it ---------------- */

    public function test_the_late_file_shows_on_the_artists_step(): void
    {
        $officer = $this->officer();
        $cristal = $this->artist('Cristal');
        $order = $this->runningOrder($officer, $cristal);

        $this->send($officer, $order, 'the-logo.jpg');

        $task = $order->tasks()->where('assigned_to', $cristal->id)->firstOrFail();

        $this->actingAs($cristal)->get(route('tasks.show', $task->id))
            ->assertOk()
            ->assertSee('from the account officer');
    }

    /** The brief's own files are not dressed up as something the officer sent. */
    public function test_the_briefs_own_files_are_not_listed_as_sent(): void
    {
        $officer = $this->officer();
        $order = $this->runningOrder($officer, $this->artist('Cristal'));

        $order->jobOrder->referenceFiles()->create([
            'path' => 'job-order-refs/from-the-brief.jpg',
            'original_name' => 'from-the-brief.jpg',
            'kind' => 'layout',
            'mime' => 'image/jpeg',
            'size' => 100,
            'uploaded_by' => $officer->id,
        ]);

        $this->assertCount(0, $order->jobOrder->fresh()->filesSentToTheArtist());
    }

    /** And a sent file is never mistaken for the design to make. */
    public function test_a_sent_file_is_not_the_design(): void
    {
        $officer = $this->officer();
        $order = $this->runningOrder($officer, $this->artist('Cristal'));

        $order->jobOrder->referenceFiles()->create([
            'path' => 'job-order-refs/drawing.jpg',
            'original_name' => 'drawing.jpg',
            'kind' => 'layout',
            'mime' => 'image/jpeg',
            'size' => 100,
            'uploaded_by' => $officer->id,
        ]);

        $this->send($officer, $order, 'a-photo-the-client-sent.jpg');

        $design = $order->jobOrder->fresh()->designFiles();

        $this->assertCount(1, $design);
        $this->assertSame('drawing.jpg', $design->first()->original_name);
    }

    /* ---------------- and the message with them ---------------- */

    /**
     * A photo of a logo with no word attached is a photo the artist has to
     * guess at — is this the logo, the placement, the colour, the thing to
     * avoid? The message rides with the files it was sent with.
     */
    public function test_the_officer_can_say_what_the_files_are_for(): void
    {
        $officer = $this->officer();
        $cristal = $this->artist('Cristal');
        $order = $this->runningOrder($officer, $cristal);

        Storage::fake('local');
        $this->actingAs($officer)->post(route('job-orders.reference', $order), [
            'reference_files' => [UploadedFile::fake()->image('logo.jpg')],
            'note' => 'Use this logo on the left chest, not the back.',
        ])->assertSessionHasNoErrors();

        $task = $order->tasks()->where('assigned_to', $cristal->id)->firstOrFail();

        $this->actingAs($cristal)->get(route('tasks.show', $task->id))
            ->assertOk()
            ->assertSee('Use this logo on the left chest, not the back.')
            ->assertSee('What the account officer said');
    }

    /** The box is on the order page, not something only the API knows about. */
    public function test_the_order_page_offers_the_message_box(): void
    {
        $officer = $this->officer();
        $order = $this->runningOrder($officer, $this->artist('Cristal'));

        $this->actingAs($officer)->get(route('orders.show', $order))
            ->assertOk()
            ->assertSee('Message for the artist');
    }

    /** Every file of one upload carries the same message — one batch, one thing said. */
    public function test_the_message_is_kept_on_each_file_of_the_batch(): void
    {
        $officer = $this->officer();
        $order = $this->runningOrder($officer, $this->artist('Cristal'));

        Storage::fake('local');
        $this->actingAs($officer)->post(route('job-orders.reference', $order), [
            'reference_files' => [
                UploadedFile::fake()->image('front.jpg'),
                UploadedFile::fake()->image('back.jpg'),
            ],
            'note' => 'Front and back of the same jersey.',
        ]);

        $files = $order->jobOrder->fresh()->referenceFiles;

        $this->assertCount(2, $files);
        $this->assertTrue($files->every(fn ($f) => $f->note === 'Front and back of the same jersey.'));
    }

    /** Files sent without a word are still shown, just without a message block. */
    public function test_files_sent_with_no_message_still_arrive(): void
    {
        $officer = $this->officer();
        $cristal = $this->artist('Cristal');
        $order = $this->runningOrder($officer, $cristal);

        $this->send($officer, $order);

        $task = $order->tasks()->where('assigned_to', $cristal->id)->firstOrFail();

        $this->actingAs($cristal)->get(route('tasks.show', $task->id))
            ->assertOk()
            ->assertSee('from the account officer')
            ->assertDontSee('What the account officer said');
    }

    /** Two separate sends keep their own messages rather than merging. */
    public function test_each_send_keeps_its_own_message(): void
    {
        $officer = $this->officer();
        $cristal = $this->artist('Cristal');
        $order = $this->runningOrder($officer, $cristal);

        Storage::fake('local');

        foreach ([['a.jpg', 'The logo goes on the sleeve.'], ['b.jpg', 'Ignore the first one, use this.']] as [$name, $note]) {
            $this->actingAs($officer)->post(route('job-orders.reference', $order), [
                'reference_files' => [UploadedFile::fake()->image($name)],
                'note' => $note,
            ]);
        }

        $task = $order->tasks()->where('assigned_to', $cristal->id)->firstOrFail();

        $this->actingAs($cristal)->get(route('tasks.show', $task->id))
            ->assertSee('The logo goes on the sleeve.')
            ->assertSee('Ignore the first one, use this.');
    }

    /**
     * Pasted, dropped or chosen, it arrives the same way.
     *
     * The officer has the reference the moment the client sends it. Saving it
     * to the desktop and finding it again in a file dialog is three steps for
     * something already in the clipboard.
     */
    public function test_a_screenshot_can_be_pasted_straight_in(): void
    {
        $officer = $this->officer();
        $order = $this->runningOrder($officer, $this->artist('Cristal'));

        $this->actingAs($officer)->get(route('orders.show', $order))
            ->assertOk()
            ->assertSee('data-paste-into', false)
            ->assertSee('to paste a screenshot straight in');
    }

    /* ---------------- a link is a reference too ---------------- */

    /**
     * Half of what a client sends is a link — a Drive folder, a Facebook post,
     * a board of pegs. Uploading that meant downloading it first, or pasting
     * the address into a chat the system cannot see.
     */
    public function test_a_link_can_be_sent_instead_of_a_file(): void
    {
        $officer = $this->officer();
        $cristal = $this->artist('Cristal');
        $order = $this->runningOrder($officer, $cristal);

        $this->actingAs($officer)->post(route('job-orders.reference', $order), [
            'link' => 'https://drive.google.com/drive/folders/abc123',
            'note' => 'The whole folder the client sent.',
        ])->assertSessionHasNoErrors();

        $ref = $order->jobOrder->fresh()->referenceFiles->first();

        $this->assertTrue($ref->isExternal());
        $this->assertTrue($ref->isWebLink());
        $this->assertFalse($ref->isImage(), 'a link is not drawn as a picture');
        $this->assertSame('https://drive.google.com/drive/folders/abc123', $ref->external_path);
    }

    /** The artist gets the address itself, and a way to copy it. */
    public function test_the_artist_can_open_and_copy_the_link(): void
    {
        $officer = $this->officer();
        $cristal = $this->artist('Cristal');
        $order = $this->runningOrder($officer, $cristal);

        $this->actingAs($officer)->post(route('job-orders.reference', $order), [
            'link' => 'https://www.facebook.com/post/123',
        ]);

        $task = $order->tasks()->where('assigned_to', $cristal->id)->firstOrFail();

        $this->actingAs($cristal)->get(route('tasks.show', $task->id))
            ->assertOk()
            ->assertSee('https://www.facebook.com/post/123', false)
            ->assertSee('Copy link');
    }

    /** And is told about it, the same as a file. */
    public function test_the_artist_is_told_about_a_link(): void
    {
        $officer = $this->officer();
        $cristal = $this->artist('Cristal');
        $order = $this->runningOrder($officer, $cristal);

        $this->actingAs($officer)->post(route('job-orders.reference', $order), [
            'link' => 'https://drive.google.com/x',
        ]);

        $this->assertSame(1, AppNotification::where('user_id', $cristal->id)->count());
    }

    /** The officer can paste the link in; the box is on the page. */
    public function test_the_order_page_offers_a_link_box(): void
    {
        $officer = $this->officer();
        $order = $this->runningOrder($officer, $this->artist('Cristal'));

        $this->actingAs($officer)->get(route('orders.show', $order))
            ->assertOk()
            ->assertSee('Or paste a link')
            ->assertSee('name="link"', false);
    }

    /** Something that is not a link is refused rather than saved as one. */
    public function test_a_line_of_text_is_not_a_link(): void
    {
        $officer = $this->officer();
        $order = $this->runningOrder($officer, $this->artist('Cristal'));

        $this->actingAs($officer)->post(route('job-orders.reference', $order), [
            'link' => 'ask the client for the folder',
        ])->assertSessionHasErrors('link');

        $this->assertCount(0, $order->jobOrder->fresh()->referenceFiles);
    }

    /** Sending nothing at all says so, rather than looking like it worked. */
    public function test_sending_neither_a_file_nor_a_link_is_refused(): void
    {
        $officer = $this->officer();
        $order = $this->runningOrder($officer, $this->artist('Cristal'));

        $this->actingAs($officer)->post(route('job-orders.reference', $order), [])
            ->assertSessionHasErrors('reference_files');
    }

    /* ---------------- who may ---------------- */

    /** Another officer's order is not theirs to add to. */
    public function test_another_officer_cannot_send_files_to_it(): void
    {
        $owner = $this->officer();
        $order = $this->runningOrder($owner, $this->artist('Cristal'));

        Storage::fake('local');

        $this->actingAs($this->officer())
            ->post(route('job-orders.reference', $order), [
                'reference_files' => [UploadedFile::fake()->image('sneaky.jpg')],
            ])
            ->assertForbidden();
    }
}
