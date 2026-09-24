<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\AuthorizesOrderAccess;
use App\Models\PaymentProof;
use App\Models\ProductionOrder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

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
            // One payment for every job under this number.
            'covers_all' => ['nullable', 'boolean'],
            // Proof is mandatory — no payment is recorded without it.
            // Images/PDF only, never executables. No app-side size cap; PHP's
            // upload_max_filesize (40M) is the practical ceiling.
            'proofs' => ['required', 'array', 'min:1'],
            'proofs.*' => ['file', 'mimes:jpg,jpeg,png,webp,pdf'],
        ], [
            'proofs.required' => 'A picture/screenshot of the payment proof is required before the payment can be recorded.',
            'proofs.min' => 'A picture/screenshot of the payment proof is required before the payment can be recorded.',
        ]);

        // Does this cover the whole job number?
        //
        // Money is per order - hasDownpayment() asks one order about its own
        // payments and nothing adds them up across a number - so a client who
        // pays once for three designs had that recorded against one of them,
        // and the other two sat waiting for money that had already arrived.
        $covering = $request->boolean('covers_all') ? $order->siblingOrders() : collect([$order]);

        if ($covering->count() > 1) {
            // Every one of them has to be ready, and the refusal has to name
            // which is not - "something is wrong" sends somebody hunting
            // through three orders.
            foreach ($covering as $sibling) {
                if ($sibling->total_price === null) {
                    return back()->withErrors(['payment' => $sibling->designLabelForPayment()
                        .' has no price yet. Price every job under this number before recording one payment for all of them.']);
                }

                if (! $sibling->layoutApproved()) {
                    return back()->withErrors(['payment' => $sibling->designLabelForPayment()
                        .' has not had its layout approved yet, so there is nothing to pay for on it.']);
                }

                if ($sibling->hasPaymentAwaitingFinance()) {
                    return back()->withErrors(['payment' => $sibling->designLabelForPayment()
                        .' already has a payment waiting for Finance. Wait for that before recording another.']);
                }
            }
        }

        if ($order->total_price === null) {
            return back()->withErrors(['payment' => 'Set a price first (Edit order) before recording a payment.']);
        }

        // Design-first flow: the downpayment is only collected once the client has
        // approved the layout. Until then there is nothing to pay for yet.
        if (! $order->layoutApproved()) {
            return back()->withErrors(['payment' => 'Record the downpayment after the client approves the layout.']);
        }

        // The figures the portion buttons mean. One order or all of them, the
        // arithmetic is the same - it is just summed over more rows.
        $total = round($covering->sum(fn ($o) => (float) $o->total_price), 2);
        $balance = round($covering->sum(fn ($o) => $o->balance() ?? 0), 2);
        $wasFirst = $covering->every(fn ($o) => ! $o->hasDownpayment());

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
        $proofUploads = collect($request->file('proofs', []))
            ->values()
            ->map(fn ($file, $position) => [
                'path' => $file->store('payment-proofs', 'local'),
                'name' => $file->getClientOriginalName(),
                'position' => $position,
            ]);

        $primaryProof = $proofUploads->first();

        $method = $data['method'];
        if ($method === \App\Models\Payment::METHOD_OTHER_TRANSFER) {
            $method .= ' — '.trim($data['other_transfer']);
        }

        // One row per order, so each carries its own share and opens its own
        // gate. The reference and the proof are the SAME on every row - it
        // was one transfer, and that is what Finance reconciles against the
        // statement.
        $shares = $covering->count() > 1
            ? ProductionOrder::splitPaymentAcross($amount, $covering)
            : [$order->id => $amount];

        foreach ($covering as $sibling) {
            $share = $shares[$sibling->id] ?? 0.0;

            // A sibling that owes nothing takes no share, and a zero-peso
            // payment row is not a record of anything.
            if ($share <= 0) {
                continue;
            }

            $payment = $sibling->recordPayment([
                'amount' => $share,
                'method' => $method,
                'reference' => trim($data['reference']),
                'proof_path' => $primaryProof['path'] ?? null,
                'proof_name' => $primaryProof['name'] ?? null,
                'kind' => $sibling->hasDownpayment() ? 'payment' : $kind,
                'recorded_by' => $request->user()->id,
            ]);

            foreach ($proofUploads as $proof) {
                $payment->proofFiles()->create([
                    'path' => $proof['path'],
                    'original_name' => $proof['name'],
                    'position' => $proof['position'],
                ]);
            }
        }

        // Safety net: the draft job order is normally created at inquiry, but make
        // sure one exists before the officer fills it in.
        if ($wasFirst) {
            foreach ($covering as $sibling) {
                if (! $sibling->jobOrder) {
                    $sibling->jobOrder()->create([
                        'status' => 'draft',
                        'created_by' => $request->user()->id,
                    ]);
                }
            }
        }

        // Flow: layout approved → downpayment → FINANCE CONFIRMS → artist
        // makes the final mockup → client approves it → account officer fills
        // and sends the Tech Pack.
        //
        // Recording does not release anything any more. What is written here is
        // what the client says they have sent; Finance watches the account and
        // says whether it arrived, and the shop draws on that answer. See
        // FinanceController::confirm, which is where the mockup is unlocked.
        // Say how it was divided, and over what. A figure split three ways
        // without being told is a figure somebody checks by hand.
        $split = $covering->count() > 1
            ? ' Split across '.$covering->count().' jobs under '.$order->order_number.': '
                .$covering->filter(fn ($o) => ($shares[$o->id] ?? 0) > 0)
                    ->map(fn ($o) => $o->designLabelForPayment().' ₱'.number_format($shares[$o->id], 2))
                    ->implode(', ').'.'
            : '';

        if ($wasFirst) {
            return redirect()->route('orders.show', $order)->with(
                'success',
                'Downpayment recorded (₱'.number_format($amount, 2).').'.$split.' '
                .'It goes to Finance to confirm — the artist starts the mockup once they have.'
            );
        }

        return back()->with('success', 'Payment recorded (₱'.number_format($amount, 2).').'.$split);
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

    public function proofFile(PaymentProof $proof)
    {
        $payment = $proof->payment;

        $user = auth()->user();
        if ($user->isSales() && $payment->order && $payment->order->created_by !== $user->id) {
            abort(403);
        }

        abort_unless(Storage::disk('local')->exists($proof->path), 404);

        return Storage::disk('local')->response(
            $proof->path,
            $proof->original_name ?: basename($proof->path)
        );
    }
}
