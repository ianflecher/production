<?php

namespace Tests\Feature;

use App\Models\Payment;
use App\Models\ProductionOrder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * End-to-end walk of the order money-pipeline, crossing all three controllers
 * that the refactor split apart:
 *   ProductionOrderController  (order + layout + payment)
 *   OrderReferenceFileController (client reference upload)
 *   OrderDocumentController     (DR/PQ document)
 * Proves the pieces still cooperate after the split.
 */
class OrderPipelineTest extends TestCase
{
    use RefreshDatabase;

    public function test_full_order_pipeline_from_intake_to_document(): void
    {
        Storage::fake('local');
        $sales = User::factory()->create(['job_role' => User::ROLE_SALES, 'is_active' => true]);

        // 1) INTAKE — sales creates the order.
        $this->actingAs($sales)->post('/orders', [
            'order_number' => 'IC2026-09900',
            'client_name' => 'Pipeline Co',
            'client_last_name' => 'Cruz',
            'client_contact' => '0917-000-0000',
            'client_address' => 'Angeles City',
            'due_date' => now()->addWeeks(3)->toDateString(),
            'product_type' => 'round_neck',
            'sizes' => ['M' => 12, 'L' => 8], // 20 pcs
        ])->assertRedirect();

        $order = ProductionOrder::where('order_number', 'IC2026-09900')->firstOrFail();
        $this->assertSame(20, $order->quantity);
        $this->assertSame('active', $order->status);
        $this->assertNotNull($order->jobOrder, 'draft job order should exist after intake');

        // 2) REFERENCE — upload the ChatGPT design output (OrderReferenceFileController).
        $this->actingAs($sales)->post("/job-orders/{$order->id}/reference", [
            'reference_files' => [UploadedFile::fake()->image('design.jpg')],
            'kind' => 'output',
        ])->assertRedirect();
        $this->assertTrue(
            $order->jobOrder->referenceFiles()->where('kind', 'output')->exists(),
            'output reference should be attached'
        );

        // 3) LAYOUT — send it to an artist (needs the output reference we just added).
        $this->actingAs($sales)->post("/orders/{$order->id}/send-for-layout", [
            'reference_note' => 'Client wants navy + white.',
        ])->assertRedirect();
        $this->assertTrue($order->fresh()->layoutReleased(), 'layout should be released to an artist');

        // 4) APPROVAL — the client approves the layout (mark the layout stage complete).
        $order->tasks()->where('stage', ProductionOrder::STAGE_LAYOUT)->update(['status' => 'complete']);
        $this->assertTrue($order->fresh()->layoutApproved(), 'layout should now read as approved');

        // 5) PAYMENT — record the downpayment (ProductionOrderController@recordPayment).
        $this->actingAs($sales)->post("/orders/{$order->id}/payment", [
            'portion' => 'half',
            'method' => 'GCash',
            'proof' => UploadedFile::fake()->image('proof.jpg'),
        ])->assertRedirect(route('orders.show', $order));
        $this->assertDatabaseHas('payments', ['production_order_id' => $order->id, 'kind' => 'downpayment']);
        $this->assertEqualsWithDelta(
            round((float) $order->total_price / 2, 2),
            (float) Payment::where('production_order_id', $order->id)->value('amount'),
            0.01
        );

        // 6) DOCUMENT — open the Delivery Receipt (OrderDocumentController); it is created on first open.
        $this->actingAs($sales)->get("/orders/{$order->id}/document/dr")->assertOk();
        $this->assertDatabaseHas('order_documents', ['production_order_id' => $order->id, 'type' => 'dr']);
    }
}
