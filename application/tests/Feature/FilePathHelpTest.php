<?php

namespace Tests\Feature;

use App\Models\ProductionOrder;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Artists submit design work as a path on the shared drive rather than an
 * upload, which means two things have to be right on their PC: the folder is
 * shared, and the path is copied correctly. The two clips sitting next to the
 * field are what stops that becoming an IT call every time.
 */
class FilePathHelpTest extends TestCase
{
    use RefreshDatabase;

    private const CLIPS = [
        'folder sharing.mp4',
        'folder file copy.mp4',
    ];

    /** An artist part-way through a step that wants a file path. */
    private function artistAtWork(): array
    {
        $sales = User::factory()->create(['job_role' => User::ROLE_SALES, 'is_active' => true]);
        $artist = User::factory()->create(['job_role' => User::JOB_ARTIST, 'is_active' => true]);

        $this->actingAs($sales)->post('/orders', [
            'order_number' => 'IC2026-08800',
            'client_name' => 'Guide',
            'client_last_name' => 'Cruz',
            'client_contact' => '0917-000-0000',
            'client_address' => 'Angeles City',
            'due_date' => now()->addWeeks(3)->toDateString(),
            'product_type' => 'round_neck',
            'sizes' => ['M' => 10],
        ]);

        $order = ProductionOrder::where('order_number', 'IC2026-08800')->firstOrFail();
        $order->unlockStage(ProductionOrder::STAGE_LAYOUT);

        // Only the export steps hand over a network path — they are the print
        // files production picks up off the shared drive.
        $task = $order->tasks()->where('department', 'Layout')->firstOrFail();
        $task->update([
            'department' => 'Export',
            'assigned_to' => $artist->id,
            'status' => 'in_progress',
        ]);

        return [$artist, $task->fresh()];
    }

    public function test_the_clips_are_actually_in_the_public_folder(): void
    {
        foreach (self::CLIPS as $clip) {
            $this->assertFileExists(
                public_path($clip),
                "$clip is missing — the guide on the artist's file-path field would be a dead link"
            );
        }
    }

    public function test_an_artist_typing_a_file_path_is_offered_the_guide(): void
    {
        [$artist, $task] = $this->artistAtWork();

        $response = $this->actingAs($artist)->get("/my-tasks/{$task->id}");

        $response->assertOk();
        $response->assertSee('Show me how', false);
        $response->assertSee('Share your folder so others can open it', false);
        $response->assertSee('Copy the file path of your file', false);
    }

    public function test_the_video_links_are_url_encoded(): void
    {
        [$artist, $task] = $this->artistAtWork();

        $response = $this->actingAs($artist)->get("/my-tasks/{$task->id}");

        // The file names contain spaces; a raw space in the src is a broken link.
        foreach (['folder%20sharing.mp4', 'folder%20file%20copy.mp4'] as $encoded) {
            $response->assertSee($encoded, false);
        }
        $response->assertDontSee('src="'.asset('folder sharing.mp4').'"', false);
    }

    public function test_the_clips_do_not_download_until_asked_for(): void
    {
        [$artist, $task] = $this->artistAtWork();

        $response = $this->actingAs($artist)->get("/my-tasks/{$task->id}");

        // Two clips on a page the floor opens all day: they must not be fetched
        // until someone actually opens the guide.
        $this->assertSame(
            2,
            substr_count($response->getContent(), 'preload="none"'),
            'both clips should be preload="none"'
        );
    }

    public function test_a_step_that_uploads_files_does_not_show_the_path_guide(): void
    {
        [$artist, $task] = $this->artistAtWork();

        // The layout is handed in as a real uploaded file, not a path, so the
        // guide would only be noise there.
        $task->update(['department' => 'Layout']);

        $this->actingAs($artist)->get("/my-tasks/{$task->id}")
            ->assertOk()
            ->assertDontSee('Show me how', false);
    }
}
