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
 * officer's desk to the floor and out the door — the artist's complete pack,
 * the officer and leader sign-offs, the materials it asks for, and what every
 * station downstream reads off it.
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
            // Watches the account and says whether the money landed.
            'finance' => User::ROLE_FINANCE,
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
    private function close(string $department, ?int $stage = null): Task
    {
        // Pairing, Sewing and Quality control each happen twice — once for the
        // sample and once for the batch — so the batch half is named by stage.
        $task = $this->order->tasks()
            ->where('department', $department)
            ->when($stage !== null, fn ($q) => $q->where('stage', $stage))
            ->firstOrFail();

        $task->update(['status' => 'complete', 'approved_at' => now(), 'released_at' => now()]);
        $this->order->refresh()->handleTaskCompleted($task->fresh());

        return $task->fresh();
    }

    /** Every step still open at this stage, in the order the shop works them. */
    private function closeStage(int $stage): void
    {
        $open = $this->order->fresh()->tasks()
            ->where('stage', $stage)
            ->whereNotIn('status', ['complete', 'cancelled'])
            ->orderBy('sequence')
            ->pluck('department')
            ->all();

        foreach ($open as $department) {
            $this->close($department, $stage);
        }
    }

    public function test_a_job_walks_from_the_officers_desk_to_the_door(): void
    {
        Storage::fake('local');

        // ---- 1. The client's own reference, before anybody draws -------------
        // The artist works from what the client sent, so it is on file first.
        $this->actingAs($this->staff['sales'])
            ->post(route('job-orders.reference', $this->order), [
                'reference_files' => [UploadedFile::fake()->image('client-peg.jpg')],
                'kind' => 'peg',
            ])->assertRedirect();

        // ---- 2. The artist draws the layout, and the client approves it ------
        $this->order->unlockStage(1);
        $this->close('Layout');

        // Approving the layout releases NOTHING on its own. The shop draws the
        // final mockup for a client who has paid, so the job waits here.
        $this->assertSame(
            'todo',
            $this->order->fresh()->tasks()->where('department', 'Final mockup')->value('status'),
            'the mockup went to the artist before the client had paid a peso'
        );

        // ---- 3. The downpayment, and the desk that confirms it --------------
        $this->actingAs($this->staff['sales'])
            ->post(route('orders.payment', $this->order), [
                // Nothing is recorded on somebody's word: the shop keeps a
                // picture of every payment against the order.
                'portion' => 'half', 'method' => 'Cash',
                'proof' => UploadedFile::fake()->image('deposit-slip.jpg'),
            ]);

        // Recorded is not received. What the officer wrote down is what the
        // client told them; the shop does not draw on it yet.
        $this->assertFalse($this->order->fresh()->hasDownpayment(),
            'the claim counted as money before anybody checked the account');
        $this->assertTrue($this->order->fresh()->hasPaymentAwaitingFinance());

        $this->assertSame(
            'todo',
            $this->order->fresh()->tasks()->where('department', 'Final mockup')->value('status'),
            'the artist was sent the mockup on a payment nobody had confirmed'
        );

        $payment = $this->order->fresh()->payments()->firstOrFail();

        // The officer cannot wave their own payment through.
        $this->actingAs($this->staff['sales'])
            ->post(route('finance.confirm', $payment), ['confirmed_name' => 'Rey'])
            ->assertForbidden();

        $this->actingAs($this->staff['finance'])
            ->post(route('finance.confirm', $payment), ['confirmed_name' => 'Rey'])
            ->assertRedirect();

        $this->assertTrue($this->order->fresh()->hasDownpayment(),
            'finance confirmed it and the order still says nothing is paid');

        $this->assertSame(
            'ready',
            $this->order->fresh()->tasks()->where('department', 'Final mockup')->value('status'),
            'the money is confirmed and the artist has not been asked for the mockup'
        );

        // The Tech Pack is NOT released with it. It becomes available only
        // after the final mockup has been approved and the officer sends it.
        $this->assertSame(
            'todo',
            $this->order->fresh()->tasks()->where('department', 'Tech pack')->value('status'),
            'the pack reached the artist before mockup approval'
        );

        // ---- 4. The artist draws the mockup, and the client approves it ------
        $this->close('Final mockup');

        $this->assertTrue($this->order->fresh()->mockupApproved(),
            'the pack must not open before the client has approved the design');

        // ---- 5. The officer sends a blank pack to the assigned artist -------

        $this->actingAs($this->staff['sales'])
            ->get(route('orders.job-order', $this->order))
            ->assertOk()
            ->assertDontSee('name="design_name"', false)
            ->assertDontSee('name="printer"', false)
            ->assertDontSee('name="tech_pack_images[front_mockup]"', false);

        // The money is in and the mockup is approved, so it can go.
        $sent = $this->actingAs($this->staff['sales'])
            ->post(route('job-orders.send', $this->order));

        $this->assertSame('sent_to_artist', $this->order->fresh()->jobOrder->status,
            'refused to send: '.json_encode(session('errors')?->all() ?? []));

        $packTask = $this->order->fresh()->tasks()->where('department', 'Tech pack')->firstOrFail();
        $packTask->update(['assigned_to' => $this->staff['artist']->id, 'status' => 'in_progress']);

        $this->actingAs($this->staff['artist'])
            ->get(route('tasks.job-order', $packTask->id))
            ->assertOk()
            ->assertSee('name="tech_pack_images[front_mockup]"', false);

        $this->actingAs($this->staff['artist'])
            ->post(route('tasks.tech-pack', $packTask->id), [
                'design_name' => 'Walkthrough Tee',
                'fitting' => 'Original fit',
                'item_style' => 'Cotton shirt',
                'quality' => 'Premium',
                'print_type' => 'dtf',
                'printer' => 'dtf_printer',
                'fabric' => 'Cotton blend',
                'neck' => 'Round neck',
                'cuff_arm_sleeves' => 'Tupi',
                'print_label' => 'IC DTF original fit',
                'neck_label' => 'IC woven label',
                'tshirt_color' => 'Black',
                'thread_color' => 'Black',
                'stitch_thread' => 'Polyester 120',
                'cutting_method' => 'Straight cut',
                'packaging' => 'Polybag',
                'zipper_type' => 'N/A',
                'bottom_hem' => 'Straight hem',
                'lip_pocket_color' => 'N/A',
                'size_range' => 'S-XL',
                'free_logo_sticker' => 'IC sticker',
                'tech_pack_images' => [
                    'front_mockup' => UploadedFile::fake()->image('mockup.png'),
                    'front_artwork' => UploadedFile::fake()->image('art.png'),
                ],
                'file_location_notes' => 'FOR PRINT\\IC2026-WALK',
                // The things built in this session: a note, a moved box, a line.
                'add_note_box' => 1,
                'box_positions' => ['front_artwork' => ['x' => 8, 'y' => -3]],
                'image_sizes' => ['front_artwork' => ['w' => 20, 'h' => 12]],
                'callouts' => ['front_artwork' => ['fx' => 30, 'fy' => 20, 'tx' => 45, 'ty' => 35]],
            ])->assertRedirect()->assertSessionHasNoErrors();

        $pack = $this->order->fresh()->techPack;

        $this->assertArrayHasKey('front_mockup', $pack->image_uploads);
        Storage::disk('local')->assertExists($pack->image_uploads['front_mockup']['path']);
        $this->assertSame('FOR PRINT\IC2026-WALK', $pack->file_location_notes);
        $this->assertSame(['x' => 8.0, 'y' => -3.0], $pack->boxPosition('front_artwork'));
        $this->assertSame(['w' => 20.0, 'h' => 12.0], $pack->imageSize('front_artwork'));
        $this->assertSame(['x' => 45.0, 'y' => 35.0], $pack->callouts()['front_artwork']['to']);
        $this->assertCount(1, $pack->extraNotes());

        $this->assertSame('Walkthrough Tee', $pack->design_name);
        $this->assertSame('Premium', $pack->quality);
        $this->assertSame('Cotton blend', $this->order->fresh()->jobOrder->fabric);

        // Raw-material quantities and machine routing remain production-detail
        // work; they are not manual boxes on the printed Tech Pack.
        $this->actingAs($this->staff['sales'])
            ->post(route('job-orders.production.update', $this->order), [
                'raw_materials' => ['Cotton shirt blank'],
                'raw_material_qty' => [55],
                'cutting_type' => 'manual',
                'fabric_press' => 'small_press',
            ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame(55.0, $this->order->fresh()->jobOrder->rawMaterialQuantity('Cotton shirt blank'),
            'the amount the desk is allowed to issue rides with the material');

        // ---- 6. Artist → account officer → leader ---------------------------
        $this->actingAs($this->staff['artist'])
            ->post(route('tasks.submit', $packTask))->assertRedirect()->assertSessionHasNoErrors();

        $this->actingAs($this->staff['sales'])
            ->post(route('tasks.approve', $packTask))->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame('leader', $packTask->fresh()->approver_role);
        $this->assertSame('for_checking', $packTask->fresh()->status);

        $this->actingAs($this->staff['leader'])
            ->post(route('tasks.approve-package', $this->order))->assertRedirect();

        $this->assertSame('complete', $packTask->fresh()->status,
            'the leader signs off the pack, and that is what opens production');

        // ---- 6. Materials: the desk issues what the job asked for -----------
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

        // ---- 7. The floor reads the pack at its station ---------------------
        $this->actingAs($this->staff['printer'])
            ->get(route('orders.package', [$this->order, 'for' => 'printer']))
            ->assertOk()
            ->assertSee('PRINT FILES')
            ->assertSee('FOR PRINT\\IC2026-WALK')
            ->assertSee('Walkthrough Tee');

        // A station that does not open files is not handed a network path.
        $this->actingAs($this->staff['supply'])
            ->get(route('orders.package', [$this->order, 'for' => 'production']))
            ->assertOk()
            ->assertDontSee('PRINT FILES');

        // ---- 8. The sample is received before it is stock -------------------
        $this->order->fresh()->stockFirstSample();

        $receipt = ProductReceipt::where('production_order_id', $this->order->id)->firstOrFail();
        $this->assertSame('pending', $receipt->status);
        $this->assertSame(0, ProductItem::count(), 'nothing on the shelf until somebody receives it');

        $this->actingAs($this->staff['inventory'])
            ->post(route('products.receive', $receipt), ['operator_name' => 'Rowena'])
            ->assertRedirect();

        $this->assertSame(1.0, (float) ProductItem::first()->quantity);

        // ---- 9. The floor works the sample through --------------------------
        // Materials issued, the design printed and pressed, the sticker run if
        // the job has one — then the one piece is cut, paired, sewn and checked.
        // Each of these is what releases the next: closing them in order is the
        // point of walking it rather than placing an order mid-pipeline.
        $this->closeStage(3);

        foreach ([5, 6, 7, 8] as $stage) {
            $this->closeStage($stage);
        }

        // ---- 10. The client approves the sample, and the batch is made -------
        // Nobody works this step — it lands on Sample Review and the officer
        // answers for the client.
        $this->close('Produce sample for client');

        $this->assertSame(
            'ready',
            $this->order->fresh()->tasks()->where('department', 'Mass production')->value('status'),
            'the client said yes and nothing was released to make the rest'
        );

        // 10 — the whole batch printed, then pressed onto the cloth.
        $this->closeStage(10);

        // ---- 11. The batch walks the same line the sample did ---------------
        foreach ([11, 12, 13, 14] as $stage) {
            $this->closeStage($stage);
        }

        // ---- 12. Counted into finished goods -------------------------------
        // Receiving the sample already put the inventory desk to work, so this
        // step may be closed before the batch ever gets here. Either way the
        // goods are counted in before anything reaches the counter.
        $inventory = $this->order->fresh()->tasks()->where('department', 'Inventory')->firstOrFail();

        $this->assertNotSame('todo', $inventory->status, 'the inventory desk was never told the batch was coming');

        if ($inventory->status !== 'complete') {
            $this->close('Inventory');
        }

        // ---- 13. The counter hands it over ---------------------------------
        // Release carries auto_submit: nobody works it, so it arrives on the
        // finished-products desk the moment stock is counted in.
        $release = $this->order->fresh()->tasks()->where('department', 'Release to client')->firstOrFail();

        $this->assertContains(
            $release->status,
            ['ready', 'for_checking'],
            'the goods are counted in and nothing is waiting at the counter'
        );

        $this->close('Release to client');

        // ---- 14. And the job is done ---------------------------------------
        $this->order->refresh();

        $this->assertSame(
            0,
            $this->order->tasks()->whereNotIn('status', ['complete', 'cancelled'])->count(),
            'the client has their goods and the board still shows work outstanding'
        );

        $this->assertTrue(
            in_array($this->order->status, ['completed', 'complete'], true),
            'every step is done and the order has not closed: status is '.$this->order->status
        );
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
        ] as $what => $url) {
            $this->actingAs($this->staff['sales'])->get($url)
                ->assertOk("$what should open")
                ->assertSee('Walkthrough Tee');
        }

        $this->actingAs($this->staff['sales'])
            ->get(route('job-orders.edit', $this->order))
            ->assertRedirect(route('orders.job-order', $this->order));

        // A box taken off stays off, and the note stays on.
        $this->actingAs($this->staff['sales'])->get(route('orders.job-order', $this->order))
            ->assertDontSee('tp_preview_tag_2', false)
            ->assertSee('A note the sheet has no row for');
    }
}
