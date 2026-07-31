<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\AuthorizesOrderAccess;
use App\Models\ProductionOrder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Client-facing order documents: the Delivery Receipt (DR, no VAT) and the
 * Price Quotation (PQ, +12% VAT). Split out of ProductionOrderController.
 */
class OrderDocumentController extends Controller
{
    use AuthorizesOrderAccess;

    /**
     * A client-facing document (DR or PQ). Created on first open, pre-filled
     * from the order; everything else is typed by the account officer.
     * Available before AND after payment.
     */
    public function document(ProductionOrder $order, string $type): View
    {
        $this->assertOrderVisible($order);
        abort_unless(array_key_exists($type, \App\Models\OrderDocument::TYPES), 404);

        $defaults = \App\Models\OrderDocument::defaultsFor($order, $type);
        $doc = $order->documents()->firstWhere('type', $type);

        if (! $doc) {
            $doc = $order->documents()->create([
                'type' => $type,
                'number' => $defaults['number'],
                'items' => $defaults['items'],
                'fields' => $defaults['fields'],
                'created_by' => auth()->id(),
            ]);
        } else {
            // The job order is filled AFTER this document first appears, so top up
            // anything still blank (print type, materials…) without ever
            // overwriting what the officer has typed.
            $fields = $doc->fields ?? [];
            $filled = false;

            foreach (array_filter($defaults['fields'], fn ($v) => $v !== null && $v !== '') as $k => $v) {
                if (! isset($fields[$k]) || $fields[$k] === '' || $fields[$k] === null) {
                    $fields[$k] = $v;
                    $filled = true;
                }
            }

            if ($filled) {
                $doc->update(['fields' => $fields]);
            }
        }

        $order->load('tasks.files');

        return view('orders.document', ['order' => $order, 'doc' => $doc]);
    }

    public function saveDocument(Request $request, ProductionOrder $order, string $type): RedirectResponse
    {
        $this->assertOrderVisible($order);
        abort_unless(array_key_exists($type, \App\Models\OrderDocument::TYPES), 404);

        $doc = $order->documents()->where('type', $type)->firstOrFail();

        $data = $request->validate([
            'number' => ['nullable', 'string', 'max:50'],
            'fields' => ['nullable', 'array'],
            'fields.*' => ['nullable', 'string', 'max:1000'],
            'items' => ['nullable', 'array'],
            'items.*.description' => ['nullable', 'string', 'max:255'],
            'items.*.size' => ['nullable', 'string', 'max:50'],
            'items.*.quantity' => ['nullable', 'numeric', 'min:0', 'max:1000000'],
            'items.*.unit_price' => ['nullable', 'numeric', 'min:0', 'max:10000000'],
            'items.*.addon' => ['nullable', 'boolean'],
        ]);

        // Drop rows with nothing on them.
        $items = collect($data['items'] ?? [])
            ->filter(fn ($r) => filled($r['description'] ?? null) || filled($r['size'] ?? null) || filled($r['quantity'] ?? null))
            ->values()
            ->all();

        // Merge, don't replace: the "before payment" copy doesn't render the
        // payment/signature fields, so a save from there must not wipe them.
        // Fields that ARE on the form still overwrite (including being cleared).
        $fields = array_merge($doc->fields ?? [], $data['fields'] ?? []);
        $fields = array_filter($fields, fn ($v) => $v !== null && $v !== '');

        $doc->update([
            'number' => $data['number'] ?? $doc->number,
            'fields' => $fields,
            'items' => $items,
        ]);

        // The document is the pricing source, so its grand total drives the order's
        // Total and payment balance — this is how extra products added on the
        // document flow into the order's payment section. The Price Quotation adds
        // 12% VAT; the Delivery Receipt (no VAT) uses the plain line total.
        $net = 0.0;
        foreach ($items as $row) {
            $net += (float) ($row['quantity'] ?? 0) * (float) ($row['unit_price'] ?? 0);
        }
        $gross = $type === \App\Models\OrderDocument::TYPE_PQ ? $net * 1.12 : $net;
        $synced = false;
        if ($gross > 0) {
            $order->update([
                'total_price' => round($gross, 2),
                'vat_inclusive' => $type === \App\Models\OrderDocument::TYPE_PQ,
            ]);
            $synced = true;
        }

        return redirect()->route('orders.document', [$order, $type])
            ->with('success', $doc->typeLabel().' saved.'.($synced ? ' Order total updated to ₱'.number_format(round($order->fresh()->total_price ?? 0, 2), 2).'.' : ''));
    }

