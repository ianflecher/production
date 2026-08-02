<?php

namespace Tests\Feature;

use App\Models\JobOrderFile;
use App\Models\ProductionOrder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Client reference files on a job order (pegs, logos, the ChatGPT design the
 * artist works from). Guarded by assertCanSeeReference: the owning officer,
 * leaders, or someone assigned to a task on that order.
 */
class ReferenceFileAccessTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $jobRole): User
    {
        return User::factory()->create(['job_role' => $jobRole, 'is_active' => true]);
    }

    private function order(User $sales): ProductionOrder
    {
        $this->actingAs($sales)->post('/orders', [
            'order_number' => 'IC2026-03030',
            'client_name' => 'Ref Co',
            'due_date' => now()->addWeeks(3)->toDateString(),
            'product_type' => 'round_neck',
            'sizes' => ['M' => 10],
        ]);

        return ProductionOrder::where('order_number', 'IC2026-03030')->firstOrFail();
    }

    private function referenceFile(ProductionOrder $order): JobOrderFile
    {
        return JobOrderFile::create([
            'job_order_id' => $order->jobOrder->id,
            'path' => UploadedFile::fake()->image('peg.jpg')->store('job-order-refs', 'local'),
            'original_name' => 'peg.jpg',
            'kind' => 'peg',
            'mime' => 'image/jpeg',
            'size' => 999,
            'uploaded_by' => $order->created_by,
        ]);
    }

    public function test_owning_officer_can_view_and_download_a_reference(): void
    {
        Storage::fake('local');
        $sales = $this->user(User::ROLE_SALES);
        $file = $this->referenceFile($this->order($sales));

        $this->actingAs($sales)->get("/job-order-files/{$file->id}/view")->assertOk();
        $this->actingAs($sales)->get("/job-order-files/{$file->id}/download")->assertOk();
    }

    public function test_another_officer_cannot_view_the_reference(): void
    {
        Storage::fake('local');
        $owner = $this->user(User::ROLE_SALES);
        $file = $this->referenceFile($this->order($owner));

        $this->actingAs($this->user(User::ROLE_SALES))
            ->get("/job-order-files/{$file->id}/view")->assertForbidden();
    }

    public function test_leader_can_view_any_reference(): void
    {
        Storage::fake('local');
        $sales = $this->user(User::ROLE_SALES);
        $file = $this->referenceFile($this->order($sales));

        $this->actingAs($this->user(User::ROLE_LEADER))
            ->get("/job-order-files/{$file->id}/view")->assertOk();
    }

    public function test_an_assigned_worker_can_view_the_reference(): void
    {
        Storage::fake('local');
        $sales = $this->user(User::ROLE_SALES);
        $order = $this->order($sales);
        $file = $this->referenceFile($order);

        $artist = $this->user(User::JOB_ARTIST);
        $order->tasks()->first()->update(['assigned_to' => $artist->id]);

        $this->actingAs($artist)->get("/job-order-files/{$file->id}/view")->assertOk();
    }

    public function test_an_unassigned_worker_cannot_view_the_reference(): void
    {
        Storage::fake('local');
        $sales = $this->user(User::ROLE_SALES);
        $file = $this->referenceFile($this->order($sales));

        $this->actingAs($this->user(User::JOB_ARTIST))
            ->get("/job-order-files/{$file->id}/view")->assertForbidden();
    }

    public function test_a_guest_cannot_reach_reference_files(): void
    {
        Storage::fake('local');
        $sales = $this->user(User::ROLE_SALES);
        $file = $this->referenceFile($this->order($sales));

        auth()->logout();
        $this->flushSession();

        $this->get("/job-order-files/{$file->id}/view")->assertRedirect('/login');
    }

    public function test_owning_officer_can_mark_a_reference_as_the_design_output(): void
    {
        Storage::fake('local');
        $sales = $this->user(User::ROLE_SALES);
        $file = $this->referenceFile($this->order($sales));

        $this->actingAs($sales)->post("/job-order-files/{$file->id}/kind", ['kind' => 'output'])
            ->assertRedirect();

        $this->assertSame('output', $file->fresh()->kind);
    }

    public function test_another_officer_cannot_delete_someone_elses_reference(): void
    {
        Storage::fake('local');
        $owner = $this->user(User::ROLE_SALES);
        $file = $this->referenceFile($this->order($owner));

        $this->actingAs($this->user(User::ROLE_SALES))
            ->post("/job-order-files/{$file->id}/delete")->assertForbidden();

        $this->assertDatabaseHas('job_order_files', ['id' => $file->id]);
    }

    public function test_owning_officer_can_delete_their_reference(): void
    {
        Storage::fake('local');
        $sales = $this->user(User::ROLE_SALES);
        $file = $this->referenceFile($this->order($sales));

        $this->actingAs($sales)->post("/job-order-files/{$file->id}/delete")->assertRedirect();

        $this->assertDatabaseMissing('job_order_files', ['id' => $file->id]);
    }
}
