@php
    // Vivid category chips: a saturated tint, a matching border, and readable
    // colored text. The dot echoes the text colour.
    $statusStyles = [
        'todo' => ['bg' => '#eef1f6', 'fg' => '#475569', 'bd' => '#d5dce8'],
        'ready' => ['bg' => '#dbeafe', 'fg' => '#1d4ed8', 'bd' => '#bfdbfe'],
        'in_progress' => ['bg' => '#fef3c7', 'fg' => '#b45309', 'bd' => '#fcd34d'],
        'for_checking' => ['bg' => '#ede9fe', 'fg' => '#6d28d9', 'bd' => '#ddd6fe'],
        'revision_required' => ['bg' => '#fee2e2', 'fg' => '#b91c1c', 'bd' => '#fecaca'],
        'complete' => ['bg' => '#dcfce7', 'fg' => '#15803d', 'bd' => '#bbf7d0'],
        'on_hold' => ['bg' => '#fef08a', 'fg' => '#854d0e', 'bd' => '#fde047'],
        'cancelled' => ['bg' => '#eef1f6', 'fg' => '#94a3b8', 'bd' => '#e2e8f0'],
        'active' => ['bg' => '#dbeafe', 'fg' => '#1d4ed8', 'bd' => '#bfdbfe'],
    ];
    $statusLabels = \App\Models\Task::STATUS_LABELS + \App\Models\ProductionOrder::STATUS_LABELS;
    $s = $statusStyles[$status] ?? $statusStyles['todo'];
@endphp
<span class="badge" style="background: {{ $s['bg'] }}; color: {{ $s['fg'] }}; box-shadow: inset 0 0 0 1px {{ $s['bd'] }};"><span class="dot"></span>{{ $statusLabels[$status] ?? strtoupper($status) }}</span>
