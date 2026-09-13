{{-- The designing board, the top of it, on the dashboard.

     The team read the spreadsheet every morning to see what moved, so the
     newest rows belong where they already look. The whole board is a click
     away; this is the part that answers "what happened yesterday".

     Expects: $designBoard (rows from App\Support\DesignLog). --}}
@php
    $statusTone = [
        'Massprod' => 'massprod',
        'Sample' => 'sample',
        'Delivered' => 'delivered',
        'Approved' => 'approved',
        'Waiting For Approval' => 'waiting',
        'Designing' => 'designing',
        'Not yet sent' => 'idle',
        'Cancelled' => 'cancelled',
        'Brief' => 'idle',
    ];
    $noteTone = [
        'Waiting For Orderlist' => 'orderlist',
        'Waiting DP' => 'dp',
        'Delivered' => 'delivered',
        'Cancelled' => 'cancelled',
        'On hold' => 'dp',
    ];
@endphp

<div class="card panel" id="design-log-panel" style="margin-bottom: 1.1rem;">
    <div class="officer-head">
        <div>
            <h2 style="margin:0;">Designing board</h2>
            <p class="sub" style="margin:0.15rem 0 0;">The latest designs and where each one has got to.</p>
        </div>
        {{-- Only for the people the full board opens for. A button that
             answers Forbidden is worse than no button. --}}
        @if (auth()->user()->canSeeDesignBoard())
            <a href="{{ route('design.log') }}" class="btn btn-ghost btn-sm">Open the board</a>
        @endif
    </div>

    @if ($designBoard->isEmpty())
        <p class="sub" style="margin:0.6rem 0 0;">No designs have come in this week.</p>
    @else
        {{-- Six columns do not fit a phone, and swiped in a panel this size
             they were not scrolled off but cut off. Stacked instead, which is
             why every cell below names itself - see tbl-stack. --}}
        <div class="tbl-wrap" style="margin-top:0.6rem;">
            <table class="tbl design-log tbl-stack">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Client</th>
                        <th>Agent</th>
                        <th>Artist</th>
                        <th>Status</th>
                        <th>Notes</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($designBoard as $row)
                        <tr>
                            <td style="white-space:nowrap;" data-label="Date">{{ $row['date']->format('n/j') }}</td>
                            <td style="font-weight:600;" data-label="Client">{{ Str::limit($row['client'], 30) }}</td>
                            <td style="white-space:nowrap;" data-label="Agent">{{ $row['agent'] }}</td>
                            <td style="white-space:nowrap;" data-label="Artist">
                                {{ $row['artist'] ?? '—' }}
                                @if ($row['revisions'] > 0)
                                    <span class="dl-rev">R{{ $row['revisions'] }}</span>
                                @endif
                            </td>
                            <td data-label="Status"><span class="dl-tag is-{{ $statusTone[$row['status']] ?? 'idle' }}">{{ $row['status'] }}</span></td>
                            {{-- Printed only when it says something the status does
                                 not - see the board itself, which had the same
                                 column repeating itself down every row. --}}
                            <td data-label="Note">
                                @if ($row['notes'] !== $row['status'] && $row['notes'] !== 'Work in progress')
                                    <span class="dl-tag is-{{ $noteTone[$row['notes']] ?? 'plain' }}">{{ $row['notes'] }}</span>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>
