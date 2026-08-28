<?php

namespace Tests\Feature;

use App\Models\Payment;
use App\Models\ProductionOrder;
use App\Models\TaskFile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Uploaded files (customer artwork, job-order references, payment proofs) are
 * served through authenticated routes with per-file permission checks. These
 * lock those checks down.
 */
class FileAccessTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $jobRole): User
    {
        return User::factory()->create(['job_role' => $jobRole, 'is_active' => true]);
    }

    private function order(User $sales): ProductionOrder
    {
        $this->actingAs($sales)->post('/orders', [
            'order_number' => 'IC2026-04040',
            'client_name' => 'File Co',
            'client_last_name' => 'Cruz',
            'client_contact' => '0917-000-0000',
            'client_address' => 'Angeles City',
            'due_date' => now()->addWeeks(3)->toDateString(),
            'product_type' => 'round_neck',
            'sizes' => ['M' => 10],
        ]);

        return ProductionOrder::where('order_number', 'IC2026-04040')->firstOrFail();
    }

    /** A real stored task file belonging to $order's first task. */
    private function taskFile(ProductionOrder $order, ?User $assignee = null): TaskFile
    {
        $task = $order->tasks()->first();
        if ($assignee) {
            $task->update(['assigned_to' => $assignee->id]);
        }

        $path = UploadedFile::fake()->image('artwork.jpg')->store('task-files', 'local');

        return TaskFile::create([
            'task_id' => $task->id,
            'path' => $path,
            'original_name' => 'artwork.jpg',
            'mime' => 'image/jpeg',
            'size' => 1234,
            'round' => 1,
            'uploaded_by' => $assignee?->id ?? $order->created_by,
        ]);
    }

    // ---- Task files --------------------------------------------------------

    public function test_owning_account_officer_can_view_a_task_file(): void
    {
        Storage::fake('local');
        $sales = $this->user(User::ROLE_SALES);
        $file = $this->taskFile($this->order($sales));

        $this->actingAs($sales)->get("/task-files/{$file->id}/view")->assertOk();
    }

    public function test_another_account_officer_cannot_view_the_file(): void
    {
        Storage::fake('local');
        $owner = $this->user(User::ROLE_SALES);
        $file = $this->taskFile($this->order($owner));

        $this->actingAs($this->user(User::ROLE_SALES))
            ->get("/task-files/{$file->id}/view")->assertForbidden();
    }

    public function test_the_assigned_artist_can_view_their_own_task_file(): void
    {
        Storage::fake('local');
        $sales = $this->user(User::ROLE_SALES);
        $artist = $this->user(User::JOB_ARTIST);
        $file = $this->taskFile($this->order($sales), $artist);

        $this->actingAs($artist)->get("/task-files/{$file->id}/view")->assertOk();
    }

    public function test_an_unrelated_artist_cannot_view_the_file(): void
    {
        Storage::fake('local');
        $sales = $this->user(User::ROLE_SALES);
        $artist = $this->user(User::JOB_ARTIST);
        $file = $this->taskFile($this->order($sales), $artist);

        $this->actingAs($this->user(User::JOB_ARTIST))
            ->get("/task-files/{$file->id}/view")->assertForbidden();
    }

    public function test_leader_can_view_any_task_file(): void
    {
        Storage::fake('local');
        $sales = $this->user(User::ROLE_SALES);
        $file = $this->taskFile($this->order($sales));

        $this->actingAs($this->user(User::ROLE_LEADER))
            ->get("/task-files/{$file->id}/view")->assertOk();
    }

    public function test_floor_staff_cannot_view_the_design_before_the_layout_is_approved(): void
    {
        Storage::fake('local');
        $sales = $this->user(User::ROLE_SALES);
        $file = $this->taskFile($this->order($sales));

        // Layout not approved yet -> the floor must not see it.
        $this->actingAs($this->user('sewing'))
            ->get("/task-files/{$file->id}/view")->assertForbidden();
    }

    public function test_floor_staff_can_view_the_design_once_the_layout_is_approved(): void
    {
        Storage::fake('local');
        $sales = $this->user(User::ROLE_SALES);
        $order = $this->order($sales);
        $file = $this->taskFile($order);

        $order->tasks()->where('stage', ProductionOrder::STAGE_LAYOUT)->update(['status' => 'complete']);
        $this->assertTrue($order->fresh()->layoutApproved());

        $this->actingAs($this->user('sewing'))
            ->get("/task-files/{$file->id}/view")->assertOk();
    }

    public function test_a_guest_cannot_reach_task_files(): void
    {
        Storage::fake('local');
        $sales = $this->user(User::ROLE_SALES);
        $file = $this->taskFile($this->order($sales));

        // Building the order signed the officer in — drop that session first.
        auth()->logout();
        $this->flushSession();

        $this->get("/task-files/{$file->id}/view")->assertRedirect('/login');
        $this->get("/task-files/{$file->id}/download")->assertRedirect('/login');
    }

    // ---- Payment proofs ----------------------------------------------------

    public function test_another_officer_cannot_view_a_payment_proof_on_someone_elses_order(): void
    {
        Storage::fake('local');
        $owner = $this->user(User::ROLE_SALES);
        $order = $this->order($owner);

        $payment = Payment::create([
            'production_order_id' => $order->id,
            'amount' => 500,
            'method' => 'GCash',
            'kind' => 'downpayment',
            'proof_path' => UploadedFile::fake()->image('proof.jpg')->store('payment-proofs', 'local'),
            'proof_name' => 'proof.jpg',
            'recorded_by' => $owner->id,
        ]);

        $this->actingAs($this->user(User::ROLE_SALES))
            ->get("/payments/{$payment->id}/proof")->assertForbidden();
    }

    public function test_owning_officer_can_view_their_payment_proof(): void
    {
        Storage::fake('local');
        $owner = $this->user(User::ROLE_SALES);
        $order = $this->order($owner);

        $payment = Payment::create([
            'production_order_id' => $order->id,
            'amount' => 500,
            'method' => 'GCash',
            'kind' => 'downpayment',
            'proof_path' => UploadedFile::fake()->image('proof.jpg')->store('payment-proofs', 'local'),
            'proof_name' => 'proof.jpg',
            'recorded_by' => $owner->id,
        ]);

        $this->actingAs($owner)->get("/payments/{$payment->id}/proof")->assertOk();
    }
}
