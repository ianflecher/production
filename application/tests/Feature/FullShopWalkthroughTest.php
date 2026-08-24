<?php

namespace Tests\Feature;

use App\Models\InventoryItem;
use App\Models\MaterialRequest;
use App\Models\ProductionOrder;
use App\Models\ProductItem;
use App\Models\ProductReceipt;
use App\Models\Task;
use App\Models\TechPack;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * One job, walked the way the shop walks it, through the pages people use.
 *
 * WholePipelineTest already proves the steps close in the right order. This one
 * asks a different question: does the TECH PACK carry a job from the account
 * officer's desk to the floor and out the door — the officer's half, the
 * artist's half, the leader's sign-off, the materials it asks for, and what
 * every station downstream reads off it.
 */
class FullShopWalkthroughTest extends TestCase
{
    use RefreshDatabase;

    private array $staff = [];

    private ProductionOrder $order;

    protected function setUp(): void
    {
        parent::setUp();

        foreach ([
            'sales' => User::ROLE_SALES,
            'artist' => User::JOB_ARTIST,
            'leader' => User::ROLE_LEADER,
            'supply' => 'Raw Materials',
            'printer' => 'printer',
            // The finished-goods desk: the materials desk issues cloth, this
            // one counts garments in and hands them over.
            'inventory' => 'Inventory',
        ] as $who => $role) {
            $this->staff[$who] = User::factory()->create([
                'job_role' => $role, 'is_active' => true, 'name' => ucfirst($who),
            ]);
        }

        $this->order = ProductionOrder::create([
            'order_number' => 'IC2026-WALK', 'customer_name' => 'Walkthrough Co',
            'product_type' => 'round_neck', 'quantity' => 55,
            'due_date' => now()->addWeeks(2), 'unit_price' => 350,
            'created_by' => $this->staff['sales']->id, 'status' => 'active',
        ]);

        foreach (['S' => 10, 'M' => 20, 'L' => 15, 'XL' => 10] as $size => $qty) {
            $this->order->items()->create(['size' => $size, 'quantity' => $qty]);
        }

        $this->order->jobOrder()->create([
            'status' => 'draft', 'created_by' => $this->staff['sales']->id,
        ]);

        // A price on the order, the way the officer sets one at intake — the
        // shop will not take a payment against a job with no price.
        $this->order->refresh()->recomputeTotal();
        $this->order->refresh()->buildPipeline([], 'manual');
    }

    /** Push a step to done without going through its station. */
    private function close(string $department): Task
    {
        $task = $this->order->tasks()->where('department', $department)->firstOrFail();
        $task->update(['status' => 'complete', 'approved_at' => now(), 'released_at' => now()]);
        $this->order->refresh()->handleTaskCompleted($task->fresh());

        return $task->fresh();
    }

