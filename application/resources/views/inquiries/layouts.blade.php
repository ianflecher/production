@extends('layouts.app')

@section('title', 'Layouts — Imprint Production')
@section('page-title', 'Layouts')

@section('content')

<p class="sub" style="margin-bottom: 1.2rem;">
    Layouts to draw. These come before the job order — nothing is on the books yet,
    which is why they are here rather than on your task list.
</p>

{{-- The same box every other list uses. An artist with a queue full of layouts
     was scrolling to find the one a client just rang about. --}}
@include('partials.list-search', [
    'action' => route('inquiries.layouts'),
    'value' => $search,
    'placeholder' => 'Client, company, or what they asked for…',
    'label' => 'Search layouts',
])

@if ($queue->isEmpty())
    <div class="card panel">
        <p class="sub" style="margin: 0;">
            @if (filled($search))
                Nothing matches “{{ $search }}”.
            @else
                Nothing to draw. Anything new will appear here.
            @endif
        </p>
    </div>
@else
    @foreach ($queue as $designs)
        @php $inq = $designs->first()->inquiry; @endphp
        <div class="card panel" style="margin-bottom: 1.1rem;">
            <h2>{{ $inq->client->fullName() }}@if ($inq->client->company) - {{ $inq->client->company }}@endif</h2>
            <p class="sub">
                From {{ $inq->officer?->name ?? 'the office' }}
                @if ($inq->layout_sent_at) &middot; sent {{ $inq->layout_sent_at->diffForHumans() }} @endif
                &middot; <strong>{{ $designs->count() }} {{ \Illuminate\Support\Str::plural('design', $designs->count()) }} for you</strong>
            </p>

            @if ($inq->what_they_want)
                <p style="margin-bottom: 0.8rem;"><strong>Asking for:</strong> {{ $inq->what_they_want }}</p>
            @endif

            @if (filled($inq->layout_reference_note))
                {{-- Six changes typed into one box arrived here as one unbroken
                     paragraph, and the fourth one got missed. Read them back as
                     the list the officer meant. --}}
                <div style="margin-bottom: 0.8rem;">
                    <strong>Notes from the officer:</strong>
                    @include('partials.note-lines', ['note' => $inq->layout_reference_note])
                </div>
            @endif

            {{-- The brief material is the same for every design under it, so it
                 is shown once here rather than repeated on each one. --}}
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
                                <div style="font-size: 1.8rem;">&#128196;</div>
                            @endif
                            <div style="font-size: 0.7rem; color: var(--ink-3); margin-top: 0.3rem; word-break: break-all;">
                                {{ $file['original_name'] }}
                                @if (($file['kind'] ?? '') === 'revision') <em>(sent back with the change)</em> @endif
                            </div>
                        </a>
                    @endforeach
                </div>
            @endif

            {{-- Then the designs: each one drawn, handed back and answered on
                 its own, so five of a six-piece kit can be finished while the
                 sixth is still being redrawn. --}}
            @foreach ($designs as $design)
                <div style="border:1px solid var(--border); border-radius:10px; padding:0.85rem; margin-bottom:0.75rem;">
                    <div style="display:flex; justify-content:space-between; align-items:center; gap:0.6rem; flex-wrap:wrap;">
                        <strong>{{ $design->name() }}</strong>
                        <span class="sub" style="margin:0;">
                            @if ($design->submitted())
                                handed back, waiting on the client
                            @elseif ($design->revision_count > 0)
                                Revision {{ $design->revision_count }} of {{ \App\Models\InquiryDesign::REVISION_LIMIT }}
                            @else
                                to draw
                            @endif
                        </span>
                    </div>

                    {{-- What the client wants changed on THIS one, shown first:
                         it is the reason this design came back. --}}
                    @if (filled($design->revision_note))
                        <div class="alert alert-error" style="margin:0.6rem 0;">
                            <strong>Changes asked for:</strong>
                            @include('partials.note-lines', ['note' => $design->revision_note])
                        </div>
                    @endif

                    @if ($design->drawings()->isNotEmpty())
                        <div style="display:flex; flex-wrap:wrap; gap:0.7rem; margin:0.7rem 0;">
                            @foreach ($design->drawings() as $index => $file)
                                <a href="{{ route('inquiries.designs.file', [$design, 'index' => $index]) }}" target="_blank"
                                   style="border:1px solid var(--border); border-radius:8px; padding:0.5rem; width:150px; text-align:center; text-decoration:none;">
                                    @if (str_starts_with($file['mime'] ?? '', 'image/'))
                                        <img src="{{ route('inquiries.designs.file', [$design, 'index' => $index]) }}" alt="{{ $file['original_name'] }}"
                                             style="max-width:100%; max-height:110px; border-radius:4px; display:block; margin:0 auto;">
                                    @else
                                        <div style="font-size:1.8rem;">&#128196;</div>
                                    @endif
                                    <div style="font-size:0.7rem; color:var(--ink-3); margin-top:0.3rem; word-break:break-all;">
                                        {{ $file['original_name'] }} <em>(yours)</em>
                                    </div>
                                </a>
                            @endforeach
                        </div>
                    @endif

                    {{-- Somewhere to put the work, but only while there IS work.

                         A design sitting with the client is not waiting on the
                         artist, and an upload box there invited a revision
                         nobody had asked for - and quietly took the design off
                         the client's desk when one was sent. The box comes back
                         the moment the client asks for a change, which is when
                         the design returns to the artist carrying the note. --}}
                    @if (! $design->submitted())
                        <form method="POST" action="{{ route('inquiries.designs.submit', $design) }}" enctype="multipart/form-data"
                              class="artist-layout-upload" style="display:flex; gap:0.6rem; align-items:center; flex-wrap:wrap;">
                            @csrf
                            <input id="artistDesignFiles_{{ $design->id }}" type="file" name="files[]" multiple required
                                   class="artist-layout-files"
                                   accept=".jpg,.jpeg,.png,.webp,.gif,.pdf,.ai,.psd,.eps,.cdr,.zip">
                            <div class="artist-layout-picked" aria-live="polite"
                                 style="display:flex; flex-wrap:wrap; gap:0.45rem; flex-basis:100%;"></div>
                            <button type="submit" class="btn btn-primary btn-sm">
                                {{ filled($design->revision_note) || $design->revision_count > 0
                                    ? 'Upload the revised design'
                                    : 'Hand back '.$design->name() }}
                            </button>
                        </form>
                    @else
                        <p class="sub" style="margin:.5rem 0 0;">
                            Handed back &mdash; waiting on the client. It comes back here if they ask for a change.
                        </p>
                    @endif
                </div>
            @endforeach
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
