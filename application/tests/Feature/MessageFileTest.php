<?php

namespace Tests\Feature;

use App\Models\Message;
use App\Models\MessageFile;
use App\Models\ProductionOrder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Photos and files sent in a job order conversation. */
class MessageFileTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $jobRole = 'sewing'): User
    {
        return User::factory()->create(['job_role' => $jobRole, 'is_active' => true]);
    }

    private function order(User $sales): ProductionOrder
    {
        $this->actingAs($sales)->post('/orders', [
            'order_number' => 'IC2026-05555',
            'client_name' => 'Photo Co',
            'client_last_name' => 'Cruz',
            'client_contact' => '0917-000-0000',
            'client_address' => 'Angeles City',
            'due_date' => now()->addWeeks(3)->toDateString(),
            'product_type' => 'round_neck',
            'sizes' => ['M' => 10],
        ]);

        return ProductionOrder::where('order_number', 'IC2026-05555')->firstOrFail();
    }

    public function test_a_photo_can_be_sent_with_a_message(): void
    {
        Storage::fake('local');
        $sales = $this->user(User::ROLE_SALES);
        $order = $this->order($sales);

        $this->actingAs($sales)->post("/messages/{$order->id}", [
            'body' => 'Here is the sample',
            'files' => [UploadedFile::fake()->image('sample.jpg')],
        ])->assertRedirect();

        $file = MessageFile::firstOrFail();
        $this->assertSame('sample.jpg', $file->original_name);
        $this->assertTrue($file->isImage());
        Storage::disk('local')->assertExists($file->path);
    }

    public function test_a_photo_on_its_own_is_a_valid_message(): void
    {
        Storage::fake('local');
        $sales = $this->user(User::ROLE_SALES);
        $order = $this->order($sales);

        $this->actingAs($sales)->post("/messages/{$order->id}", [
            'files' => [UploadedFile::fake()->image('only.jpg')],
        ])->assertRedirect();

        $this->assertNull(Message::first()->body);
        $this->assertSame(1, MessageFile::count());
    }

    public function test_several_photos_can_be_sent_at_once(): void
    {
        Storage::fake('local');
        $sales = $this->user(User::ROLE_SALES);
        $order = $this->order($sales);

        $this->actingAs($sales)->post("/messages/{$order->id}", [
            'files' => [
                UploadedFile::fake()->image('a.jpg'),
                UploadedFile::fake()->image('b.png'),
            ],
        ])->assertRedirect();

        $this->assertSame(2, Message::first()->files()->count());
    }

    public function test_a_message_with_neither_text_nor_a_photo_is_rejected(): void
    {
        $sales = $this->user(User::ROLE_SALES);
        $order = $this->order($sales);

        $this->actingAs($sales)->post("/messages/{$order->id}", ['body' => ''])
            ->assertInvalid(['body']);

        $this->assertSame(0, Message::count());
    }

    public function test_executables_are_refused(): void
    {
        Storage::fake('local');
        $sales = $this->user(User::ROLE_SALES);
        $order = $this->order($sales);

        $this->actingAs($sales)->post("/messages/{$order->id}", [
            'files' => [UploadedFile::fake()->create('nasty.exe', 20)],
        ])->assertInvalid(['files.0']);

        $this->assertSame(0, MessageFile::count());
    }

    // ---- Who can open an attachment ---------------------------------------

    public function test_someone_on_the_order_can_open_the_photo(): void
    {
        Storage::fake('local');
        $sales = $this->user(User::ROLE_SALES);
        $order = $this->order($sales);
        $worker = $this->user();
        $order->tasks()->first()->update(['assigned_to' => $worker->id]);

        $this->actingAs($sales)->post("/messages/{$order->id}", [
            'files' => [UploadedFile::fake()->image('sample.jpg')],
        ]);

        $file = MessageFile::firstOrFail();
        $this->actingAs($worker)->get("/message-files/{$file->id}")->assertOk();
    }

    public function test_someone_not_on_the_order_cannot_open_the_photo(): void
    {
        Storage::fake('local');
        $sales = $this->user(User::ROLE_SALES);
        $order = $this->order($sales);

        $this->actingAs($sales)->post("/messages/{$order->id}", [
            'files' => [UploadedFile::fake()->image('private.jpg')],
        ]);

        $file = MessageFile::firstOrFail();
        $this->actingAs($this->user())->get("/message-files/{$file->id}")->assertForbidden();
    }

    public function test_a_guest_cannot_open_a_photo(): void
    {
        Storage::fake('local');
        $sales = $this->user(User::ROLE_SALES);
        $order = $this->order($sales);

        $this->actingAs($sales)->post("/messages/{$order->id}", [
            'files' => [UploadedFile::fake()->image('private.jpg')],
        ]);
        $file = MessageFile::firstOrFail();

        auth()->logout();
        $this->flushSession();

        $this->get("/message-files/{$file->id}")->assertRedirect('/login');
    }

    public function test_the_inbox_describes_a_photo_only_message(): void
    {
        Storage::fake('local');
        $sales = $this->user(User::ROLE_SALES);
        $order = $this->order($sales);

        // A photo with nothing typed — the row must not read "You:" then blank.
        $this->actingAs($sales)->post("/messages/{$order->id}", [
            'files' => [UploadedFile::fake()->image('sample.jpg')],
        ]);

        $this->actingAs($sales)->get('/messages')
            ->assertOk()
            ->assertSee('Photo');

        $this->assertSame('📷 Photo', Message::first()->preview());
    }

    public function test_the_preview_counts_several_photos(): void
    {
        Storage::fake('local');
        $sales = $this->user(User::ROLE_SALES);
        $order = $this->order($sales);

        $this->actingAs($sales)->post("/messages/{$order->id}", [
            'files' => [
                UploadedFile::fake()->image('a.jpg'),
                UploadedFile::fake()->image('b.jpg'),
            ],
        ]);

        $this->assertSame('📷 2 photos', Message::first()->preview());
    }

    public function test_a_non_image_attachment_reads_as_a_file(): void
    {
        Storage::fake('local');
        $sales = $this->user(User::ROLE_SALES);
        $order = $this->order($sales);

        $this->actingAs($sales)->post("/messages/{$order->id}", [
            'files' => [UploadedFile::fake()->create('spec.pdf', 20, 'application/pdf')],
        ]);

        $this->assertSame('📎 File', Message::first()->preview());
    }

    public function test_typed_text_still_wins_over_the_attachment_description(): void
    {
        Storage::fake('local');
        $sales = $this->user(User::ROLE_SALES);
        $order = $this->order($sales);

        $this->actingAs($sales)->post("/messages/{$order->id}", [
            'body' => 'Here is the sample',
            'files' => [UploadedFile::fake()->image('sample.jpg')],
        ]);

        $this->assertSame('Here is the sample', Message::first()->preview());
    }

    public function test_the_photo_shows_in_the_thread(): void
    {
        Storage::fake('local');
        $sales = $this->user(User::ROLE_SALES);
        $order = $this->order($sales);

        $this->actingAs($sales)->post("/messages/{$order->id}", [
            'files' => [UploadedFile::fake()->image('sample.jpg')],
        ]);

        $file = MessageFile::firstOrFail();
        $this->actingAs($sales)->get("/messages/{$order->id}")
            ->assertOk()
            ->assertSee(route('messages.file', $file), false);
    }
}
