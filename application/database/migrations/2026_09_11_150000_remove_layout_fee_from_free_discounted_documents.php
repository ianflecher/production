<?php

use App\Models\OrderDocument;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /** Remove system layout-fee rows from saved quotations made fully free by discount. */
    public function up(): void
    {
        OrderDocument::query()
            ->with('order')
            ->orderBy('id')
            ->eachById(function (OrderDocument $document): void {
                $order = $document->order;

                if (! $order || $order->pricingBreakdown()['layout_fee'] > 0) {
                    return;
                }

                $items = collect($document->items ?? [])
                    ->reject(fn ($item) => str_starts_with((string) ($item['description'] ?? ''), 'Layout fee'))
                    ->values()
                    ->all();

                $document->update(['items' => $items]);
                $order->recomputeTotal();
            });
    }

    public function down(): void
    {
        // Do not recreate fee rows on client-facing documents.
    }
};
