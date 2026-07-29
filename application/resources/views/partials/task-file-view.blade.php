{{-- Render one task file: a network-path reference (copyable / link), an image,
     or a download link. Expects $file; optional $maxH for image height. --}}
@php $maxH = $maxH ?? '600px'; @endphp
@if ($file->isExternal())
    <div style="text-align:center; padding:1rem;">
        <div style="font-size:2rem; line-height:1;">📁</div>
        <div style="max-width:640px; margin:0.4rem auto; word-break:break-all; font-family:ui-monospace,Consolas,monospace; font-size:0.85rem; background:#f4f6f9; border:1px solid #dce3ea; border-radius:6px; padding:0.5rem 0.7rem;">{{ $file->external_path }}</div>
        <div class="no-print" style="display:flex; gap:0.5rem; justify-content:center; flex-wrap:wrap;">
            @if ($file->isWebLink())
                <a href="{{ $file->external_path }}" target="_blank" rel="noopener" class="btn btn-primary btn-sm">↗ Open</a>
            @endif
            <button type="button" class="btn btn-ghost btn-sm"
                    onclick="navigator.clipboard.writeText(@js($file->external_path)); this.textContent='✓ Copied';">📋 Copy path</button>
        </div>
    </div>
@elseif ($file->isImage())
    <img src="{{ route('tasks.file.view', $file) }}" alt="{{ $file->label ?? 'file' }}"
         style="max-width:100%; max-height:{{ $maxH }}; object-fit:contain; display:block; margin:0 auto;">
@else
    <div style="text-align:center; padding:1.5rem;">
        <div style="font-size:2rem; line-height:1;">📄</div>
        <a href="{{ route('tasks.file.view', $file) }}" target="_blank" rel="noopener" style="font-weight:700;">Open {{ $file->original_name }}</a>
    </div>
@endif
