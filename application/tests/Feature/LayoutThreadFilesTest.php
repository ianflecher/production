<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Inquiry;
use App\Models\Message;
use App\Models\ProductionOrder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * A layout message can carry a photo.
 *
 * Most of what the officer and the artist say to each other about a drawing is
 * a picture of it. Text only meant they went back to Viber for exactly the
 * thing the thread was built to hold.
 */
class LayoutThreadFilesTest extends TestCase
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

    public function test_a_photo_can_be_sent_with_a_message(): void
    {
        Storage::fake('local');
        [$officer, , $inquiry] = $this->layout();

        $this->actingAs($officer)->post(route('inquiries.messages.store', $inquiry), [
            'body' => 'Like this one.',
            'files' => [UploadedFile::fake()->image('peg.png')],
        ])->assertRedirect();

        $message = Message::firstOrFail();

        $this->assertSame('Like this one.', $message->body);
        $this->assertCount(1, $message->files);
        $this->assertSame('peg.png', $message->files->first()->original_name);
        Storage::disk('local')->assertExists($message->files->first()->path);
    }

    public function test_a_photo_on_its_own_is_a_message(): void
    {
        Storage::fake('local');
        [, $artist, $inquiry] = $this->layout();

        $this->actingAs($artist)->post(route('inquiries.messages.store', $inquiry), [
            'files' => [UploadedFile::fake()->image('draft.png')],
        ])->assertRedirect();

        $message = Message::firstOrFail();

        $this->assertNull($message->body, 'nothing typed, and that is fine');
        $this->assertCount(1, $message->files);
    }

    public function test_an_empty_message_with_no_photo_is_still_refused(): void
    {
        [$officer, , $inquiry] = $this->layout();

        $this->actingAs($officer)->post(route('inquiries.messages.store', $inquiry), ['body' => ''])
            ->assertInvalid(['body']);

        $this->assertSame(0, Message::count());
    }

    public function test_a_junk_file_type_is_refused(): void
    {
        Storage::fake('local');
        [$officer, , $inquiry] = $this->layout();

        $this->actingAs($officer)->post(route('inquiries.messages.store', $inquiry), [
            'body' => 'see attached',
            'files' => [UploadedFile::fake()->create('script.exe', 10)],
        ])->assertInvalid(['files.0']);

        $this->assertSame(0, Message::count());
    }

    public function test_both_sides_can_open_the_attachment(): void
    {
        Storage::fake('local');
        [$officer, $artist, $inquiry] = $this->layout();

        $this->actingAs($artist)->post(route('inquiries.messages.store', $inquiry), [
            'files' => [UploadedFile::fake()->image('draft.png')],
        ])->assertRedirect();

        $file = Message::firstOrFail()->files->first();

        // This used to 403 for everyone: the download asked the ORDER for
        // permission, and a layout thread has no order behind it yet.
        $this->actingAs($officer)->get(route('messages.file', $file))->assertOk();
        $this->actingAs($artist)->get(route('messages.file', $file))->assertOk();
    }

    public function test_a_stranger_cannot_open_the_attachment(): void
    {
        Storage::fake('local');
        [$officer, , $inquiry] = $this->layout();
        $other = User::factory()->create(['job_role' => 'artist', 'is_active' => true]);

        $this->actingAs($officer)->post(route('inquiries.messages.store', $inquiry), [
            'files' => [UploadedFile::fake()->image('peg.png')],
        ])->assertRedirect();

        $this->actingAs($other)
            ->get(route('messages.file', Message::firstOrFail()->files->first()))
            ->assertForbidden();
    }

    public function test_the_attachment_shows_on_the_thread(): void
    {
        Storage::fake('local');
        [$officer, $artist, $inquiry] = $this->layout();

        $this->actingAs($artist)->post(route('inquiries.messages.store', $inquiry), [
            'files' => [UploadedFile::fake()->image('draft.png')],
        ])->assertRedirect();

        $this->actingAs($officer)->get(route('messages.layout', $inquiry))
            ->assertOk()->assertSee('draft.png');
    }

    public function test_the_photo_carries_over_to_the_job_order(): void
    {
        Storage::fake('local');
        [$officer, $artist, $inquiry] = $this->layout();

        $this->actingAs($artist)->post(route('inquiries.messages.store', $inquiry), [
            'body' => 'First draft.',
            'files' => [UploadedFile::fake()->image('draft.png')],
        ])->assertRedirect();

        $inquiry->update([
            'layout_status' => Inquiry::LAYOUT_APPROVED,
            'layout_submitted_at' => now()->subHour(),
            'layout_approved_at' => now(),
        ]);

        $this->actingAs($officer)->post('/orders', [
            'inquiry_id' => $inquiry->id,
            'order_number' => 'IC2026-09400',
            'due_date' => now()->addWeeks(3)->toDateString(),
            'product_type' => 'round_neck',
            'sizes' => ['M' => 10],
        ])->assertRedirect();

        $order = ProductionOrder::firstOrFail();

        $this->assertSame(1, $order->messages()->count());
        $this->assertCount(1, $order->messages()->first()->files, 'the photo came with it');

        $this->actingAs($officer)->get(route('messages.show', $order))
            ->assertOk()->assertSee('draft.png');
    }
}
