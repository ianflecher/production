<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\AuthorizesOrderAccess;
use App\Models\ProductionOrder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Recording payments against an order, and serving payment-proof files.
 * Split out of ProductionOrderController.
 */
class PaymentController extends Controller
{
    use AuthorizesOrderAccess;

    public function recordPayment(Request $request, ProductionOrder $order): RedirectResponse
    {
        $this->assertOrderVisible($order);

        $data = $request->validate([
            'portion' => ['required', 'in:half,full,balance,partial'],
            'amount' => ['nullable', 'numeric', 'min:1', 'max:100000000', 'required_if:portion,partial'],
            'method' => ['required', 'in:'.implode(',', \App\Models\Payment::METHODS)],
            'reference' => ['nullable', 'string', 'max:255'],
            // Proof is mandatory — no payment is recorded without it.
            // Images/PDF only, never executables. No app-side size cap; PHP's
            // upload_max_filesize (40M) is the practical ceiling.
            'proof' => ['required', 'file', 'mimes:jpg,jpeg,png,webp,pdf'],
        ], [
            'proof.required' => 'A picture/screenshot of the payment proof is required before the payment can be recorded.',
        ]);

        if ($order->total_price === null) {
            return back()->withErrors(['payment' => 'Set a price first (Edit order) before recording a payment.']);
        }

        // Design-first flow: the downpayment is only collected once the client has
        // approved the layout. Until then there is nothing to pay for yet.
        if (! $order->layoutApproved()) {
            return back()->withErrors(['payment' => 'Record the downpayment after the client approves the layout.']);
        }

        $total = (float) $order->total_price;
        $balance = $order->balance() ?? 0;
        $wasFirst = ! $order->hasDownpayment();

        // Partial top-ups are only allowed after a downpayment has been recorded.
        if ($data['portion'] === 'partial' && $wasFirst) {
            return back()->withErrors(['payment' => 'Record the downpayment first, then you can add partial payments.']);
        }

        $amount = match ($data['portion']) {
            'half' => round($total / 2, 2),
            'full' => $wasFirst ? $total : $balance,
            'balance' => $balance,
            // A custom amount, never more than what's still owed.
            'partial' => min(round((float) ($data['amount'] ?? 0), 2), $balance),
        };

        if ($amount <= 0) {
            return back()->withErrors(['payment' => 'Nothing left to pay — this order is fully paid.']);
        }

        $kind = $wasFirst ? ($data['portion'] === 'full' ? 'full' : 'downpayment') : 'payment';

        // Proof files stay on this PC in storage/app (never in the public folder)
        // and are only served through an authenticated route.
        $proofPath = null;
        $proofName = null;
        if ($request->hasFile('proof')) {
            $file = $request->file('proof');
            $proofName = $file->getClientOriginalName();
            $proofPath = $file->store('payment-proofs', 'local');
        }

        $order->recordPayment([
            'amount' => $amount,
            'method' => $data['method'] ?? null,
            'reference' => $data['reference'] ?? null,
            'proof_path' => $proofPath,
            'proof_name' => $proofName,
            'kind' => $kind,
            'recorded_by' => $request->user()->id,
        ]);

        // Safety net: the draft job order is normally created at inquiry, but make
        // sure one exists before the officer fills it in.
        if ($wasFirst && ! $order->jobOrder) {
            $order->jobOrder()->create([
                'status' => 'draft',
                'created_by' => $request->user()->id,
            ]);
        }

        // Flow: layout approved → downpayment → FILL JOB ORDER → send → artist
        // makes the final mockup → template → leader. The first payment opens the
        // job order for the account officer to fill in right away.
        if ($wasFirst) {
            return redirect()->route('job-orders.edit', $order)->with(
                'success',
                'Downpayment recorded (₱'.number_format($amount, 2).'). Fill in the job order below, then send it to the artist.'
            );
        }

        return back()->with('success', 'Payment recorded (₱'.number_format($amount, 2).').');
    }

    /** Serve a payment proof file — only to signed-in sales/leaders/admins. */
    public function proof(\App\Models\Payment $payment)
    {
        // Account officers may only view proofs on their own orders.
        $user = auth()->user();
        if ($user->isSales() && $payment->order && $payment->order->created_by !== $user->id) {
            abort(403);
        }

        abort_unless($payment->hasProof() && \Illuminate\Support\Facades\Storage::disk('local')->exists($payment->proof_path), 404);

        return \Illuminate\Support\Facades\Storage::disk('local')->response(
            $payment->proof_path,
            $payment->proof_name ?: basename($payment->proof_path)
        );
    }
}
