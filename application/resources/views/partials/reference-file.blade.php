{{-- One reference/design file tile: preview + download. --}}
@php $w = $width ?? 260; @endphp
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
