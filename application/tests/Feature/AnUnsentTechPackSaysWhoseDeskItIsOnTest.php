<?php

namespace Tests\Feature;

use App\Models\ProductionOrder;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A tech pack nobody has sent says whose desk it is on.
 *
 * What the artist was given was "This step is blocked" - which names nobody,
 * offers nothing to do, and does not say whether anybody is coming. The pack
 * itself, when they got that far, blamed the downpayment whatever the real
 * reason was.
 *
 * The worse half was that the step could be STARTED. Nothing guarded the
 * Open Tech Pack button, so it flipped the step to in_progress and the pack
 * then refused to open: the job read as being worked on, by an artist who
 * could not see it, on every board in the shop. Two live orders were sitting
 * in exactly that state - IC2026-00009 and IC2026-01174, both Mick's, both
 * in_progress against a job order still at draft.
 *
 * The release gate in unlockStage() has held these steps back for a while.
 * That is not enough on its own: a step can reach an artist by other routes -
 * released before that gate existed, or sent back for revision - and this is
 * the end that decides whether they can act on it.
 */
class AnUnsentTechPackSaysWhoseDeskItIsOnTest extends TestCase
{
    use RefreshDatabase;

    private function artist(): User
    {
        return User::factory()->create(['job_role' => User::JOB_ARTIST, 'is_active' => true]);
    }

    private function officer(): User
    {
        return User::factory()->create(['job_role' => User::ROLE_SALES, 'is_active' => true]);
    }

    /**
     * An order with a tech pack step sitting on the artist's desk.
     *
     * Built at 'ready' on purpose: this is the shape the gate in unlockStage()
     * is meant to prevent, and the point of these tests is what happens when
     * something reaches the artist anyway.
     */
    private function orderWithTechPackStep(User $artist, string $taskStatus = 'ready'): Task
    {
        $officer = $this->officer();

        $order = ProductionOrder::create([
            'order_number' => 'IC2026-09090',
            'customer_name' => 'Consignee Co',
            'product_type' => 'round_neck',
            'quantity' => 40,
            'due_date' => now()->addWeeks(3),
            'created_by' => $officer->id,
            'status' => 'active',
        ]);

        $order->jobOrder()->create(['status' => 'draft', 'created_by' => $officer->id]);

        return Task::create([
            'production_order_id' => $order->id,
            'department' => 'Tech pack',
            'team' => User::JOB_ARTIST,
            'stage' => 2,
            'sequence' => 20,
            'status' => $taskStatus,
            'assigned_to' => $artist->id,
            'approver_role' => 'sales',
        ]);
    }

    /* ---------------- the one answer ---------------- */

    public function test_the_order_says_what_the_pack_is_waiting_for(): void
    {
        $task = $this->orderWithTechPackStep($this->artist());
        $order = $task->order;

        // Nothing has happened yet, so the mockup is the first thing missing.
        $this->assertSame('the final mockup has not been approved yet', $order->techPackWaitingOn());
    }

    /** Once it has been sent there is nothing to report. */
    public function test_a_sent_pack_is_waiting_for_nothing(): void
    {
        $task = $this->orderWithTechPackStep($this->artist());
        $task->order->jobOrder->update(['status' => 'sent_to_artist', 'sent_to_artist_at' => now()]);

        $this->assertNull($task->order->fresh()->techPackWaitingOn());
    }

    /* ---------------- the artist cannot start what they cannot open ---------------- */

    /**
     * The bug itself. Pressing the button used to set the step to in_progress
     * and then bounce the artist off the pack, leaving the job reading as
     * under way with nothing behind it.
     */
    public function test_an_artist_cannot_start_a_tech_pack_that_has_not_been_sent(): void
    {
        $artist = $this->artist();
        $task = $this->orderWithTechPackStep($artist);

        $this->actingAs($artist)
            ->post(route('tasks.start', $task->id))
            ->assertSessionHasErrors('tech_pack');

        $this->assertSame('ready', $task->fresh()->status,
            'the step was started against a pack that will not open');
    }

