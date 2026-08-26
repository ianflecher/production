<?php

namespace App\Http\Controllers;

use App\Models\ProductionOrder;
use App\Models\Inquiry;
use App\Services\DesignBrief;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Public, login-free client questionnaire reached through a signed link the
 * account officer shares. Answers are stored in the same place the internal
 * design-brief page uses (jobOrder->design_brief), so the officer sees them —
 * and the generated prompt — as soon as the client submits.
 */
class ClientDesignBriefController extends Controller
{
    public function show(ProductionOrder $order): View
    {
        $order->load('jobOrder.referenceFiles');
        abort_unless($order->jobOrder, 404);

        $justSaved = (bool) session('client_brief_saved', false);
        // The link is single-use: once submitted it's closed (unless this is the
        // "thank you" render right after a successful submit).
        $closed = $order->jobOrder->client_brief_submitted_at !== null && ! $justSaved;
        // …and it stops working after its expiry date.
        $expired = $order->briefExpired() && ! $justSaved;

        return view('client.design-brief', [
            'order' => $order,
            'briefTitle' => $order->order_number,
            'clientName' => $order->clientName(),
            'briefMeta' => 'Order '.$order->order_number.' · '.($order->productLabel() ?? 'Custom apparel').' · '.number_format($order->quantity).' pcs',
            'questions' => DesignBrief::questions(),
            'answers' => $order->jobOrder->design_brief ?? [],
            // The POST goes to the same token URL — the token is the credential.
            'submitUrl' => route('client.design-brief.submit', ['order' => $order]),
            'justSaved' => $justSaved,
            'closed' => $closed,
            'expired' => $expired,
        ]);
    }

    public function showInquiry(Inquiry $inquiry): View
    {
        $justSaved = (bool) session('client_brief_saved', false);

        return view('client.design-brief', [
            'inquiry' => $inquiry->load('client'),
            'briefTitle' => 'Design inquiry',
            'clientName' => $inquiry->client->fullName(),
            'briefMeta' => 'Design inquiry · apparel and quantity will be confirmed by our team',
            'questions' => DesignBrief::questions(),
            'answers' => $inquiry->design_brief ?? [],
            'submitUrl' => route('client.inquiry-design-brief.submit', $inquiry),
            'justSaved' => $justSaved,
            'closed' => $inquiry->client_brief_submitted_at !== null && ! $justSaved,
            'expired' => $inquiry->briefExpired() && ! $justSaved,
            'refFiles' => collect($inquiry->layout_files ?? [])->whereIn('kind', ['peg', 'logo']),
        ]);
    }

    public function submitInquiry(Request $request, Inquiry $inquiry): RedirectResponse
    {
        abort_if($inquiry->client_brief_submitted_at !== null || $inquiry->briefExpired(), 410);
        $questions = DesignBrief::questions();
        $data = $request->validate([
            'brief' => ['nullable', 'array'], 'brief.*' => ['nullable', 'string', 'max:2000'],
            'files' => ['nullable', 'array'], 'files.*' => ['nullable', 'array'],
            'files.*.*' => ['file', 'mimes:jpg,jpeg,png,webp,gif,pdf,ai,psd,eps,cdr,zip', 'max:65536'],
        ]);
        $answers = collect($data['brief'] ?? [])->only(array_keys($questions))
            ->filter(fn ($value) => filled($value))->map(fn ($value) => trim($value))->all();
        $files = $inquiry->layout_files ?? [];
        $kinds = collect($questions)->pluck('files')->filter()->all();
        foreach ($request->file('files', []) as $kind => $uploads) {
            if (! in_array($kind, $kinds, true)) continue;
            foreach ($uploads as $file) {
                $files[] = ['path' => $file->store('inquiry-layouts', 'local'), 'original_name' => $file->getClientOriginalName(),
                    'kind' => $kind, 'mime' => $file->getClientMimeType(), 'size' => $file->getSize(), 'uploaded_by' => null];
            }
        }
        $inquiry->update(['design_brief' => $answers ?: null, 'layout_files' => $files ?: null, 'client_brief_submitted_at' => now()]);

        return redirect()->route('client.inquiry-design-brief', $inquiry)->with('client_brief_saved', true);
    }

    public function submit(Request $request, ProductionOrder $order): RedirectResponse
    {
        $order->load('jobOrder');
        abort_unless($order->jobOrder, 404);

        // Single-use: reject a second submission on an already-used link.
        abort_if($order->jobOrder->client_brief_submitted_at !== null, 410);
        // Reject an expired link.
        abort_if($order->briefExpired(), 410);

        $questions = DesignBrief::questions();

        $data = $request->validate([
            'brief' => ['nullable', 'array'],
            'brief.*' => ['nullable', 'string', 'max:2000'],
            // Reference files (peg / logo) — same rules as the internal form.
            'files' => ['nullable', 'array'],
            'files.*' => ['nullable', 'array'],
            'files.*.*' => ['file', 'mimes:jpg,jpeg,png,webp,gif,pdf,ai,psd,eps,cdr,zip', 'max:65536'],
        ]);

        // Keep only questions we actually asked, and drop blanks — identical to
        // the internal save so the stored shape never diverges.
        $answers = collect($data['brief'] ?? [])
            ->only(array_keys($questions))
            ->filter(fn ($v) => filled($v))
            ->map(fn ($v) => trim($v))
            ->all();

        // Save answers and stamp the submission — this closes the link.
        $order->jobOrder->update([
            'design_brief' => $answers ?: null,
            'client_brief_submitted_at' => now(),
        ]);

        // Store uploads exactly like saveDesignBrief, but with no user
        // (uploaded_by is nullable) since the client isn't logged in.
        $kinds = collect($questions)->pluck('files')->filter()->all();

        foreach ($request->file('files', []) as $kind => $files) {
            if (! in_array($kind, $kinds, true)) {
                continue;
            }

            foreach ($files as $file) {
                $order->jobOrder->referenceFiles()->create([
                    'path' => $file->store('job-order-refs', 'local'),
                    'original_name' => $file->getClientOriginalName(),
                    'kind' => $kind,
                    'mime' => $file->getClientMimeType(),
                    'size' => $file->getSize(),
                    'uploaded_by' => null,
                ]);
            }
        }

        return redirect()
            ->route('client.design-brief', ['order' => $order])
            ->with('client_brief_saved', true);
    }
}
