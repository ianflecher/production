<?php

namespace Tests\Feature;

use App\Models\InventoryItem;
use App\Models\MaterialRequest;
use App\Models\Message;
use App\Models\ProductionOrder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ListSearchAndIssuerTest extends TestCase
{
    use RefreshDatabase;

    private function order(string $number, string $customer): ProductionOrder
    {
        $sales = User::factory()->create(['job_role' => User::ROLE_SALES, 'is_active' => true]);

        return ProductionOrder::create([
            'order_number' => $number, 'customer_name' => $customer,
            'product_type' => 'round_neck', 'quantity' => 10,
            'due_date' => now()->addWeek(), 'created_by' => $sales->id, 'status' => 'active',
        ]);
    }

    public function test_the_inbox_can_be_searched(): void
    {
        $leader = User::factory()->create(['job_role' => User::ROLE_LEADER, 'is_active' => true]);
        $wanted = $this->order('IC2026-11111', 'Findable Co');
        $other = $this->order('IC2026-22222', 'Somebody Else');

        $this->actingAs($leader)->get('/messages?q=11111')
            ->assertOk()
            ->assertSee('IC2026-11111')
            ->assertDontSee('IC2026-22222');

        // …including by what was actually said, which is the point of it.
        Message::create(['production_order_id' => $other->id, 'sender_id' => $leader->id,
            'body' => 'the zipper is the wrong colour']);

        $this->actingAs($leader)->get('/messages?q=zipper')
            ->assertOk()
            ->assertSee('IC2026-22222')
            ->assertDontSee('IC2026-11111');
    }

    public function test_the_raw_materials_desk_sees_every_conversation(): void
    {
        $supply = User::factory()->create(['job_role' => 'Raw materials', 'is_active' => true]);
        $this->order('IC2026-33333', 'Not Theirs');

        // They supply every job on the floor, so a question about fabric on an
        // order they hold no step on is still theirs to answer.
        $this->assertTrue($supply->canManageInventory());
        $this->assertSame(
            ProductionOrder::count(),
            Message::accessibleOrderIds($supply)->count()
        );
    }

    public function test_issuing_records_the_person_not_the_shared_account(): void
    {
        $desk = User::factory()->create(['job_role' => 'Raw materials', 'is_active' => true, 'name' => 'Supply Desk']);
        $order = $this->order('IC2026-44444', 'Fabric Co');
        $item = InventoryItem::create(['name' => 'Cotton combed 24s', 'unit' => 'kg', 'quantity' => 50]);
        $req = MaterialRequest::create([
            'production_order_id' => $order->id, 'material' => 'Cotton combed 24s', 'status' => 'pending',
        ]);

        $this->actingAs($desk)->post("/material-requests/{$req->id}/approve", [
            'inventory_item_id' => $item->id,
            'quantity' => 5,
            'operator_name' => 'Aling Nena',
        ])->assertSessionHasNoErrors();

        $req->refresh();

        $this->assertSame('Aling Nena', $req->decided_by_name);
        $this->assertSame('Aling Nena', $req->decidedByLabel(),
            'the decisions list must name the person, not the login they shared');
    }

    public function test_a_job_order_cannot_reach_the_floor_with_no_materials(): void
    {
        $order = $this->order('IC2026-55555', 'Empty Co');
        // The officer who took the order — anyone else cannot open it.
        $sales = User::find($order->created_by);
        $order->jobOrder()->create(['status' => 'draft', 'created_by' => $sales->id]);

        // Without this the Raw materials step opens with nothing to issue, and
        // the supply desk waits on a request that never arrives.
        $this->actingAs($sales)
            ->post("/job-orders/{$order->id}/production", ['fabric_press' => 'heat_press'])
            ->assertSessionHasErrors('raw_materials');

        // Blank rows are what the form always posts, so they must not count.
        $this->actingAs($sales)
            ->post("/job-orders/{$order->id}/production", [
                'fabric_press' => 'heat_press', 'raw_materials' => ['', ''],
            ])
            ->assertSessionHasErrors('raw_materials');
    }
}
