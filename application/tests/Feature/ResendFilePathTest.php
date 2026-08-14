<?php

namespace Tests\Feature;

use App\Models\ProductionOrder;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Correcting a network path that has already gone to production.
 *
 * An export step completes the moment the path is handed over, so a typo — or a
 * file someone later moved or renamed — left production pointed at nothing,
 * with no way for the artist to put it right.
 */
class ResendFilePathTest extends TestCase
{
    use RefreshDatabase;

    private const GOOD = '\\\\192.168.150.233\\Designs\\IC2026-02200.tif';

    private const MOVED = '\\\\192.168.150.240\\Designs\\2026\\IC2026-02200-final.tif';

    /** @return array{0: User, 1: Task} an artist whose export step is done */
    private function sentExport(): array
    {
        $sales = User::factory()->create(['job_role' => User::ROLE_SALES, 'is_active' => true]);
        $artist = User::factory()->create(['job_role' => User::JOB_ARTIST, 'is_active' => true]);

        $order = ProductionOrder::create([
            'order_number' => 'IC2026-02200',
            'customer_name' => 'Resend Co',
            'product_type' => 'round_neck',
            'quantity' => 10,
            'due_date' => now()->addWeeks(2),
            'status' => 'active',
            'created_by' => $sales->id,
        ]);

        $task = $order->tasks()->create([
            'sequence' => 1, 'stage' => 3, 'department' => 'Export',
            'status' => 'in_progress', 'approver_role' => 'leader',
            'assigned_to' => $artist->id,
        ]);

        $this->actingAs($artist)->post("/my-tasks/{$task->id}/submit", [
            'paths' => array_fill_keys(array_keys($task->fileSlots()), self::GOOD),
        ])->assertRedirect();

        return [$artist, $task->fresh()];
    }

    private function resend(User $who, Task $task, string $path)
    {
        return $this->actingAs($who)->post("/my-tasks/{$task->id}/path", [
            'paths' => array_fill_keys(array_keys($task->fileSlots()), $path),
        ]);
    }

    public function test_the_artist_can_correct_a_path_after_sending_it(): void
    {
        [$artist, $task] = $this->sentExport();

        $this->assertSame('complete', $task->status);

        $this->resend($artist, $task, self::MOVED)
            ->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame(
            self::MOVED,
            $task->fresh()->files()->orderByDesc('id')->first()->external_path
        );
    }

    public function test_the_step_stays_done(): void
    {
        [$artist, $task] = $this->sentExport();

        $this->resend($artist, $task, self::MOVED);

        // The work was done; only where the file lives changed.
        $this->assertSame('complete', $task->fresh()->status);
    }

    public function test_production_is_not_handed_a_worse_path_than_before(): void
    {
        [$artist, $task] = $this->sentExport();

        // The same rule as the first submission — the address alone is not a file.
        $this->resend($artist, $task, '\\\\192.168.150.233\\')->assertSessionHasErrors();

        $this->assertSame(
            self::GOOD,
            $task->fresh()->files()->orderByDesc('id')->first()->external_path,
            'a rejected correction must leave the original in place'
        );
    }

    public function test_a_leader_can_fix_it_when_the_artist_is_away(): void
    {
        [, $task] = $this->sentExport();
        $leader = User::factory()->create(['job_role' => User::ROLE_LEADER, 'is_active' => true]);

        $this->resend($leader, $task, self::MOVED)
            ->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame(self::MOVED, $task->fresh()->files()->orderByDesc('id')->first()->external_path);
    }

    public function test_another_artist_cannot_touch_it(): void
    {
        [, $task] = $this->sentExport();
        $other = User::factory()->create(['job_role' => User::JOB_ARTIST, 'is_active' => true]);

        $this->resend($other, $task, self::MOVED)->assertNotFound();

        $this->assertSame(self::GOOD, $task->fresh()->files()->orderByDesc('id')->first()->external_path);
    }

    public function test_sending_the_same_path_again_says_nothing_changed(): void
    {
        [$artist, $task] = $this->sentExport();

        $this->resend($artist, $task, self::GOOD)
            ->assertRedirect()
            ->assertSessionHas('success', fn ($m) => str_contains($m, 'Nothing changed'));
    }

    public function test_a_step_that_uploads_files_has_no_path_to_resend(): void
    {
        [$artist, $task] = $this->sentExport();
        $task->update(['department' => 'Layout']);   // uploaded, not a path

        $this->resend($artist, $task->fresh(), self::MOVED)->assertNotFound();
    }

    public function test_the_artist_is_offered_the_way_back_to_it(): void
    {
        [$artist, $task] = $this->sentExport();

        // The card in the list used to carry this link. It now carries nothing
        // but the order and its current step — so the guarantee moved to the
        // step page, which is one click away and is where the paths are shown.
        // What must stay true is that a wrong path is always reachable.
        $this->actingAs($artist)->get("/my-tasks/{$task->id}")
            ->assertOk()
            ->assertSee('Edit and send again', false);
    }

    public function test_the_path_is_visible_from_any_step_of_the_order(): void
    {
        [$artist, $task] = $this->sentExport();

        // Opening a different step of the same order used to show nothing about
        // the export, so the paths could only be found by knowing which step
        // held them. Production works from these; they belong on the job.
        $other = \App\Models\Task::create([
            'production_order_id' => $task->production_order_id,
            'department' => 'Layout',
            'sequence' => $task->sequence + 1,
            'stage' => 1,
            'status' => 'ready',
            'assigned_to' => $artist->id,
        ]);

        $this->actingAs($artist)->get("/my-tasks/{$other->id}")
            ->assertOk()
            ->assertSee('Export files for this order')
            ->assertSee(self::GOOD, false);
    }

    public function test_every_step_of_the_order_is_listed_on_the_step_page(): void
    {
        [$artist, $task] = $this->sentExport();

        \App\Models\Task::create([
            'production_order_id' => $task->production_order_id,
            'department' => 'Production template',
            'sequence' => $task->sequence + 1,
            'stage' => 2,
            'status' => 'todo',
        ]);

        $this->actingAs($artist)->get("/my-tasks/{$task->id}")
            ->assertOk()
            ->assertSee('Steps on ')
            ->assertSee('Production template')
            ->assertSee('you are here');
    }

    public function test_the_form_is_on_the_task_page(): void
    {
        [$artist, $task] = $this->sentExport();

        $this->actingAs($artist)->get("/my-tasks/{$task->id}")
            ->assertOk()
            ->assertSee('Edit and send again', false)
            ->assertSee(self::GOOD, false);
    }
}
