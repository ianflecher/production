<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\AuthorizesOrderAccess;
use App\Models\ProductionOrder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The account-officer side of the client design questionnaire (generate the
 * ChatGPT prompt, save answers, reopen the client link). The public client-facing
 * side lives in ClientDesignBriefController. Split out of ProductionOrderController.
 */
class OrderDesignBriefController extends Controller
{
    use AuthorizesOrderAccess;

    /** The client design questionnaire → copy-paste ChatGPT prompt. */
    public function designBrief(ProductionOrder $order): View
    {
        $this->assertOrderVisible($order);
        $order->load('jobOrder.referenceFiles');
        abort_unless($order->jobOrder, 404);

        // Orders made before the token feature (or with an expired link) get a
        // fresh token here so the share link always generates.
        if (! $order->brief_token) {
            $order->regenerateBriefLink();
        }

        $answers = $order->jobOrder->design_brief ?? [];

        return view('orders.design-brief', [
            'order' => $order,
            'questions' => \App\Services\DesignBrief::questions(),
            'answers' => $answers,
            'prompt' => $answers ? \App\Services\DesignBrief::toPrompt($answers, $order) : null,
            // Shareable, login-free link the client can fill in themselves — a
            // clean random-token URL (no signature) that expires after 30 days.
            'clientLink' => route('client.design-brief', ['order' => $order]),
            'clientLinkExpiresAt' => $order->brief_expires_at,
            // When set, the client already submitted and the link is now closed.
            'clientSubmittedAt' => $order->jobOrder->client_brief_submitted_at,
        ]);
    }

    /** Reopen the single-use client link so the client can submit once more. */
    public function reopenClientBrief(ProductionOrder $order): RedirectResponse
    {
        $this->assertOrderVisible($order);
        $order->load('jobOrder');
        abort_unless($order->jobOrder, 404);

        $order->jobOrder->update(['client_brief_submitted_at' => null]);

        return redirect()->route('orders.design-brief', $order)
            ->with('success', 'Client form reopened — the link works again for one more submission.');
    }

    public function saveDesignBrief(Request $request, ProductionOrder $order): RedirectResponse
    {
        $this->assertOrderVisible($order);
        $order->load('jobOrder');
        abort_unless($order->jobOrder, 404);

        $questions = \App\Services\DesignBrief::questions();

        $data = $request->validate([
            'brief' => ['nullable', 'array'],
            'brief.*' => ['nullable', 'string', 'max:2000'],
            // Files attached under the peg / logo questions.
            'files' => ['nullable', 'array'],
            'files.*' => ['nullable', 'array'],
            'files.*.*' => ['file', 'mimes:jpg,jpeg,png,webp,gif,pdf,ai,psd,eps,cdr,zip', 'max:65536'],
        ]);

        // Keep only questions we actually asked, and drop blanks.
        $answers = collect($data['brief'] ?? [])
            ->only(array_keys($questions))
            ->filter(fn ($v) => filled($v))
            ->map(fn ($v) => trim($v))
            ->all();

        $order->jobOrder->update(['design_brief' => $answers ?: null]);

        // Uploads live with the client reference files, so the artist gets them.
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
                    'uploaded_by' => $request->user()->id,
                ]);
            }
        }

        return redirect()->route('orders.design-brief', $order)
            ->with('success', 'Design brief saved. Copy the prompt below into ChatGPT to generate the design.');
    }
}
