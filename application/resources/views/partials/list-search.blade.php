{{-- One search box, used by every list.

     The orders list grew its own; every other list then had none, so finding
     a material meant scrolling. This is that box, made shared, so the lists
     all look and behave the same and a fix reaches all of them.

     Expects: $action (route to submit to), $value (current term).
     Optional: $placeholder, $label, $keep (hidden fields to carry through,
     e.g. a status filter or a page size). --}}
@php
    $placeholder = $placeholder ?? 'Search';
    $label = $label ?? $placeholder;
    $keep = $keep ?? [];
@endphp

<form method="GET" action="{{ $action }}" class="list-search" role="search">
    @foreach ($keep as $name => $val)
        @if (filled($val))
            <input type="hidden" name="{{ $name }}" value="{{ $val }}">
        @endif
    @endforeach

    <div class="list-search-box">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
             stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/>
        </svg>

        <input type="search" name="q" value="{{ $value }}"
               placeholder="{{ $placeholder }}" autocomplete="off" aria-label="{{ $label }}">
    </div>

    <button class="btn btn-primary btn-sm">Search</button>

    {{-- Only offered once there is something to clear. --}}
    @if (filled($value))
        <a href="{{ $action }}" class="btn btn-ghost btn-sm">Clear</a>
        <span class="list-search-note">Showing matches for “{{ $value }}”</span>
    @endif
</form>
