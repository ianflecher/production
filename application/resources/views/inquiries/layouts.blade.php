@extends('layouts.app')

@section('title', 'Layouts — Imprint Production')
@section('page-title', 'Layouts')

@section('content')

<p class="sub" style="margin-bottom: 1.2rem;">
    Layouts to draw. These come before the job order — nothing is on the books yet,
    which is why they are here rather than on your task list.
</p>

@if ($queue->isEmpty())
    <div class="card panel">
        <p class="sub" style="margin: 0;">Nothing to draw. Anything new will appear here.</p>
    </div>
@else
    @foreach ($queue as $inq)
        <div class="card panel" style="margin-bottom: 1.1rem;">
            <h2>{{ $inq->client->fullName() }}@if ($inq->client->company) — {{ $inq->client->company }}@endif</h2>
            <p class="sub">
                From {{ $inq->officer?->name ?? 'the office' }}
                @if ($inq->layout_sent_at) · sent {{ $inq->layout_sent_at->diffForHumans() }} @endif
                @if ($inq->layoutSubmitted()) · <strong>handed back, waiting on the client</strong> @endif
            </p>

            @if ($inq->what_they_want)
                <p style="margin-bottom: 0.8rem;"><strong>Asking for:</strong> {{ $inq->what_they_want }}</p>
            @endif

            {{-- What the client wants changed. Shown first: it is the reason
                 this one is back. --}}
            @if (filled($inq->layout_revision_note))
                <div class="alert alert-error" style="margin-bottom: 0.9rem;">
                    <strong>Changes asked for:</strong> {{ $inq->layout_revision_note }}
                </div>
            @endif

            @if (filled($inq->layout_reference_note))
                <p style="margin-bottom: 0.8rem;"><strong>Notes from the officer:</strong> {{ $inq->layout_reference_note }}</p>
            @endif

            @php $refs = collect($inq->layout_files ?? []); @endphp

            @if ($refs->isNotEmpty())
                <div style="display: flex; flex-wrap: wrap; gap: 0.7rem; margin-bottom: 0.9rem;">
                    @foreach ($refs as $index => $file)
                        <a href="{{ route('inquiries.layout.file', [$inq, 'index' => $index]) }}" target="_blank"
                           style="border: 1px solid var(--border); border-radius: 8px; padding: 0.5rem; width: 150px; text-align: center; text-decoration: none;">
                            @if (str_starts_with($file['mime'] ?? '', 'image/'))
                                <img src="{{ route('inquiries.layout.file', [$inq, 'index' => $index]) }}" alt="{{ $file['original_name'] }}"
                                     style="max-width: 100%; max-height: 110px; border-radius: 4px; display: block; margin: 0 auto;">
                            @else
                                <div style="font-size: 1.8rem;">📄</div>
                            @endif
                            <div style="font-size: 0.7rem; color: var(--ink-3); margin-top: 0.3rem; word-break: break-all;">
                                {{ $file['original_name'] }}
                                @if (($file['kind'] ?? '') === 'layout') <em>(your layout)</em> @endif
                            </div>
                        </a>
                    @endforeach
                </div>
            @endif

            @if ($inq->layoutWithArtist())
                <form method="POST" action="{{ route('inquiries.layout.submit', $inq) }}" enctype="multipart/form-data"
                      class="artist-layout-upload" style="display: flex; gap: 0.6rem; align-items: center; flex-wrap: wrap;">
                    @csrf
                    <input id="artistLayoutFiles_{{ $inq->id }}" type="file" name="layout_files[]" multiple required
                           class="artist-layout-files"
                           accept=".jpg,.jpeg,.png,.webp,.gif,.pdf,.ai,.psd,.eps,.cdr,.zip">
                    <div class="artist-layout-picked" aria-live="polite"
                         style="display:flex; flex-wrap:wrap; gap:0.45rem; flex-basis:100%;"></div>
                    <button type="submit" class="btn btn-primary btn-sm">Hand back the layout</button>
                    @error('layout_files')<span class="error">{{ $message }}</span>@enderror
                </form>
            @endif
        </div>
    @endforeach
@endif

<script>
    document.querySelectorAll('.artist-layout-files').forEach(function (input) {
        var picked = input.closest('.artist-layout-upload').querySelector('.artist-layout-picked');

        function renderPicked() {
            picked.innerHTML = '';

            Array.prototype.forEach.call(input.files, function (file, index) {
                var item = document.createElement('div');
                item.style.cssText = 'display:flex;align-items:center;gap:.35rem;max-width:240px;padding:.35rem .45rem;border:1px solid var(--border);border-radius:8px;background:var(--surface);';

                var name = document.createElement('span');
                name.textContent = file.name;
                name.title = file.name;
                name.style.cssText = 'min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-size:.72rem;color:var(--ink-2);';

                var remove = document.createElement('button');
                remove.type = 'button';
                remove.textContent = '×';
                remove.title = 'Remove ' + file.name;
                remove.setAttribute('aria-label', 'Remove ' + file.name);
                remove.style.cssText = 'flex:0 0 22px;width:22px;height:22px;padding:0;border:0;border-radius:50%;background:var(--danger,#dc2626);color:#fff;font-size:16px;font-weight:800;line-height:22px;cursor:pointer;';
                remove.addEventListener('click', function () {
                    var remaining = new DataTransfer();
                    Array.prototype.forEach.call(input.files, function (candidate, candidateIndex) {
                        if (candidateIndex !== index) remaining.items.add(candidate);
                    });
                    input.files = remaining.files;
                    renderPicked();
                });

                item.appendChild(name);
                item.appendChild(remove);
                picked.appendChild(item);
            });
        }

        input.addEventListener('change', renderPicked);
    });
</script>

@endsection
