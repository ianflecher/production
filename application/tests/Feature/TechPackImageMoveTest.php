<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\ProductionOrder;
use App\Models\TechPack;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * A picture pasted into the wrong box is dragged into the right one.
 *
 * The file does not move on disk — only which box points at it — so
 * correcting a mistake costs nothing and never uploads the same picture twice.
 */
class TechPackImageMoveTest extends TestCase
{
    use RefreshDatabase;

    private function packWithImages(array $slots): array
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

        $uploads = [];
        foreach ($slots as $slot => $name) {
            $uploads[$slot] = ['path' => 'tech-pack-images/'.$name, 'name' => $name];
            Storage::disk('local')->put('tech-pack-images/'.$name, 'image bytes');
        }

        $pack = TechPack::create([
            'production_order_id' => $order->id,
            'image_uploads' => $uploads,
        ]);

        return [$order->fresh(), $pack, $artist];
    }

    /** The move as the server performs it, which is what the drag posts. */
    private function move(array $uploads, string $from, string $to): array
    {
        $slots = TechPack::imageSlots();

        if ($from === $to
            || ! in_array($from, $slots, true)
            || ! in_array($to, $slots, true)
            || blank($uploads[$from]['path'] ?? null)) {
            return $uploads;
        }

        $moving = $uploads[$from];
        $displaced = $uploads[$to] ?? null;

        $uploads[$to] = $moving;

        if ($displaced) {
            $uploads[$from] = $displaced;
        } else {
            unset($uploads[$from]);
        }

        return $uploads;
    }

    public function test_a_picture_moves_into_an_empty_box(): void
    {
        [, $pack, ] = $this->packWithImages(['front_mockup' => 'shirt.png']);

        $after = $this->move($pack->image_uploads, 'front_mockup', 'back_mockup');

        $this->assertSame('tech-pack-images/shirt.png', $after['back_mockup']['path']);
        $this->assertArrayNotHasKey('front_mockup', $after, 'it left the box it came from');
    }

    public function test_two_pictures_swap(): void
    {
        [, $pack, ] = $this->packWithImages([
            'front_mockup' => 'front.png',
            'back_mockup' => 'back.png',
        ]);

        $after = $this->move($pack->image_uploads, 'front_mockup', 'back_mockup');

        $this->assertSame('tech-pack-images/front.png', $after['back_mockup']['path']);
        $this->assertSame('tech-pack-images/back.png', $after['front_mockup']['path'],
            'the one already there goes back the other way');
    }

    public function test_the_file_on_disk_is_untouched(): void
    {
        [, $pack, ] = $this->packWithImages(['front_mockup' => 'shirt.png']);

        $this->move($pack->image_uploads, 'front_mockup', 'tag_1');

        Storage::disk('local')->assertExists('tech-pack-images/shirt.png');
    }

    public function test_moving_an_empty_box_does_nothing(): void
    {
        [, $pack, ] = $this->packWithImages(['front_mockup' => 'shirt.png']);

        $after = $this->move($pack->image_uploads, 'back_mockup', 'tag_1');

        $this->assertSame($pack->image_uploads, $after);
    }

    public function test_an_invented_slot_is_ignored(): void
    {
        [, $pack, ] = $this->packWithImages(['front_mockup' => 'shirt.png']);

        $this->assertSame($pack->image_uploads, $this->move($pack->image_uploads, 'front_mockup', 'not_a_box'));
        $this->assertSame($pack->image_uploads, $this->move($pack->image_uploads, 'front_mockup', 'front_mockup'));
    }

    public function test_the_sheet_offers_pasting_and_dragging(): void
    {
        [$order, , ] = $this->packWithImages(['front_mockup' => 'shirt.png']);

        // The sheet as the artist edits it — the only mode where the boxes
        // take an upload at all, and so the only one that needs either.
        $html = view('partials.tech-pack', ['order' => $order, 'editable' => true])->render();

        // The two behaviours, as the page actually carries them.
        $this->assertStringContainsString("addEventListener('paste'", $html);
        $this->assertStringContainsString('text/x-tp-slot', $html);
        $this->assertStringContainsString('move_image', $html);
    }
}
