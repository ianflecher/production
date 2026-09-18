{{-- One reference/design file tile: preview + download.

     Or a LINK, which has no file behind it: a Drive folder, a post, a board of
     pegs. The artist gets the address itself and a button that copies it,
     because the thing they need is the address, not a page about it. --}}
@php $w = $width ?? 260; @endphp
@if ($ref->isExternal())
    <div style="width: {{ $w }}px; text-align: left;">
        <a href="{{ $ref->external_path }}" target="_blank" rel="noopener"
           style="display:block; border:1px solid var(--border); border-radius:8px; padding:0.6rem 0.7rem; background:var(--surface-2);">
            <div style="font-size:1.6rem; line-height:1;">🔗</div>
            <div style="font-weight:700; font-size:0.8rem; margin-top:0.25rem; word-break:break-all;">
                {{ $ref->original_name }}
            </div>
        </a>
        <code style="display:block; font-size:0.66rem; color:var(--ink-3); margin-top:0.35rem; word-break:break-all;">{{ $ref->external_path }}</code>
        <button type="button" class="btn btn-ghost btn-sm" style="margin-top:0.35rem;"
                onclick="navigator.clipboard.writeText(@js($ref->external_path)); this.textContent='✓ Copied';">
            📋 Copy link
        </button>
    </div>
@else
<div style="text-align: center; width: {{ $w }}px;">
    <a href="{{ route('job-order-files.view', $ref) }}" target="_blank">
        @if ($ref->isImage())
            <img src="{{ route('job-order-files.view', $ref) }}" alt="{{ $ref->original_name }}" class="design-preview"
                 style="max-width: 100%; max-height: {{ $w > 260 ? 340 : 260 }}px; border: 1px solid var(--border); border-radius: 8px; display: block; margin: 0 auto;">
        @else
            <div style="font-size: 3.4rem; padding: 2rem 0;">📄</div>
        @endif
    </a>
    <div style="font-size: 0.75rem; color: var(--ink-3); margin-top: 0.4rem; word-break: break-all;">
        {{ $ref->original_name }} ({{ $ref->sizeForHumans() }})
    </div>
    <a href="{{ route('job-order-files.download', $ref) }}" class="btn btn-primary btn-sm" style="margin-top: 0.4rem;">⬇ Download</a>
</div>
@endif
