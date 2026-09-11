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

        // An officer may record what the client says they paid once. Until
        // Finance confirms or rejects that claim, another entry would either
        // duplicate the deposit or make the balance impossible to audit.
        if ($order->hasPaymentAwaitingFinance()) {
            return back()->withErrors(['payment' => 'Finance is still checking the recorded payment. Wait for their confirmation before recording another payment.']);
        }

        $data = $request->validate([
            'portion' => ['required', 'in:half,custom_downpayment,full,balance,partial'],
            'amount' => ['nullable', 'numeric', 'min:1', 'max:100000000', 'required_if:portion,partial,custom_downpayment'],
            'method' => ['required', 'in:'.implode(',', \App\Models\Payment::METHODS)],
            'other_transfer' => ['nullable', 'string', 'max:100', 'required_if:method,'.\App\Models\Payment::METHOD_OTHER_TRANSFER],
            'reference' => ['required', 'string', 'max:255'],
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

        if ($data['portion'] === 'custom_downpayment' && ! $wasFirst) {
            return back()->withErrors(['payment' => 'The downpayment is already recorded. Use a partial payment or pay the remaining balance.']);
        }

        $minimumDownpayment = round($total / 2, 2);
        if ($data['portion'] === 'custom_downpayment'
            && round((float) ($data['amount'] ?? 0), 2) < $minimumDownpayment) {
            return back()->withErrors(['amount' => 'The first downpayment must be at least 50% (₱'.number_format($minimumDownpayment, 2).').']);
        }

        $amount = match ($data['portion']) {
            'half' => round($total / 2, 2),
            // An agent may collect more than 50% up front, but never more
            // than the total balance of the order.
            'custom_downpayment' => min(round((float) ($data['amount'] ?? 0), 2), $balance),
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

        $method = $data['method'];
        if ($method === \App\Models\Payment::METHOD_OTHER_TRANSFER) {
            $method .= ' — '.trim($data['other_transfer']);
        }

        $order->recordPayment([
            'amount' => $amount,
            'method' => $method,
            'reference' => trim($data['reference']),
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

        // Flow: layout approved → downpayment → FINANCE CONFIRMS → artist
        // makes the final mockup → client approves it → account officer fills
        // and sends the Tech Pack.
        //
        // Recording does not release anything any more. What is written here is
        // what the client says they have sent; Finance watches the account and
        // says whether it arrived, and the shop draws on that answer. See
        // FinanceController::confirm, which is where the mockup is unlocked.
        if ($wasFirst) {
            return redirect()->route('orders.show', $order)->with(
                'success',
                'Downpayment recorded (₱'.number_format($amount, 2).'). '
                .'It goes to Finance to confirm — the artist starts the mockup once they have.'
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
