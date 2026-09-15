<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\ProductionOrder;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The Tech Pack is not released until its sheet has been sent.
 *
 * The artist works FROM the job order: it is the officer's sheet, filled in
 * and handed over, and the Tech Pack page refuses to open until that has
 * happened. handleTaskCompleted has held the step back for exactly that reason
 * for a while — but unlockStage, which opens a whole stage at once, did not.
 *
 * So anything that unlocked the mockup stage while the mockup was already
 * approved — a waived downpayment, a payment confirmed by Finance — released
 * the Tech Pack too. The artist was handed a step their own queue said was
 * theirs, and the page behind it answered "not open yet". One real order sat
 * like that: the step in progress, the sheet still a draft.
 *
 * Both doors now have the same lock, and sending the sheet is what opens it.
 */
class TheTechPackWaitsForItsSheetTest extends TestCase
{
    use RefreshDatabase;

    private int $made = 0;

    /** An order whose layout and mockup are done, with the sheet still a draft. */
    private function orderReadyForItsPack(): ProductionOrder
    {
        $officer = User::factory()->create(['job_role' => User::ROLE_SALES, 'is_active' => true]);
        $artist = User::factory()->create(['job_role' => User::JOB_ARTIST, 'is_active' => true]);

        $order = ProductionOrder::create([
            'order_number' => 'IC2026-PACK'.(++$this->made),
            'client_id' => Client::create(['name' => 'Pack', 'last_name' => 'Client'])->id,
            'customer_name' => 'Pack Client',
            'product_type' => 'round_neck',
            'quantity' => 10,
            'unit_price' => 500,
            'due_date' => now()->addWeeks(2),
            'status' => 'active',
            'created_by' => $officer->id,
        ]);

        $order->items()->create(['size' => 'M', 'quantity' => 10]);
        $order->jobOrder()->create(['status' => 'draft', 'created_by' => $officer->id]);
        $order->refresh()->buildPipeline([], 'manual');

        // The drawing steps are done; only the pack is left in this stage.
        $order->tasks()
            ->whereIn('department', ['Layout', 'Final mockup'])
            ->update(['status' => 'complete', 'assigned_to' => $artist->id, 'approved_at' => now()]);

        return $order->refresh();
    }

    private function techPack(ProductionOrder $order): Task
    {
        return $order->tasks()->get()->first(fn (Task $t) => $t->isTechPackStep());
    }

    public function test_unlocking_the_stage_does_not_hand_over_a_pack_nobody_sent(): void
    {
        $order = $this->orderReadyForItsPack();

        $this->assertSame('draft', $order->jobOrder->status);

        // This is what a waived downpayment and a confirmed payment both do.
        $order->unlockStage(ProductionOrder::STAGE_MOCKUP);

        $this->assertSame('todo', $this->techPack($order->refresh())->status,
            'the pack was released while its sheet was still a draft');
    }

    public function test_sending_the_sheet_is_what_releases_it(): void
    {
        $order = $this->orderReadyForItsPack();
        $order->unlockStage(ProductionOrder::STAGE_MOCKUP);

        $this->assertSame('todo', $this->techPack($order->refresh())->status);

        // What JobOrderController::sendToArtist does.
        $order->jobOrder->update(['status' => 'sent_to_artist', 'sent_to_artist_at' => now()]);
        $order->refresh()->unlockStage(ProductionOrder::STAGE_MOCKUP);

        $this->assertSame('ready', $this->techPack($order->refresh())->status,
            'sending the sheet did not release the pack');
    }

    /**
     * The step the artist can see is the step the artist can open. That is the
     * whole point of holding it: the two used to disagree.
     */
    public function test_a_released_pack_is_one_the_artist_can_actually_open(): void
    {
        $order = $this->orderReadyForItsPack();
        $order->unlockStage(ProductionOrder::STAGE_MOCKUP);

        $pack = $this->techPack($order->refresh());
        $artist = User::factory()->create(['job_role' => User::JOB_ARTIST, 'is_active' => true]);
        $pack->update(['assigned_to' => $artist->id]);

        // Held: not on their bench, and the page says why rather than opening.
        $this->assertSame('todo', $pack->fresh()->status);
        $this->actingAs($artist)->get(route('tasks.job-order', $pack->id))
            ->assertRedirect()
            ->assertSessionHasErrors('tech_pack');

        $order->jobOrder->update(['status' => 'sent_to_artist', 'sent_to_artist_at' => now()]);
        $order->refresh()->unlockStage(ProductionOrder::STAGE_MOCKUP);

        $this->assertSame('ready', $pack->fresh()->status);
        $this->actingAs($artist)->get(route('tasks.job-order', $pack->id))->assertOk();
    }

    /** The message names the one thing outstanding, not the downpayment always. */
    public function test_the_refusal_names_what_is_actually_outstanding(): void
    {
        $order = $this->orderReadyForItsPack();
        $order->forceFill(['downpayment_waived' => true])->save();

        $pack = $this->techPack($order->refresh());
        $artist = User::factory()->create(['job_role' => User::JOB_ARTIST, 'is_active' => true]);
        $pack->update(['assigned_to' => $artist->id]);

        $this->actingAs($artist)->get(route('tasks.job-order', $pack->id))->assertRedirect();

        $said = session('errors')->get('tech_pack')[0];

        // "the tech pack", not "the job order". The job order SHEET is gone -
        // the officer fills their half of the tech pack and sends THAT - and
        // this message was the last place still naming a document the artist
        // cannot open, which left them waiting on a thing that does not exist.
        // The artist's card already said "tech pack"; now they agree.
        $this->assertStringContainsString('has not sent the tech pack', $said);
        $this->assertStringNotContainsString('downpayment', $said,
            'it sent the artist to chase a deposit that was already waived');
        $this->assertStringContainsString('Pack Client', $said,
            'several orders share a job order number now, so the number alone does not say which job');
    }
}
