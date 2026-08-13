<?php

namespace Tests\Feature;

use App\Models\ProductionOrder;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * "No file attached." is only news when a file was expected.
 *
 * A physical sample is a garment on a table — there is nothing to upload, and
 * nothing ever was: every "Produce sample for client" task in the shop's whole
 * history has zero files. So the warning fired on all of them, in red, saying
 * something is wrong about the one step where nothing is.
 *
 * On an artist's step it still means what it says: a layout sent for review
 * with no artwork on it is a real fault and has to stay loud.
 */
class SampleReviewFileNoticeTest extends TestCase
{
    use RefreshDatabase;

    /** An order waiting on the account officer's review of the given step. */
    private function waitingOn(string $department): array
    {
        $sales = User::factory()->create(['job_role' => User::ROLE_SALES, 'is_active' => true]);

        $order = ProductionOrder::create([
            'order_number' => 'IC2026-0'.random_int(1000, 9999),
            'customer_name' => 'Sample Co',
            'product_type' => 'round_neck',
            'quantity' => 55,
            'due_date' => now()->addWeek(),
            'created_by' => $sales->id,
            'status' => 'active',
        ]);

        Task::create([
            'production_order_id' => $order->id,
            'department' => $department,
            'sequence' => 10,
            'stage' => 3,
            'status' => 'for_checking',
            'approver_role' => 'sales',
            'assigned_to' => $sales->id,
            'submitted_at' => now(),
        ]);

        return [$sales, $order];
    }

    public function test_a_garment_on_the_table_is_not_missing_a_file(): void
    {
        [$sales] = $this->waitingOn('Produce sample for client');

        $this->actingAs($sales)->get('/sample-review')
            ->assertOk()
            ->assertDontSee('No file attached.');
    }

    public function test_a_layout_sent_with_no_artwork_still_says_so(): void
    {
        [$sales] = $this->waitingOn('Final mockup');

        $this->actingAs($sales)->get('/sample-review')
            ->assertOk()
            ->assertSee('No file attached.');
    }

    public function test_the_physical_sample_still_offers_its_own_controls(): void
    {
        // Removing the warning must not take the rest of the card with it.
        [$sales] = $this->waitingOn('Produce sample for client');

        $this->actingAs($sales)->get('/sample-review')
            ->assertOk()
            ->assertSee('Client approved')
            ->assertSee('Send back to production');
    }
}
