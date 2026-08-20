{{-- The two order charts — by status, and created over the last stretch of
     days. The leader's dashboard and the account officer's both end with these,
     drawn exactly the same way from exactly the same figures, so they are kept
     once here rather than twice in dashboard.blade.php.

     Every value comes from DashboardController; a plain @include inherits them.
     Expects: $totalOrders, $designCount, $productionCount, $completedCount,
     $otherCount, $chartDays, $linePoints, $areaPoints, $stopOne, $stopTwo,
     $stopThree. --}}
    @if ($totalOrders > 0)
        <div class="dash-chart-grid">
<div class="dash-card">
    <div class="dash-card-head">
        <div>
            <h2>Orders by current step</h2>
            <p>Where the work is sitting right now.</p>
        </div>
    </div>

    @php
        // One wedge per step, built from the counts rather than three fixed
        // stops. "In production" used to be a single wedge holding everything
        // past design, which on a shop where everything is past design was one
        // colour filling the chart and answering nothing.
        $slices = collect($stepSlices ?? []);
        $sliceTotal = max(1, (int) ($stepTotal ?? $slices->sum('value')));

        $at = 0.0;
        $stops = [];

        foreach ($slices as $slice) {
            $from = $at;
            $at += ($slice['value'] / $sliceTotal) * 100;
            $stops[] = $slice['color'].' '.round($from, 2).'% '.round($at, 2).'%';
        }

        // Anything unaccounted for stays visibly grey rather than stretching
        // the last wedge over it.
        if ($at < 99.99) {
            $stops[] = '#DCE3EA '.round($at, 2).'% 100%';
        }
    @endphp

    <div class="dash-donut-layout">
        <div class="dash-donut" style="background: conic-gradient({{ implode(', ', $stops) }});">
            <div class="dash-donut-center">
                <strong>{{ $stepTotal ?? $totalOrders }}</strong>
                <span>Total orders</span>
            </div>
        </div>

        <div class="dash-legend">
            @forelse ($slices as $slice)
                <div class="dash-legend-row">
                    <span class="dash-legend-dot" style="background: {{ $slice['color'] }};"></span>
                    <span>{{ $slice['label'] }}</span>
                    <strong>{{ $slice['value'] }}</strong>
                </div>
            @empty
                <div class="dash-legend-row">
                    <span>No orders on the floor yet.</span>
                </div>
            @endforelse
        </div>
    </div>
</div>

<div class="dash-card">
    <div class="dash-card-head">
        <div>
            <h2>Orders created</h2>
            <p>Last eight days from the orders loaded on this dashboard.</p>
        </div>
    </div>

    <div class="dash-line-chart">
        <svg
            viewBox="0 0 800 180"
            role="img"
            aria-label="Orders created during the last eight days"
        >
            <defs>
                <linearGradient
                    id="ordersArea"
                    x1="0"
                    y1="0"
                    x2="0"
                    y2="1"
                >
                    <stop
                        offset="0%"
                        stop-color="#e31b23"
                        stop-opacity="0.24"
                    />

                    <stop
                        offset="100%"
                        stop-color="#e31b23"
                        stop-opacity="0"
                    />
                </linearGradient>
            </defs>

            <line x1="20" y1="40" x2="780" y2="40" stroke="#edf1f5" />
            <line x1="20" y1="80" x2="780" y2="80" stroke="#edf1f5" />
            <line x1="20" y1="120" x2="780" y2="120" stroke="#edf1f5" />
            <line x1="20" y1="165" x2="780" y2="165" stroke="#dfe6ed" />

            <polygon
                points="{{ $areaPoints }}"
                fill="url(#ordersArea)"
            />

            <polyline
                points="{{ $linePoints }}"
                fill="none"
                stroke="#e31b23"
                stroke-width="4"
                stroke-linecap="round"
                stroke-linejoin="round"
            />
        </svg>

        <div class="dash-chart-labels">
            <span>{{ $chartDays->first()->format('M j') }}</span>
            <span>{{ $chartDays->get(3)->format('M j') }}</span>
            <span>{{ $chartDays->last()->format('M j') }}</span>
        </div>
    </div>
</div>

        </div>
    @endif
