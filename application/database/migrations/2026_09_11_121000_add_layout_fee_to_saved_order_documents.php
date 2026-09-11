<?php

use App\Models\OrderDocument;
use App\Models\ProductionOrder;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Put the new layout charge on documents that were saved before the
     * pricing change. Existing rows stay exactly as they were; only missing
     * system fee rows are appended.
     */
    public function up(): void
    {
        OrderDocument::query()
            ->with('order')
            ->orderBy('id')
            ->eachById(function (OrderDocument $document): void {
                $order = $document->order;

                if (! $order) {
                    return;
                }

                $items = $document->items ?? [];
                $descriptions = collect($items)
                    ->pluck('description')
                    ->map(fn ($description) => mb_strtolower(trim((string) $description)));

                if (! $descriptions->contains('layout fee')) {
                    $items[] = [
                        'description' => 'Layout fee',
                        'size' => '',
                        'quantity' => 1,
                        'unit_price' => ProductionOrder::LAYOUT_FEE,
                        'addon' => true,
                    ];
                }

                if ($order->layoutFeeRefund() > 0
                    && ! $descriptions->contains(fn ($description) => str_starts_with($description, 'layout fee refund'))) {
                    $items[] = [
                        'description' => 'Layout fee refund (24+ pcs)',
                        'size' => '',
                        'quantity' => 1,
                        'unit_price' => -1 * ProductionOrder::LAYOUT_FEE,
                        'addon' => true,
                    ];
                }

                $document->update(['items' => $items]);

                // Saved older orders should show the same total as their
                // updated document and newly created orders.
                $order->recomputeTotal();
            });
    }

    public function down(): void
    {
        // Do not remove rows from client-facing, saved documents.
    }
};
