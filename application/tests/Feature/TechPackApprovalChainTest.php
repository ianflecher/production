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
            // The tech pack's final sign-off is two named people now, so the
            // leader who checks one has to be one of them.
            'can_approve_tech_packs' => true,
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

    /**
     * A pack ready to submit: the officer's spec boxes, then the artist's half.
     *
     * The two halves have two owners and two forms — the officer fills the
     * typed boxes from their copy of the sheet, the artist adds the pictures
     * and the file location from theirs.
     */
    private function fillSpec(User $officer, ProductionOrder $order): void
    {
        $this->actingAs($officer)->post(route('job-orders.update', $order), [
            'design_name' => 'Complete Artist Pack',
            'fitting' => 'Original fit',
            'item_style' => 'Round-neck shirt',
            'print_type' => 'dtf',
            'printer' => 'dtf_printer',
            'fabric' => 'Cotton blend',
            'neck' => 'Round neck',
            'cuff_arm_sleeves' => 'Tupi',
            'neck_label' => 'IC woven label',
            'tshirt_color' => 'Black',
            'thread_color' => 'Black',
            'packaging' => 'One piece per plastic',
            'zipper_type' => 'N/A',
            'bottom_hem' => 'Straight hem',
            'lip_pocket_color' => 'N/A',
            'free_logo_sticker' => 'N/A',
        ])->assertRedirect()->assertSessionHasNoErrors();
    }

    private function completePack(User $artist, Task $pack): void
    {
        $this->actingAs($artist)->post(route('tasks.tech-pack', $pack), [
            'file_location_notes' => 'FOR PRINT\IC2026-CHAIN',
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
            // The tech pack's final sign-off is two named people now, so the
            // leader who checks one has to be one of them.
            'can_approve_tech_packs' => true,
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
            ->assertSee('name="file_location_notes"', false)
            // Every typed spec box is the officer's now — theirs is the
            // pictures and the file location.
            ->assertDontSee('name="tshirt_color"', false)
            ->assertDontSee('name="printer"', false);

        $this->fillSpec($officer, $order);
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

        $this->fillSpec($officer, $order);
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
        [$officer, , $artist, $leader, $order, $pack] = $this->shop();
        $this->fillSpec($officer, $order);
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

    public function test_artist_can_correct_a_pack_while_it_is_waiting_for_either_reviewer(): void
    {
        [$officer, , $artist, $leader, $order, $pack] = $this->shop();
        $this->fillSpec($officer, $order);
        $this->completePack($artist, $pack);
        $this->actingAs($artist)->post(route('tasks.submit', $pack))->assertRedirect();

        // It stays editable while the account officer is checking it.
        $this->actingAs($artist)->get(route('tasks.job-order', $pack))
            ->assertOk()
            ->assertSee('name="file_location_notes"', false);
        $this->actingAs($artist)->post(route('tasks.tech-pack', $pack), [
            'file_location_notes' => 'Corrected by Artist',
        ])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('in_progress', $pack->fresh()->status);
        $this->assertSame('sales', $pack->fresh()->approver_role);

        $this->fillSpec($officer, $order);
        $this->completePack($artist, $pack);
        $this->actingAs($artist)->post(route('tasks.submit', $pack))->assertRedirect();
        $this->actingAs($officer)->post(route('tasks.approve', $pack))->assertRedirect();

        // It also stays editable while the leader is checking it. Saving it
        // recalls the pack and requires the officer's review again.
        $this->actingAs($artist)->get(route('tasks.job-order', $pack))
            ->assertOk()
            ->assertSee('name="file_location_notes"', false);
        $this->actingAs($artist)->post(route('tasks.tech-pack', $pack), [
            'file_location_notes' => 'Corrected again',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame('in_progress', $pack->fresh()->status);
        $this->assertSame('sales', $pack->fresh()->approver_role);
        $this->assertNull($pack->fresh()->officer_approved_by);
        $this->assertSame('Corrected again', $order->fresh()->techPack->file_location_notes);
        $this->actingAs($leader)->post(route('tasks.approve', $pack))->assertForbidden();
    }
}