    /** And the refusal says which thing is outstanding, not just "no". */
    public function test_the_refusal_names_what_is_outstanding(): void
    {
        $artist = $this->artist();
        $task = $this->orderWithTechPackStep($artist);

        $this->actingAs($artist)
            ->post(route('tasks.start', $task->id))
            ->assertSessionHasErrorsIn('default', ['tech_pack']);

        $this->assertStringContainsString(
            'the final mockup has not been approved yet',
            session('errors')->first('tech_pack')
        );
    }

    /** A step that is NOT a tech pack is untouched by any of this. */
    public function test_an_ordinary_step_still_starts(): void
    {
        $artist = $this->artist();
        $task = $this->orderWithTechPackStep($artist);

        $task->update(['department' => 'Final mockup', 'status' => 'ready']);

        $this->actingAs($artist)
            ->post(route('tasks.start', $task->id))
            ->assertSessionHasNoErrors();

        $this->assertSame('in_progress', $task->fresh()->status);
    }

    /* ---------------- what the artist reads ---------------- */

    public function test_the_step_page_names_the_account_officer(): void
    {
        $artist = $this->artist();
        $task = $this->orderWithTechPackStep($artist);

        $this->actingAs($artist)
            ->get(route('tasks.show', $task->id))
            ->assertOk()
            ->assertSee('Waiting for the account officer to send the tech pack')
            ->assertDontSee('This step is blocked');
    }

    /** Including one already stuck at in_progress, which is the live shape. */
    public function test_a_step_stuck_in_progress_still_explains_itself(): void
    {
        $artist = $this->artist();
        $task = $this->orderWithTechPackStep($artist, 'in_progress');

        $this->actingAs($artist)
            ->get(route('tasks.show', $task->id))
            ->assertOk()
            ->assertSee('Waiting for the account officer to send the tech pack');
    }

    /**
     * And it is not offered a submit form. Submitting was refused at the
     * controller anyway, so the form was an invitation to be told no.
     */
    public function test_a_stuck_step_is_not_offered_a_submit_form(): void
    {
        $artist = $this->artist();
        $task = $this->orderWithTechPackStep($artist, 'in_progress');

        $this->actingAs($artist)
            ->get(route('tasks.show', $task->id))
            ->assertOk()
            ->assertDontSee(route('tasks.submit', $task->id));
    }

    /**
     * The layout step must NOT claim to be waiting for a pack. There is no
     * pack at that point - the artist works from the design alone - and
     * saying otherwise invents a hold-up that is not there.
     */
    public function test_the_layout_step_is_not_told_it_is_waiting_for_a_pack(): void
    {
        $artist = $this->artist();
        $task = $this->orderWithTechPackStep($artist);

        $task->update(['department' => 'Layout', 'stage' => 1, 'status' => 'ready']);

        $this->actingAs($artist)
            ->get(route('tasks.show', $task->id))
            ->assertOk()
            ->assertDontSee('Waiting for the account officer to send the tech pack')
            ->assertSee('The design to make for this order');
    }

    /* ---------------- once it is sent ---------------- */

    public function test_a_sent_pack_can_be_opened_and_started(): void
    {
        $artist = $this->artist();
        $task = $this->orderWithTechPackStep($artist);
        $task->order->jobOrder->update(['status' => 'sent_to_artist', 'sent_to_artist_at' => now()]);

        $this->actingAs($artist)
            ->get(route('tasks.show', $task->id))
            ->assertOk()
            ->assertDontSee('Waiting for the account officer to send the tech pack')
            ->assertSee('Open Tech Pack', false);

        $this->actingAs($artist)
            ->post(route('tasks.start', $task->id))
            ->assertSessionHasNoErrors();

        $this->assertSame('in_progress', $task->fresh()->status);
    }
}
