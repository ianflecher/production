<?php

namespace Tests\Feature;

use App\Models\ProductionOrder;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Both desks type; only the artist draws.
 *
 * The typed boxes were handed to the account officer alone, because they know
 * the spec when the job is taken and the artist was retyping it off the order
 * form. That left the artist looking at an answer they could see was wrong -
 * they have the garment in front of them - with no way to correct it, and the
 * sheet the floor reads is the one that has to be right. So both fill it in.
 *
 * The pictures stay the artist's. They are uploaded, sized, dragged into place
 * and pointed at the garment from that page; an officer has nothing to add
 * there, and every accidental click is a lost layout.
 */
class BothDesksTypeOnlyTheArtistDrawsTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: User, 1: User, 2: ProductionOrder, 3: Task} */
    private function shop(): array
    {
        $officer = User::factory()->create(['job_role' => User::ROLE_SALES, 'is_active' => true]);
        $artist = User::factory()->create(['job_role' => User::JOB_ARTIST, 'is_active' => true]);

        $order = ProductionOrder::create([
            'order_number' => 'IC2026-BOTH1', 'customer_name' => 'Both Desks',
            'product_type' => 'round_neck', 'quantity' => 30,
            'due_date' => now()->addWeeks(3), 'created_by' => $officer->id, 'status' => 'active',
        ]);

        $order->jobOrder()->create([
            'status' => 'sent_to_artist', 'created_by' => $officer->id,
            'print_type' => 'dtf', 'printer' => 'dtf_printer',
        ]);

        Task::create([
            'production_order_id' => $order->id, 'department' => 'Final mockup',
            'sequence' => 2, 'stage' => 2, 'status' => 'complete', 'approved_at' => now(),
            'team' => User::JOB_ARTIST, 'assigned_to' => $artist->id,
        ]);

        $pack = Task::create([
            'production_order_id' => $order->id, 'department' => 'Tech pack',
            'sequence' => 3, 'stage' => 2, 'status' => 'in_progress',
            'team' => User::JOB_ARTIST, 'assigned_to' => $artist->id,
            'approver_role' => 'sales',
        ]);

        return [$officer, $artist, $order->fresh(), $pack];
    }

    public function test_the_artist_fills_in_the_whole_sheet(): void
    {
        [, $artist, $order, $pack] = $this->shop();

        $this->actingAs($artist)->post(route('tasks.tech-pack', $pack), [
            // the spec
            'design_name' => 'Team kit',
            'item_style' => 'Round-neck shirt',
            'tshirt_color' => 'Navy',
            'zipper_type' => 'N/A',
            // rows that live on the job order
            'fabric' => 'Cotton blend',
            'neck' => 'Round neck',
            'packaging' => 'Polybag',
            // and their own half
            'file_location_notes' => 'FOR PRINT\\IC2026-BOTH1',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $order = $order->fresh();

        $this->assertSame('Team kit', $order->techPack->design_name);
        $this->assertSame('Navy', $order->techPack->tshirt_color);
        $this->assertSame('FOR PRINT\\IC2026-BOTH1', $order->techPack->file_location_notes);
        $this->assertSame('Cotton blend', $order->jobOrder->fabric);
        $this->assertSame('Polybag', $order->jobOrder->packaging);
    }

    public function test_the_officer_fills_the_same_boxes_from_their_own_copy(): void
    {
        [$officer, , $order] = $this->shop();

        $this->actingAs($officer)->post(route('job-orders.update', $order), [
            'design_name' => 'Taken at the counter',
            'tshirt_color' => 'Black',
            'fabric' => 'Dri-fit',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $order = $order->fresh();

        $this->assertSame('Taken at the counter', $order->techPack->design_name);
        $this->assertSame('Black', $order->techPack->tshirt_color);
        $this->assertSame('Dri-fit', $order->jobOrder->fabric);
    }

    public function test_whoever_types_last_is_what_the_floor_reads(): void
    {
        // Deliberate: the officer takes the job and fills the spec, then the
        // artist has the garment in front of them and corrects it.
        [$officer, $artist, $order, $pack] = $this->shop();

        $this->actingAs($officer)->post(route('job-orders.update', $order), [
            'tshirt_color' => 'Black',
        ])->assertRedirect();

        $this->actingAs($artist)->post(route('tasks.tech-pack', $pack), [
            'tshirt_color' => 'Navy',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame('Navy', $order->fresh()->techPack->tshirt_color);
    }

    public function test_the_officers_copy_has_no_way_to_put_a_picture_on_the_sheet(): void
    {
        [$officer, , $order] = $this->shop();

        $this->actingAs($officer)->get(route('job-orders.edit', $order))
            ->assertOk()
            // the typed boxes are there
            ->assertSee('name="design_name"', false)
            ->assertSee('name="tshirt_color"', false)
            // the pictures are not
            ->assertDontSee('name="tech_pack_images[front_mockup]"', false)
            ->assertDontSee('name="tech_pack_mockups[]"', false)
            ->assertDontSee('tp-image-input', false);
    }

    public function test_the_artists_copy_has_both(): void
    {
        [, $artist, , $pack] = $this->shop();

        $this->actingAs($artist)->get(route('tasks.job-order', $pack))
            ->assertOk()
            ->assertSee('name="design_name"', false)
            ->assertSee('name="tshirt_color"', false)
            ->assertSee('name="tech_pack_images[front_mockup]"', false)
            ->assertSee('name="file_location_notes"', false);
    }
}