    public function test_a_job_walks_from_the_officers_desk_to_the_door(): void
    {
        Storage::fake('local');

        // ---- 1. The design, approved by the client --------------------------
        $this->order->unlockStage(1);
        $this->close('Layout');
        $this->order->refresh()->unlockStage(2);
        $this->close('Final mockup');

        $this->assertTrue($this->order->fresh()->mockupApproved(),
            'the pack must not open before the client has approved the design');

        // ---- 2. The officer fills their half of the PACK --------------------
        $this->order->refresh()->unlockStage(2);

        $this->actingAs($this->staff['sales'])
            ->get(route('job-orders.edit', $this->order))
            ->assertOk()
            ->assertSee('name="design_name"', false)
            ->assertSee('name="printer"', false)
            // The artist's half is not theirs to fill.
            ->assertDontSee('name="tech_pack_images[front_mockup]"', false);

        $this->actingAs($this->staff['sales'])
            ->post(route('job-orders.update', $this->order), [
                'design_name' => 'Walkthrough Tee',
                'fitting' => 'Original fit',
                'item_style' => 'Cotton shirt',
                'quality' => 'Premium',
                'print_type' => 'DTF',
                'printer' => 'dtf_printer',
                'fabric' => 'Cotton blend',
                'neck' => 'Round neck',
                'packaging' => 'Polybag',
                'free_logo_sticker' => 'IC sticker',
                'color_1' => 'Black',
                'tshirt_color' => 'Black',
                'size_range' => 'S-XL',
                'raw_materials' => ['Cotton shirt blank'],
                'raw_material_qty' => [55],
            ])->assertRedirect()->assertSessionHasNoErrors();

        $pack = $this->order->fresh()->techPack;
        $this->assertSame('Walkthrough Tee', $pack->design_name);
        $this->assertSame('Premium', $pack->quality);
        $this->assertSame('Cotton blend', $this->order->fresh()->jobOrder->fabric);
        $this->assertSame(55.0, $this->order->fresh()->jobOrder->rawMaterialQuantity('Cotton shirt blank'),
            'the amount the desk is allowed to issue rides with the material');

        // ---- 3. The money, the client's reference, then off to the artist ---
        // Nothing reaches the artist on a promise: the downpayment is recorded
        // and the client's own reference is on file first.
        $paid = $this->actingAs($this->staff['sales'])
            ->post(route('orders.payment', $this->order), [
                // Nothing is recorded on somebody's word: the shop keeps a
                // picture of every payment against the order.
                'portion' => 'half', 'method' => 'Cash',
                'proof' => UploadedFile::fake()->image('deposit-slip.jpg'),
            ]);

        $this->assertTrue($this->order->fresh()->hasDownpayment(),
            'payment refused: '.json_encode(session('errors')?->all() ?? []));

        $this->actingAs($this->staff['sales'])
            ->post(route('job-orders.reference', $this->order), [
                'reference_files' => [UploadedFile::fake()->image('client-peg.jpg')],
                'kind' => 'peg',
            ])->assertRedirect();

        $sent = $this->actingAs($this->staff['sales'])
            ->post(route('job-orders.send', $this->order));

        $this->assertSame('sent_to_artist', $this->order->fresh()->jobOrder->status,
            'refused to send: '.json_encode(session('errors')?->all() ?? []));

        $packTask = $this->order->fresh()->tasks()->where('department', 'Tech pack')->firstOrFail();
        $packTask->update(['assigned_to' => $this->staff['artist']->id, 'status' => 'in_progress']);

        $this->actingAs($this->staff['artist'])
            ->get(route('tasks.job-order', $packTask->id))
            ->assertOk()
            ->assertSee('name="tech_pack_images[front_mockup]"', false)
            ->assertSee('Walkthrough Tee');

        $this->actingAs($this->staff['artist'])
            ->post(route('tasks.tech-pack', $packTask->id), [
                'tech_pack_images' => [
                    'front_mockup' => UploadedFile::fake()->image('mockup.png'),
                    'front_artwork' => UploadedFile::fake()->image('art.png'),
                ],
                'front_print_placement' => 'Left chest',
                'front_actual_size' => '4.0" W x 2.3" H',
                'file_location_host' => 'IC-SERVER',
                'file_location_tail' => 'FOR PRINT\\IC2026-WALK',
                // The things built in this session: a note, a moved box, a line.
                'add_note_box' => 1,
                'box_positions' => ['front_artwork' => ['x' => 8, 'y' => -3]],
                'image_sizes' => ['front_artwork' => ['w' => 20, 'h' => 12]],
                'callouts' => ['front_artwork' => ['fx' => 30, 'fy' => 20, 'tx' => 45, 'ty' => 35]],
            ])->assertRedirect()->assertSessionHasNoErrors();

        $pack = $this->order->fresh()->techPack;

        $this->assertArrayHasKey('front_mockup', $pack->image_uploads);
        Storage::disk('local')->assertExists($pack->image_uploads['front_mockup']['path']);
        $this->assertSame('\\\\IC-SERVER\FOR PRINT\IC2026-WALK', $pack->file_location_notes);
        $this->assertSame(['x' => 8.0, 'y' => -3.0], $pack->boxPosition('front_artwork'));
        $this->assertSame(['w' => 20.0, 'h' => 12.0], $pack->imageSize('front_artwork'));
        $this->assertSame(['x' => 45.0, 'y' => 35.0], $pack->callouts()['front_artwork']['to']);
        $this->assertCount(1, $pack->extraNotes());

        // ---- 4. The leader signs off the pack -------------------------------
        $packTask->update(['status' => 'for_checking', 'submitted_at' => now()]);

        $this->actingAs($this->staff['leader'])
            ->post(route('tasks.approve-package', $this->order))->assertRedirect();

        $this->assertSame('complete', $packTask->fresh()->status,
            'the leader signs off the pack, and that is what opens production');

        // ---- 5. Materials: the desk issues what the job asked for -----------
        $item = InventoryItem::create(['name' => 'Cotton shirt blank', 'unit' => 'pcs', 'quantity' => 500]);
        $request = MaterialRequest::where('production_order_id', $this->order->id)->firstOrFail();

        $this->assertSame('55.00', $request->requested_quantity,
            'the request carries the amount, so the desk is not typing it from memory');

        $this->actingAs($this->staff['supply'])
            ->post(route('inventory.requests.approve', $request), [
                'inventory_item_id' => $item->id,
                'quantity' => 100,               // a stale tab, or a slip
                'operator_name' => 'Ian',
            ])->assertRedirect();

        $this->assertSame('55.00', $request->fresh()->issued_quantity, 'the job asked for 55');
        $this->assertSame(445.0, (float) $item->fresh()->quantity, 'so 55 left the shelf');

        // ---- 6. The floor reads the pack at its station ---------------------
        $this->actingAs($this->staff['printer'])
            ->get(route('orders.package', [$this->order, 'for' => 'printer']))
            ->assertOk()
            ->assertSee('PRINT FILES')
            ->assertSee('IC-SERVER')
            ->assertSee('Walkthrough Tee');

        // A station that does not open files is not handed a network path.
        $this->actingAs($this->staff['supply'])
            ->get(route('orders.package', [$this->order, 'for' => 'production']))
            ->assertOk()
            ->assertDontSee('PRINT FILES');

        // ---- 7. The sample is received before it is stock -------------------
        $this->order->fresh()->stockFirstSample();

        $receipt = ProductReceipt::where('production_order_id', $this->order->id)->firstOrFail();
        $this->assertSame('pending', $receipt->status);
        $this->assertSame(0, ProductItem::count(), 'nothing on the shelf until somebody receives it');

        $this->actingAs($this->staff['inventory'])
            ->post(route('products.receive', $receipt), ['operator_name' => 'Rowena'])
            ->assertRedirect();

        $this->assertSame(1.0, (float) ProductItem::first()->quantity);
    }

