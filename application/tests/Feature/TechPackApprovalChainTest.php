<?php

namespace Tests\Feature;

use App\Models\ProductionOrder;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TechPackApprovalChainTest extends TestCase
{
    use RefreshDatabase;

    private function shop(): array
    {
        $officer = User::factory()->create([
            'job_role' => User::ROLE_SALES,
            'is_active' => true,
            'name' => 'Account Officer',
        ]);
        $otherOfficer = User::factory()->create([
            'job_role' => User::ROLE_SALES,
            'is_active' => true,
        ]);
        $artist = User::factory()->create([
            'job_role' => User::JOB_ARTIST,
            'is_active' => true,
            'name' => 'Artist',
        ]);
        $leader = User::factory()->create([
            'job_role' => User::ROLE_LEADER,
            'is_active' => true,
            'name' => 'Leader',
        ]);

        $order = ProductionOrder::create([
            'order_number' => 'IC2026-CHAIN',
            'customer_name' => 'Approval Chain Client',
            'product_type' => 'round_neck',
            'quantity' => 12,
            'due_date' => now()->addWeeks(2),
            'created_by' => $officer->id,
            'status' => 'active',
        ]);

        $order->jobOrder()->create([
            'status' => 'sent_to_artist',
            'created_by' => $officer->id,
        ]);

        Task::create([
            'production_order_id' => $order->id,
            'sequence' => 1,
            'stage' => ProductionOrder::STAGE_MOCKUP,
            'department' => 'Final mockup',
            'team' => User::JOB_ARTIST,
            'assigned_to' => $artist->id,
            'status' => 'complete',
            'approver_role' => 'sales',
            'approved_at' => now(),
        ]);

        $pack = Task::create([
            'production_order_id' => $order->id,
            'sequence' => 2,
            'stage' => ProductionOrder::STAGE_MOCKUP,
            'department' => 'Tech pack',
            'team' => User::JOB_ARTIST,
            'assigned_to' => $artist->id,
            'status' => 'in_progress',
            'approver_role' => 'sales',
        ]);

        $next = Task::create([
            'production_order_id' => $order->id,
            'sequence' => 3,
            'stage' => 3,
            'department' => 'Printer',
            'team' => User::JOB_PRODUCTION,
            'status' => 'todo',
            'approver_role' => 'leader',
        ]);

        return [$officer, $otherOfficer, $artist, $leader, $order, $pack, $next];
    }

    private function completePack(User $artist, Task $pack): void
    {
        $this->actingAs($artist)->post(route('tasks.tech-pack', $pack), [
            'design_name' => 'Complete Artist Pack',
            'fitting' => 'Original fit',
            'item_style' => 'Round-neck shirt',
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
            'packaging' => 'One piece per plastic',
            'zipper_type' => 'N/A',
            'bottom_hem' => 'Straight hem',
            'lip_pocket_color' => 'N/A',
            'size_range' => 'S-2XL',
            'free_logo_sticker' => 'N/A',
            'file_location_host' => 'IC-SERVER',
            'file_location_tail' => 'FOR PRINT\\IC2026-CHAIN',
        ])->assertRedirect()->assertSessionHasNoErrors();
    }

    public function test_mockup_approval_opens_the_artist_pack_then_it_passes_officer_and_leader(): void
    {
        $officer = User::factory()->create([
            'job_role' => User::ROLE_SALES, 'is_active' => true,
        ]);
        $artist = User::factory()->create([
            'job_role' => User::JOB_ARTIST, 'is_active' => true,
        ]);
        $leader = User::factory()->create([
            'job_role' => User::ROLE_LEADER, 'is_active' => true,
        ]);

        $order = ProductionOrder::create([
            'order_number' => 'IC2026-EXACT',
            'customer_name' => 'Exact Workflow Client',
            'product_type' => 'round_neck',
            'quantity' => 20,
            'due_date' => now()->addWeeks(2),
            'created_by' => $officer->id,
            'status' => 'active',
        ]);
        $order->jobOrder()->create([
            'status' => 'draft', 'created_by' => $officer->id,
        ]);

        $mockup = Task::create([
            'production_order_id' => $order->id,
            'sequence' => 1,
            'stage' => ProductionOrder::STAGE_MOCKUP,
            'department' => 'Final mockup',
            'team' => User::JOB_ARTIST,
            'assigned_to' => $artist->id,
            'status' => 'for_checking',
            'approver_role' => 'sales',
            'submitted_at' => now(),
        ]);
        $pack = Task::create([
            'production_order_id' => $order->id,
            'sequence' => 2,
            'stage' => ProductionOrder::STAGE_MOCKUP,
            'department' => 'Tech pack',
            'team' => User::JOB_ARTIST,
            'assigned_to' => $artist->id,
            'status' => 'todo',
            'approver_role' => 'sales',
        ]);
        $next = Task::create([
            'production_order_id' => $order->id,
            'sequence' => 3,
            'stage' => 3,
            'department' => 'Printer',
            'team' => User::JOB_PRODUCTION,
            'status' => 'todo',
            'approver_role' => 'leader',
        ]);

        $this->actingAs($officer)->post(route('tasks.approve', $mockup))->assertRedirect();

        $this->assertSame('complete', $mockup->fresh()->status);
        $this->assertSame('sent_to_artist', $order->fresh()->jobOrder->status);
        $this->assertSame('ready', $pack->fresh()->status);

        $this->actingAs($artist)->post(route('tasks.start', $pack))->assertRedirect();
        $this->actingAs($artist)->get(route('tasks.job-order', $pack))
            ->assertOk()
            ->assertSee('name="design_name"', false)
            ->assertSee('name="printer"', false);

        $this->completePack($artist, $pack);
        $this->actingAs($artist)->post(route('tasks.submit', $pack))->assertRedirect();

        $this->assertSame('for_checking', $pack->fresh()->status);
        $this->assertSame('sales', $pack->fresh()->approver_role);

        $this->actingAs($officer)->post(route('tasks.approve', $pack))->assertRedirect();
        $this->assertSame('for_checking', $pack->fresh()->status);
        $this->assertSame('leader', $pack->fresh()->approver_role);
        $this->assertSame('todo', $next->fresh()->status);

        $this->actingAs($leader)->post(route('tasks.approve', $pack))->assertRedirect();
        $this->assertSame('complete', $pack->fresh()->status);
        $this->assertNotSame('todo', $next->fresh()->status);
    }

    public function test_artist_to_account_officer_to_leader_is_the_only_approval_path(): void
    {
        [$officer, $otherOfficer, $artist, $leader, $order, $pack, $next] = $this->shop();

        $this->completePack($artist, $pack);

        $this->assertSame('Complete Artist Pack', $order->fresh()->techPack->design_name);
        $this->assertSame('Cotton blend', $order->fresh()->jobOrder->fabric);

        $this->actingAs($artist)
            ->post(route('tasks.submit', $pack))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame('for_checking', $pack->fresh()->status);
        $this->assertSame('sales', $pack->fresh()->approver_role);
        $this->assertSame('todo', $next->fresh()->status);

        // A different account officer and the leader cannot bypass the owner.
        $this->actingAs($otherOfficer)->post(route('tasks.approve', $pack))->assertForbidden();
        $this->actingAs($leader)->post(route('tasks.approve', $pack))->assertForbidden();

        $this->actingAs($officer)
            ->post(route('tasks.approve', $pack))
            ->assertRedirect()
            ->assertSessionHas('success');

        $pack->refresh();
        $this->assertSame('for_checking', $pack->status);
        $this->assertSame('leader', $pack->approver_role);
        $this->assertSame($officer->id, $pack->officer_approved_by);
        $this->assertNotNull($pack->officer_approved_at);
        $this->assertSame('todo', $next->fresh()->status, 'Production opened before final leader approval.');

        $this->actingAs($officer)->post(route('tasks.approve', $pack))->assertForbidden();

        $this->actingAs($leader)
            ->post(route('tasks.approve', $pack))
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertSame('complete', $pack->fresh()->status);
        $this->assertNotSame('todo', $next->fresh()->status, 'Leader approval did not release production.');
    }

    public function test_artist_cannot_submit_an_incomplete_tech_pack(): void
    {
        [, , $artist, , , $pack] = $this->shop();

        $this->actingAs($artist)
            ->post(route('tasks.submit', $pack))
            ->assertRedirect(route('tasks.job-order', $pack))
            ->assertSessionHasErrors('tech_pack');

        $this->assertSame('in_progress', $pack->fresh()->status);
    }

    public function test_leader_revision_returns_to_artist_then_account_officer_again(): void
    {
        [$officer, , $artist, $leader, , $pack] = $this->shop();
        $this->completePack($artist, $pack);
        $this->actingAs($artist)->post(route('tasks.submit', $pack))->assertRedirect();
        $this->actingAs($officer)->post(route('tasks.approve', $pack))->assertRedirect();

        $this->actingAs($leader)->post(route('tasks.revision', $pack), [
            'revision_note' => 'Correct the neck label placement.',
        ])->assertRedirect();

        $pack->refresh();
        $this->assertSame('revision_required', $pack->status);
        $this->assertSame('sales', $pack->approver_role);
        $this->assertNull($pack->officer_approved_by);
        $this->assertNull($pack->officer_approved_at);

        $this->actingAs($artist)->post(route('tasks.start', $pack))->assertRedirect();
        $this->actingAs($artist)->post(route('tasks.submit', $pack))->assertRedirect();

        $this->assertSame('for_checking', $pack->fresh()->status);
        $this->assertSame('sales', $pack->fresh()->approver_role);
        $this->actingAs($leader)->post(route('tasks.approve', $pack))->assertForbidden();
    }
}