    /**
     * Re-pull everything from the order (prices, sizes, job order specs),
     * discarding typed values. Used when the order changed after the document
     * was first made.
     */
    public function refreshDocument(ProductionOrder $order, string $type): RedirectResponse
    {
        $this->assertOrderVisible($order);
        abort_unless(array_key_exists($type, \App\Models\OrderDocument::TYPES), 404);

        $doc = $order->documents()->where('type', $type)->firstOrFail();
        $defaults = \App\Models\OrderDocument::defaultsFor($order, $type);

        $doc->update([
            'items' => $defaults['items'],
            'fields' => array_filter($defaults['fields'], fn ($v) => $v !== null && $v !== ''),
        ]);

        return redirect()->route('orders.document', [$order, $type])
            ->with('success', 'Re-filled from the order — typed changes were replaced.');
    }

    /** Attach the contract / payment proof / signed copy onto the document. */
    public function uploadDocumentFile(Request $request, ProductionOrder $order, string $type): RedirectResponse
    {
        $this->assertOrderVisible($order);
        abort_unless(array_key_exists($type, \App\Models\OrderDocument::TYPES), 404);

        $doc = $order->documents()->where('type', $type)->firstOrFail();

        $request->validate([
            'attachments' => ['required', 'array'],
            'attachments.*' => ['file', 'mimes:jpg,jpeg,png,webp,gif,pdf', 'max:65536'],
        ], ['attachments.required' => 'Choose at least one file.']);

        $files = $doc->attachmentList();

        foreach ($request->file('attachments') as $file) {
            $files[] = [
                'path' => $file->store('order-documents', 'local'),
                'name' => $file->getClientOriginalName(),
                'mime' => $file->getClientMimeType(),
            ];
        }

        $doc->update(['attachments' => $files]);

        return back()->with('success', 'Attached to the document.');
    }

    public function deleteDocumentFile(Request $request, ProductionOrder $order, string $type, int $index): RedirectResponse
    {
        $this->assertOrderVisible($order);
        abort_unless(array_key_exists($type, \App\Models\OrderDocument::TYPES), 404);

        $doc = $order->documents()->where('type', $type)->firstOrFail();
        $files = $doc->attachmentList();

        if (isset($files[$index])) {
            \Illuminate\Support\Facades\Storage::disk('local')->delete($files[$index]['path']);
            unset($files[$index]);
            $doc->update(['attachments' => array_values($files)]);
        }

        return back()->with('success', 'Attachment removed.');
    }

    /** Serve an attachment inline (officers on their own order, plus leaders). */
    public function viewDocumentFile(ProductionOrder $order, string $type, int $index)
    {
        $this->assertOrderVisible($order);

        $doc = $order->documents()->where('type', $type)->firstOrFail();
        $file = $doc->attachmentList()[$index] ?? abort(404);

        abort_unless(\Illuminate\Support\Facades\Storage::disk('local')->exists($file['path']), 404);

        return \Illuminate\Support\Facades\Storage::disk('local')->response($file['path'], $file['name']);
    }

    /** Serve the flatlay photo (stored on the private disk). */
    public function viewDocumentFlatlay(ProductionOrder $order, string $type)
    {
        $this->assertOrderVisible($order);
        abort_unless(array_key_exists($type, \App\Models\OrderDocument::TYPES), 404);

        $doc = $order->documents()->where('type', $type)->firstOrFail();
        $flat = $doc->flatlay;

        abort_unless(is_array($flat) && ! empty($flat['path']), 404);
        abort_unless(\Illuminate\Support\Facades\Storage::disk('local')->exists($flat['path']), 404);

        return \Illuminate\Support\Facades\Storage::disk('local')->response(
            $flat['path'],
            $flat['name'] ?? 'flatlay'
        );
    }

    /** Upload flatlay image for the document. */
    public function uploadDocumentFlatlay(\Illuminate\Http\Request $request, ProductionOrder $order, string $type): RedirectResponse
    {
        $this->assertOrderVisible($order);
        abort_unless(array_key_exists($type, \App\Models\OrderDocument::TYPES), 404);

        $request->validate([
            'flatlay' => ['required', 'file', 'mimes:jpg,jpeg,png,webp,gif', 'max:65536'],
        ], ['flatlay.required' => 'Please choose a flatlay image.']);

        $doc = $order->documents()->where('type', $type)->firstOrFail();
        $file = $request->file('flatlay');

        $path = $file->store('order-documents/flatlays', 'local');

        $flatlay = [
            'path' => $path,
            'name' => $file->getClientOriginalName(),
            'mime' => $file->getClientMimeType(),
        ];

        $doc->update(['flatlay' => $flatlay]);

        return redirect()->route('orders.document', [$order, $type])
            ->with('success', 'Flatlay image uploaded successfully.');
    }
}
