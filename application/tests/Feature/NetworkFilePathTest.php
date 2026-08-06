<?php

namespace Tests\Feature;

use App\Models\ProductionOrder;
use App\Models\Task;
use App\Models\TaskFile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * What an artist is allowed to hand over as the location of a print file.
 *
 * The box is pre-filled with their PC's address, so submitting it untouched was
 * easy — and it passed. The export step then completed and production was sent
 * to a machine with no file named. The address is the start of the answer, not
 * the answer.
 */
class NetworkFilePathTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: User, 1: Task} */
    private function artistOnExport(): array
    {
        $sales = User::factory()->create(['job_role' => User::ROLE_SALES, 'is_active' => true]);
        $artist = User::factory()->create(['job_role' => User::JOB_ARTIST, 'is_active' => true]);

        $order = ProductionOrder::create([
            'order_number' => 'IC2026-03300',
            'customer_name' => 'Path Co',
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

        return [$artist, $task];
    }

    private function submit(User $artist, Task $task, string $path)
    {
        $slots = array_keys($task->fileSlots());

        return $this->actingAs($artist)->post("/my-tasks/{$task->id}/submit", [
            'paths' => array_fill_keys($slots ?: ['file'], $path),
        ]);
    }

    public function test_a_real_path_is_accepted(): void
    {
        [$artist, $task] = $this->artistOnExport();

        $this->submit($artist, $task, '\\\\192.168.150.233\\Designs\\IC2026-03300.tif')
            ->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame('complete', $task->fresh()->status);

        // An export step can carry several files (print, sticker, back pocket);
        // what matters is the path was kept, not how many slots this order had.
        $this->assertGreaterThan(0, TaskFile::where('task_id', $task->id)->count());
    }

    public function test_the_address_on_its_own_is_refused(): void
    {
        [$artist, $task] = $this->artistOnExport();

        // Exactly what the pre-filled box contains before anything is typed.
        $this->submit($artist, $task, '\\\\192.168.150.233\\')
            ->assertSessionHasErrors();

        $this->assertSame('in_progress', $task->fresh()->status, 'the step must not complete');
        $this->assertSame(0, TaskFile::where('task_id', $task->id)->count());
    }

    public function test_the_address_with_no_trailing_slash_is_refused_too(): void
    {
        [$artist, $task] = $this->artistOnExport();

        $this->submit($artist, $task, '\\\\192.168.150.233')->assertSessionHasErrors();
        $this->assertSame('in_progress', $task->fresh()->status);
    }

    public function test_a_share_with_no_file_is_refused(): void
    {
        [$artist, $task] = $this->artistOnExport();

        // A folder is not a file — production would open it and find nothing.
        $this->submit($artist, $task, '\\\\192.168.150.233\\Designs')->assertSessionHasErrors();
        $this->assertSame('in_progress', $task->fresh()->status);
    }

    public function test_forward_slashes_are_refused_and_say_so(): void
    {
        [$artist, $task] = $this->artistOnExport();

        $response = $this->submit($artist, $task, '//192.168.150.233/Designs/file.tif');

        $response->assertSessionHasErrors();
        $this->assertStringContainsString(
            'back-slashes',
            collect(session('errors')->all())->join(' ')
        );
    }

    public function test_something_that_is_not_a_network_path_is_refused(): void
    {
        [$artist, $task] = $this->artistOnExport();

        foreach (['C:\\Designs\\file.tif', 'Designs\\file.tif', 'file.tif'] as $notShared) {
            $this->submit($artist, $task, $notShared)->assertSessionHasErrors();
        }

        $this->assertSame('in_progress', $task->fresh()->status);
    }

    public function test_a_deeper_folder_is_fine(): void
    {
        [$artist, $task] = $this->artistOnExport();

        $this->submit($artist, $task, '\\\\192.168.150.233\\Designs\\2026\\August\\IC2026-03300.tif')
            ->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame('complete', $task->fresh()->status);
    }

    public function test_spaces_in_names_are_fine(): void
    {
        [$artist, $task] = $this->artistOnExport();

        $this->submit($artist, $task, '\\\\192.168.150.233\\Client Designs\\IC2026 final.tif')
            ->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame('complete', $task->fresh()->status);
    }

    public function test_a_hostname_works_as_well_as_an_address(): void
    {
        [$artist, $task] = $this->artistOnExport();

        $this->submit($artist, $task, '\\\\MARU-PC\\Designs\\IC2026-03300.tif')
            ->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame('complete', $task->fresh()->status);
    }
}
