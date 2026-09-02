<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\ProductionOrder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Uploading a picture to the tech pack, the plain way.
 *
 * Guards the ordinary path against anything added around it — paste, drag,
 * move between boxes. If this breaks, the artist cannot work.
 */
class TechPackUploadStillWorksTest extends TestCase
{
    use RefreshDatabase;

    private function mockupTask(): array
    {
        Storage::fake('local');

        $officer = User::factory()->create(['job_role' => User::ROLE_SALES, 'is_active' => true]);
        $artist = User::factory()->create(['job_role' => 'artist', 'is_active' => true]);

        $order = ProductionOrder::create([
            'order_number' => 'IC2026-0'.random_int(1000, 9999),
            'customer_name' => 'Juan Dela Cruz',
            'client_id' => Client::create([
                'name' => 'Juan', 'last_name' => 'Dela Cruz', 'contact_number' => '0917',
                'office_address' => 'Angeles City', 'delivery_address' => 'Angeles City',
                'created_by' => $officer->id,
            ])->id,
            'product_type' => 'round_neck',
            'quantity' => 10,
            'unit_price' => 500,
            'total_price' => 5000,
            'due_date' => now()->addWeeks(3)->toDateString(),
            'status' => 'active',
            'created_by' => $officer->id,
        ]);

        $order->buildPipeline([], 'manual');
        $order = $order->fresh();

        // The pack is only editable once the client has approved the mockup and
        // the officer has sent their half — the same two things the flow asks
        // for. Anything less and the controller sends the artist away.
        $mockup = $order->tasks()->where('department', 'like', 'Final mockup%')->first();
        $mockup->update(['assigned_to' => $artist->id, 'status' => 'complete', 'released_at' => now()]);

        \App\Models\JobOrder::updateOrCreate(
            ['production_order_id' => $order->id],
            ['status' => 'sent_to_artist', 'created_by' => $officer->id]
        );

        $task = $order->fresh()->tasks()->where('department', 'Tech pack')->first();
        $task->update(['assigned_to' => $artist->id, 'status' => 'in_progress', 'released_at' => now()]);

        return [$order->fresh(), $task->fresh(), $artist];
    }

    public function test_an_artist_can_upload_a_picture_into_a_box(): void
    {
        [$order, $task, $artist] = $this->mockupTask();

        $this->actingAs($artist)->post(route('tasks.tech-pack', $task->id), [
            'tech_pack_images' => ['front_mockup' => UploadedFile::fake()->image('shirt.png')],
        ])->assertValid()->assertRedirect();

        $uploads = $order->fresh()->techPack?->image_uploads ?? [];

        $this->assertArrayHasKey('front_mockup', $uploads, 'the picture went into the box');
        Storage::disk('local')->assertExists($uploads['front_mockup']['path']);
    }

    public function test_uploading_a_second_picture_leaves_the_first_alone(): void
    {
        [$order, $task, $artist] = $this->mockupTask();

        $this->actingAs($artist)->post(route('tasks.tech-pack', $task->id), [
            'tech_pack_images' => ['front_mockup' => UploadedFile::fake()->image('front.png')],
        ])->assertRedirect();

        $this->actingAs($artist)->post(route('tasks.tech-pack', $task->id), [
            'tech_pack_images' => ['back_mockup' => UploadedFile::fake()->image('back.png')],
        ])->assertRedirect();

        $uploads = $order->fresh()->techPack?->image_uploads ?? [];

        $this->assertArrayHasKey('front_mockup', $uploads);
        $this->assertArrayHasKey('back_mockup', $uploads);
    }

    public function test_saving_with_no_picture_at_all_still_works(): void
    {
        [, $task, $artist] = $this->mockupTask();

        $this->actingAs($artist)->post(route('tasks.tech-pack', $task->id), [])
            ->assertValid()->assertRedirect();
    }
}
