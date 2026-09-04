<?php

namespace Tests\Feature;

use App\Models\JobOrder;
use App\Models\MaterialRequest;
use App\Models\OrderItem;
use App\Models\ProductionOrder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A material is asked for one size at a time.
 *
 * The desk used to get one line per material carrying the whole run's amount,
 * so fifty-five shirts across S to XL arrived as a single request: issue it
 * all, or issue nothing. The shelf does not run out evenly — the mediums go
 * first — and there was no way to say the larges are out while the smalls go
 * ahead. The order sat whole behind its shortest size.
 */
class MaterialRequestBySizeTest extends TestCase
{
    use RefreshDatabase;

    private function order(array $sizes): ProductionOrder
    {
        $sales = User::factory()->create(['job_role' => User::ROLE_SALES, 'is_active' => true]);

        $order = ProductionOrder::create([
            'order_number' => 'IC2026-0'.random_int(1000, 9999),
            'customer_name' => 'Fifty Five Co',
            'product_type' => 'round_neck',
            'quantity' => array_sum($sizes),
            'due_date' => now()->addWeek(),
            'created_by' => $sales->id,
            'status' => 'active',
        ]);

        foreach ($sizes as $size => $qty) {
            OrderItem::create([
                'production_order_id' => $order->id, 'size' => $size, 'quantity' => $qty,
            ]);
        }

        return $order->fresh();
    }

    private function jobOrder(ProductionOrder $order, array $materials, array $quantities = []): void
    {
        JobOrder::create([
            'production_order_id' => $order->id,
            'raw_materials' => $materials,
            'raw_material_quantities' => $quantities,
        ]);
    }

    public function test_each_size_gets_its_own_request(): void
    {
        $order = $this->order(['S' => 10, 'M' => 20, 'L' => 25]);
        $this->jobOrder($order, ['Aircool navy']);

        $order->fresh()->syncMaterialRequests();

        $sizes = MaterialRequest::where('production_order_id', $order->id)
            ->pluck('size')->sort()->values()->all();

        $this->assertSame(['L', 'M', 'S'], $sizes);
    }

    /**
     * The job order says what the whole run takes, so the amount follows the
     * pieces — a size that is a fifth of the run carries a fifth of the fabric.
     */
    public function test_the_amount_is_shared_out_in_proportion_to_the_pieces(): void
    {
        $order = $this->order(['S' => 10, 'M' => 20, 'L' => 20]);
        $this->jobOrder($order, ['Aircool navy'], ['Aircool navy' => 100]);

        $order->fresh()->syncMaterialRequests();

        $bySize = MaterialRequest::where('production_order_id', $order->id)
            ->pluck('requested_quantity', 'size')
            ->map(fn ($q) => (float) $q)->all();

        $this->assertSame(20.0, $bySize['S']);
        $this->assertSame(40.0, $bySize['M']);
        $this->assertSame(40.0, $bySize['L']);
    }

    /** The parts add up to what was asked for, rather than drifting short. */
    public function test_rounding_does_not_lose_any_of_the_material(): void
    {
        $order = $this->order(['S' => 1, 'M' => 1, 'L' => 1]);
        $this->jobOrder($order, ['Aircool navy'], ['Aircool navy' => 10]);

        $order->fresh()->syncMaterialRequests();

        $total = MaterialRequest::where('production_order_id', $order->id)
            ->get()->sum(fn ($r) => (float) $r->requested_quantity);

        $this->assertSame(10.0, $total);
    }

    /** An order with no size breakdown raises the single line it always did. */
    public function test_an_order_without_sizes_still_gets_one_plain_request(): void
    {
        $order = $this->order([]);
        $this->jobOrder($order, ['Aircool navy'], ['Aircool navy' => 100]);

        $order->fresh()->syncMaterialRequests();

        $reqs = MaterialRequest::where('production_order_id', $order->id)->get();

        $this->assertCount(1, $reqs);
        $this->assertSame('', $reqs->first()->size);
        $this->assertSame(100.0, (float) $reqs->first()->requested_quantity);
    }

    /** When nobody said how much, every size inherits that silence. */
    public function test_a_material_with_no_amount_leaves_the_box_empty_for_each_size(): void
    {
        $order = $this->order(['S' => 10, 'M' => 20]);
        $this->jobOrder($order, ['Aircool navy']);

        $order->fresh()->syncMaterialRequests();

        $this->assertCount(2, MaterialRequest::where('production_order_id', $order->id)
            ->whereNull('requested_quantity')->get());
    }

    /**
     * One size being out does not hold the others: the larges can be refused
     * while the smalls are issued, because they are separate requests now.
     */
    public function test_one_size_can_be_refused_while_another_is_issued(): void
    {
        $order = $this->order(['S' => 10, 'L' => 10]);
        $this->jobOrder($order, ['Aircool navy'], ['Aircool navy' => 20]);
        $order->fresh()->syncMaterialRequests();

        $small = MaterialRequest::where('production_order_id', $order->id)->where('size', 'S')->first();
        $large = MaterialRequest::where('production_order_id', $order->id)->where('size', 'L')->first();

        $this->assertNotNull($small);
        $this->assertNotNull($large);
        $this->assertNotSame($small->id, $large->id);
    }

    /** Syncing twice does not raise the same size a second time. */
    public function test_running_it_again_does_not_duplicate_the_sizes(): void
    {
        $order = $this->order(['S' => 10, 'M' => 20]);
        $this->jobOrder($order, ['Aircool navy'], ['Aircool navy' => 30]);

        $order->fresh()->syncMaterialRequests();
        $order->fresh()->syncMaterialRequests();

        $this->assertCount(2, MaterialRequest::where('production_order_id', $order->id)->get());
    }

    /** A size dropped from the order stops being asked for. */
    public function test_dropping_a_size_withdraws_its_pending_request(): void
    {
        $order = $this->order(['S' => 10, 'M' => 20]);
        $this->jobOrder($order, ['Aircool navy'], ['Aircool navy' => 30]);
        $order->fresh()->syncMaterialRequests();

        OrderItem::where('production_order_id', $order->id)->where('size', 'M')->delete();
        $order->fresh()->syncMaterialRequests();

        $sizes = MaterialRequest::where('production_order_id', $order->id)->pluck('size')->all();

        $this->assertSame(['S'], $sizes);
    }
}