    public function test_every_page_of_the_pack_opens_for_everyone_who_reads_it(): void
    {
        $this->order->unlockStage(1);
        $this->close('Layout');
        $this->order->refresh()->unlockStage(2);
        $this->close('Final mockup');

        $this->order->techPack()->create([
            'design_name' => 'Walkthrough Tee',
            'file_location_notes' => '\\\\IC-SERVER\FOR PRINT',
            'extra_notes' => ['A note the sheet has no row for'],
            'callouts' => ['tag_1' => ['fx' => 10, 'fy' => 10, 'tx' => 40, 'ty' => 30]],
            'hidden_boxes' => ['tag_2'],
            'box_positions' => ['tag_1' => ['x' => 3, 'y' => 4]],
        ]);

        foreach ([
            'the read-only pack' => route('orders.job-order', $this->order),
            'the full document' => route('orders.package', $this->order),
            "the officer's copy" => route('job-orders.edit', $this->order),
        ] as $what => $url) {
            $this->actingAs($this->staff['sales'])->get($url)
                ->assertOk("$what should open")
                ->assertSee('Walkthrough Tee');
        }

        // A box taken off stays off, and the note stays on.
        $this->actingAs($this->staff['sales'])->get(route('orders.job-order', $this->order))
            ->assertDontSee('tp_preview_tag_2', false)
            ->assertSee('A note the sheet has no row for');
    }
}
