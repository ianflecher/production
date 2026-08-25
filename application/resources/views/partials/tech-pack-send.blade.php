{{-- Sending the pack to the artist.

     Kept in one file because it belongs in two places: on the sheet the office
     reads, and on the officer's own editor. It used to be only the first, so an
     officer who had just finished filling the pack in was sent to production
     details, then to the order page, and had to go and open the pack AGAIN to
     find this button. The last thing they do is send it, so the button is where
     they finish.

     Expects $order and $jo. --}}

@if ($jo->status === 'draft')
    @php
        $canSend = $order->mockupApproved()
            && $order->hasDownpayment()
            && $jo->isReadyToSend()
            && $jo->referenceFiles->isNotEmpty();
        $sendBlockReason = ! $order->mockupApproved()
            ? 'The final mockup must be approved first.'
            : (! $order->hasDownpayment()
                ? 'Record the downpayment before sending.'
                : (! $jo->isReadyToSend()
                    ? 'Fill in Print Type, Printer and Fabric before sending.'
                    : (! $jo->referenceFiles->isNotEmpty()
                        ? 'Upload a client reference before sending.'
                        : null)));
    @endphp
    @if ($canSend)
        <form method="POST" action="{{ route('job-orders.send', $order) }}" onsubmit="return confirm('Send this Tech Pack to the artist?');" style="margin-right: auto;">
            @csrf
            <button type="submit" class="btn btn-success btn-sm">📤 Send Tech Pack to Artist</button>
        </form>
    @else
        <span style="margin-right: auto; color: var(--danger-ink); font-weight: 600; font-size: 0.85rem;">⚠ {{ $sendBlockReason }}</span>
    @endif
@else
    <span style="margin-right: auto; color: var(--success-ink); font-weight: 600; font-size: 0.85rem;">✓ Sent to the artist {{ $jo->sent_to_artist_at?->format('M j, g:i A') }}</span>
@endif
