<?php

namespace Tests\Feature;

use App\Models\ProductionOrder;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A tech pack an artist holds but cannot open goes back to TODO.
 *
 * Two live orders were in that state — IC2026-00009 and IC2026-01174, both
 * Mick's, both IN PROGRESS against a job order still at draft. The step reads
 * as being worked on, by somebody who cannot see it, on every board in the
 * shop: not waiting anywhere anybody would chase it, and not moving either.
 *
 * The gate in TaskController::start() stops new ones. This is the sweep for
 * the ones already in it, and it must be narrow — a command that resets tasks
 * on live is only safe if it touches exactly the shape it was written for.
 */
class StuckTechPacksGoBackToTodoTest extends TestCase
{
    use RefreshDatabase;

    private function artist(): User
    {
        return User::factory()->create(['job_role' => User::JOB_ARTIST, 'is_active' => true]);
    }

    private function techPack(string $taskStatus, string $jobOrderStatus, string $department = 'Tech pack'): Task
    {
        $officer = User::factory()->create(['job_role' => User::ROLE_SALES, 'is_active' => true]);

        $order = ProductionOrder::create([
            'order_number' => 'IC2026-0'.random_int(1000, 9999),
            'customer_name' => 'Stuck Co',
            'product_type' => 'round_neck',
            'quantity' => 20,
            'due_date' => now()->addWeeks(2),
            'created_by' => $officer->id,
            'status' => 'active',
        ]);

        $order->jobOrder()->create(['status' => $jobOrderStatus, 'created_by' => $officer->id]);

        return Task::create([
            'production_order_id' => $order->id,
            'department' => $department,
            'team' => User::JOB_ARTIST,
            'stage' => 2,
            'sequence' => 20,
            'status' => $taskStatus,
            'assigned_to' => $this->artist()->id,
            'approver_role' => 'sales',
        ]);
    }

    /* ---------------- it reports before it writes ---------------- */

    public function test_it_changes_nothing_without_fix(): void
    {
        $task = $this->techPack('in_progress', 'draft');

        $this->artisan('tech-packs:stuck')
            ->expectsOutputToContain('1 tech pack step(s) an artist cannot open')
            ->expectsOutputToContain('Run again with --fix')
            ->assertSuccessful();

        $this->assertSame('in_progress', $task->fresh()->status);
    }

    public function test_it_puts_a_stuck_step_back_to_todo(): void
    {
        $task = $this->techPack('in_progress', 'draft');

        $this->artisan('tech-packs:stuck --fix')->assertSuccessful();

        $this->assertSame('todo', $task->fresh()->status);
    }

    /**
     * The artist keeps it. unlockStage() reuses whoever was already given the
     * work, and clearing this would hand the job to somebody else when the
     * officer finally sends the pack.
     */
    public function test_it_leaves_the_artist_on_the_step(): void
    {
        $task = $this->techPack('in_progress', 'draft');
        $artist = $task->assigned_to;

        $this->artisan('tech-packs:stuck --fix')->assertSuccessful();

        $this->assertSame($artist, $task->fresh()->assigned_to);
    }

    public function test_it_is_safe_to_run_twice(): void
    {
        $task = $this->techPack('in_progress', 'draft');

        $this->artisan('tech-packs:stuck --fix')->assertSuccessful();
        $this->artisan('tech-packs:stuck --fix')
            ->expectsOutputToContain('No stuck tech packs')
            ->assertSuccessful();

        $this->assertSame('todo', $task->fresh()->status);
    }

    /* ---------------- what it must NOT touch ---------------- */

    /** A pack that HAS been sent is the artist's work, in progress, correctly. */
    public function test_it_leaves_a_sent_pack_alone(): void
    {
        $task = $this->techPack('in_progress', 'sent_to_artist');

        $this->artisan('tech-packs:stuck --fix')
            ->expectsOutputToContain('No stuck tech packs')
            ->assertSuccessful();

        $this->assertSame('in_progress', $task->fresh()->status);
    }

    /**
     * Submitted work is on an approver's desk, not the artist's. Pulling it
     * back to TODO would take it off somebody's review list.
     */
    public function test_it_leaves_a_submitted_pack_alone(): void
    {
        $task = $this->techPack('for_checking', 'draft');

        $this->artisan('tech-packs:stuck --fix')->assertSuccessful();

        $this->assertSame('for_checking', $task->fresh()->status);
    }

    public function test_it_leaves_finished_work_alone(): void
    {
        $task = $this->techPack('complete', 'draft');

        $this->artisan('tech-packs:stuck --fix')->assertSuccessful();

        $this->assertSame('complete', $task->fresh()->status);
    }

    /** And it is not a general-purpose task reset: only the pack steps. */
    public function test_it_leaves_other_departments_alone(): void
    {
        $task = $this->techPack('in_progress', 'draft', 'Final mockup');

        $this->artisan('tech-packs:stuck --fix')
            ->expectsOutputToContain('No stuck tech packs')
            ->assertSuccessful();

        $this->assertSame('in_progress', $task->fresh()->status);
    }

    /** The mass-production pack is the same step on a later stage. */
    public function test_it_catches_the_mass_production_pack_too(): void
    {
        $task = $this->techPack('in_progress', 'draft', 'Tech pack (mass production)');

        $this->artisan('tech-packs:stuck --fix')->assertSuccessful();

        $this->assertSame('todo', $task->fresh()->status);
    }

    /* ---------------- and then it opens by itself ---------------- */

    /**
     * The point of TODO rather than anything else: sending the pack releases
     * it again, to the same artist, with nobody having to remember.
     */
    public function test_a_reset_step_opens_again_when_the_pack_is_sent(): void
    {
        $task = $this->techPack('in_progress', 'draft');
        $artist = $task->assigned_to;

        $this->artisan('tech-packs:stuck --fix')->assertSuccessful();
        $this->assertSame('todo', $task->fresh()->status);

        $order = $task->order;
        $order->jobOrder->update(['status' => 'sent_to_artist', 'sent_to_artist_at' => now()]);
        $order->unlockStage(2);

        $task->refresh();

        $this->assertSame('ready', $task->status, 'sending the pack did not open it again');
        $this->assertSame($artist, $task->assigned_to);
    }
}
