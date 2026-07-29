{{-- One production file for a station (TIFF / sticker / embroidery).
     Expects: $file (TaskFile|null), $label (string).
     Files are usually a network-path reference now, not an upload. --}}
<div style="text-align:center; padding:1.5rem 1rem;">
    @if (! $file)
        <div style="color:#999;">No {{ $label }} yet.</div>
    @elseif ($file->isExternal())
        {{-- Network path / link — copyable, clickable when it's a web URL. --}}
        <div style="font-size:3rem; line-height:1; margin-bottom:0.6rem;">📁</div>
        <div style="font-weight:700; margin-bottom:0.2rem;">{{ $label }} — on the shared drive</div>
        <div style="max-width:640px; margin:0.4rem auto 1rem; word-break:break-all; font-family:ui-monospace,Consolas,monospace; font-size:0.85rem; background:#f4f6f9; border:1px solid #dce3ea; border-radius:6px; padding:0.6rem 0.8rem;">{{ $file->external_path }}</div>
        <div class="no-print" style="display:flex; gap:0.5rem; justify-content:center; flex-wrap:wrap;">
            @if ($file->isWebLink())
                <a href="{{ $file->external_path }}" target="_blank" rel="noopener" class="btn btn-primary btn-sm">↗ Open</a>
            @endif
            <button type="button" class="btn btn-ghost btn-sm"
                    onclick="navigator.clipboard.writeText(@js($file->external_path)); this.textContent='✓ Copied';">📋 Copy path</button>
        </div>
    @elseif ($file->isImage())
        <img src="{{ route('tasks.file.view', $file) }}" alt="{{ $label }}"
             style="max-width:100%; max-height:70vh; object-fit:contain; display:block; margin:0 auto 1rem;">
        <a href="{{ route('tasks.file.download', $file) }}" class="btn btn-primary btn-sm no-print">⬇ Download {{ $label }}</a>
    @else
        @php $ext = strtolower(pathinfo($file->original_name, PATHINFO_EXTENSION)); @endphp
        <div style="font-size:3rem; line-height:1; margin-bottom:0.6rem;">🗂</div>
        <div style="font-weight:700; margin-bottom:0.2rem;">{{ $file->original_name }}</div>
        <div style="color:#888; font-size:0.85rem; margin-bottom:1rem;">{{ strtoupper($ext) }} · {{ $file->sizeForHumans() }}</div>
        <a href="{{ route('tasks.file.download', $file) }}" class="btn btn-primary">⬇ Download {{ $label }}</a>
    @endif
</div>
