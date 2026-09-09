<?php

namespace Tests\Feature;

use App\Models\ProductionOrder;
use App\Models\TechPack;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Several designs on the batch sheet.
 *
 * One order can carry a whole kit - a jersey, a jacket and shorts on one job -
 * and the batch sheet is where the floor reads them. It held one picture, so
 * the second and third design were either left off the sheet or pasted into
 * one flattened image nobody could read.
 *
 * The sample sheet still holds one: at that point there is one garment in
 * front of the client.
 */
class ManyMockupsOnTheBatchSheetTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: User, 1: ProductionOrder} */
    private function jobReadyForTheBatchSheet(): array
    {
        $officer = User::factory()->create(['job_role' => User::ROLE_SALES, 'is_active' => true]);
        $artist = User::factory()->create(['job_role' => User::JOB_ARTIST, 'is_active' => true]);

        $order = ProductionOrder::create([
            'order_number' => 'IC2026-KIT01', 'customer_name' => 'Team Kit',
            'product_type' => 'round_neck', 'quantity' => 12,
            'due_date' => now()->addWeeks(2), 'created_by' => $officer->id, 'status' => 'active',
        ]);

        $order->jobOrder()->create([
            'status' => 'sent_to_artist', 'created_by' => $officer->id,
            'print_type' => 'dtf', 'printer' => 'dtf_printer',
        ]);

        $order->buildPipeline([], null);
        $order->tasks()->where('department', 'Final mockup')->update([
            'status' => 'complete', 'approved_at' => now(), 'assigned_to' => $artist->id,
        ]);

        $order->openTechPack(TechPack::PHASE_SAMPLE)->fill(['design_name' => 'Team Kit'])->save();
        $order->fresh()->openTechPack(TechPack::PHASE_MASSPROD);

        $batch = $order->tasks()
            ->where('department', ProductionOrder::STEP_TECH_PACK_MASSPROD)
            ->firstOrFail();
        $batch->update(['assigned_to' => $artist->id, 'status' => 'in_progress']);

        return [$artist, $order->fresh()];
    }

    public function test_the_artist_uploads_a_whole_kit_at_once(): void
    {
        Storage::fake('local');
        [$artist, $order] = $this->jobReadyForTheBatchSheet();

        $batch = $order->tasks()->where('department', ProductionOrder::STEP_TECH_PACK_MASSPROD)->firstOrFail();

        $this->actingAs($artist)->post(route('tasks.tech-pack', $batch), [
            'tech_pack_mockups' => [
                UploadedFile::fake()->image('jersey.png'),
                UploadedFile::fake()->image('jacket.png'),
                UploadedFile::fake()->image('shorts.png'),
            ],
        ])->assertRedirect()->assertSessionHasNoErrors();

        $pack = $order->fresh()->techPackFor(TechPack::PHASE_MASSPROD);

        $this->assertCount(3, $pack->mockups());
        // Filled from the front, so the order they were picked is the order
        // the sheet turns through them.
        $this->assertSame(['front_mockup', 'mockup_2', 'mockup_3'], array_keys($pack->mockups()));
    }

    public function test_a_design_added_later_goes_after_the_ones_already_there(): void
    {
        Storage::fake('local');
        [$artist, $order] = $this->jobReadyForTheBatchSheet();

        $batch = $order->tasks()->where('department', ProductionOrder::STEP_TECH_PACK_MASSPROD)->firstOrFail();

        $this->actingAs($artist)->post(route('tasks.tech-pack', $batch), [
            'tech_pack_mockups' => [UploadedFile::fake()->image('jersey.png')],
        ])->assertRedirect();

        $this->actingAs($artist)->post(route('tasks.tech-pack', $batch), [
            'tech_pack_mockups' => [UploadedFile::fake()->image('jacket.png')],
        ])->assertRedirect();

        $pack = $order->fresh()->techPackFor(TechPack::PHASE_MASSPROD);

        $this->assertSame(['front_mockup', 'mockup_2'], array_keys($pack->mockups()));
        $this->assertSame('jacket.png', $pack->mockups()['mockup_2']['name']);
    }

    public function test_the_sheet_turns_through_them(): void
    {
        Storage::fake('local');
        [$artist, $order] = $this->jobReadyForTheBatchSheet();

        $batch = $order->tasks()->where('department', ProductionOrder::STEP_TECH_PACK_MASSPROD)->firstOrFail();

        $this->actingAs($artist)->post(route('tasks.tech-pack', $batch), [
            'tech_pack_mockups' => [
                UploadedFile::fake()->image('a.png'),
                UploadedFile::fake()->image('b.png'),
            ],
        ])->assertRedirect();

        $this->actingAs($artist)->get(route('tasks.job-order', $batch))
            ->assertOk()
            ->assertSee('data-carousel', false)
            ->assertSee('Previous design', false)
            ->assertSee('Next design', false)
            ->assertSee('1 / 2');
    }

    public function test_each_picture_is_served_off_the_sheet_that_asked_for_it(): void
    {
        // Both sheets have a front_mockup. Serving the sample's picture to the
        // batch sheet is exactly the mix-up the two sheets exist to prevent.
        Storage::fake('local');
        [$artist, $order] = $this->jobReadyForTheBatchSheet();

        $order->techPackFor(TechPack::PHASE_SAMPLE)->update(['image_uploads' => [
            'front_mockup' => ['path' => 'tech-pack-images/sample.png', 'name' => 'sample.png'],
        ]]);
        $order->techPackFor(TechPack::PHASE_MASSPROD)->update(['image_uploads' => [
            'front_mockup' => ['path' => 'tech-pack-images/batch.png', 'name' => 'batch.png'],
        ]]);

        Storage::disk('local')->put('tech-pack-images/sample.png', 'SAMPLE-BYTES');
        Storage::disk('local')->put('tech-pack-images/batch.png', 'BATCH-BYTES');

        $officer = User::find($order->created_by);

        $this->actingAs($officer)->get(route('job-orders.tech-pack-image', [
            'order' => $order, 'slot' => 'front_mockup', 'phase' => TechPack::PHASE_MASSPROD,
        ]))->assertOk()->assertStreamedContent('BATCH-BYTES');

        // No phase named is the sample: every link written before the split.
        $this->actingAs($officer)->get(route('job-orders.tech-pack-image', [
            'order' => $order, 'slot' => 'front_mockup',
        ]))->assertOk()->assertStreamedContent('SAMPLE-BYTES');
    }

    public function test_the_sample_sheet_can_hold_several_too(): void
    {
        Storage::fake('local');
        [$artist, $order] = $this->jobReadyForTheBatchSheet();

        $sample = $order->tasks()->where('department', 'Tech pack')->firstOrFail();
        $sample->update(['assigned_to' => $artist->id, 'status' => 'in_progress']);

        $this->actingAs($artist)->get(route('tasks.job-order', $sample))
            ->assertOk()
            // An order that skips the sample has no batch sheet at all, so the
            // carousel cannot live only there - its one tech pack IS the
            // production sheet. Every sheet can hold a kit now.
            ->assertSee('data-carousel', false);
    }
}
