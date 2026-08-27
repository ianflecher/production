@extends('layouts.app')

@section('title', 'Artist Layout Brief — Imprint Production')
@section('page-title', 'Artist Layout Brief')

@section('content')
@include('partials.intake-steps', ['on' => 2])

<p class="sub" style="margin-bottom: 1.2rem;">{{ $inquiry->client->fullName() }}. Add the design and instructions the artist will work from, then continue to the New Job Order.</p>

<div class="card panel" style="margin-bottom: 1.4rem; border-left: 4px solid var(--accent);">
    <h2>Layout — send to an artist first</h2>
    <p class="sub" style="margin-bottom: 0.8rem;">Get the details from the client, then send it to an artist for the layout. No downpayment is needed yet — the client reviews and approves the layout first.</p>

    <a href="{{ route('inquiries.design-brief', $inquiry) }}" class="btn btn-ghost btn-sm" style="margin-bottom: 1rem;">
        📝 Design questionnaire &amp; ChatGPT prompt
    </a>

    @php $files = $inquiry->layout_files ?? []; @endphp
    @if (count($files))
        <div style="display:flex; flex-wrap:wrap; gap:.8rem; margin-bottom:1rem;">
            @foreach ($files as $index => $file)
                <a href="{{ route('inquiries.layout.file', [$inquiry, 'index' => $index]) }}" target="_blank" style="border:1px solid var(--border); border-radius:8px; padding:.5rem; width:150px; text-align:center; text-decoration:none;">
                    @if (str_starts_with($file['mime'] ?? '', 'image/'))
                        <img src="{{ route('inquiries.layout.file', [$inquiry, 'index' => $index]) }}" alt="{{ $file['original_name'] }}" style="max-width:100%; max-height:110px; border-radius:4px; display:block; margin:0 auto;">
                    @else
                        <div style="font-size:2rem;">📄</div>
                    @endif
                    <div style="font-size:.72rem; color:var(--ink-3); margin-top:.3rem; word-break:break-all;">{{ $file['original_name'] }}</div>
                </a>
            @endforeach
        </div>
    @endif

    <label style="font-weight:600; font-size:.9rem;">ChatGPT design output <span style="font-weight:400; color:var(--ink-3);">— save the image from ChatGPT, then upload it here. This is what the artist works from.</span></label>
    <form method="POST" action="{{ route('inquiries.layout.upload', $inquiry) }}" enctype="multipart/form-data" style="display:flex; gap:.6rem; flex-wrap:wrap; align-items:center; margin:.4rem 0 1rem;">
        @csrf
        {{-- No upload button: choosing the files is the whole gesture, and a
             button that only repeats what already happened is one more thing
             to wonder whether you were meant to press. --}}
        <input type="file" name="reference_files[]" multiple accept=".jpg,.jpeg,.png,.webp,.gif,.pdf,.ai,.psd,.eps,.cdr,.zip" onchange="if(this.files.length){ this.form.submit(); }">
        @if (count($files))
            <span style="color:var(--success-ink); font-size:.82rem;">✓ design uploaded — the artist will see this</span>
        @else
            <span style="color:var(--danger-ink); font-size:.82rem; font-weight:600;">⚠ no design uploaded yet</span>
        @endif
    </form>

    {{-- Who has it. Named the moment it is sent, so the officer can answer
         "who is drawing this?" without opening the job order. --}}
    @if ($inquiry->layoutArtist)
        <div class="layout-artist">
            <span class="layout-artist-avatar">{{ mb_substr($inquiry->layoutArtist->name, 0, 1) }}</span>
            <span>
                <strong>{{ $inquiry->layoutArtist->name }}</strong> has the layout
                <span class="layout-artist-when">
                    @if ($inquiry->layout_sent_at)
                        — sent {{ $inquiry->layout_sent_at->diffForHumans() }}
                    @endif
                </span>
            </span>
        </div>
    @endif

    @if ($inquiry->layout_sent_at)
        {{-- Already sent. Sending twice would hand the same brief out again, so
             what is left to do here is the job order — and that is the only
             button. The notes stay on screen because the artist is working
             from them and the officer may be asked what they said. --}}
        <label style="font-weight:600; font-size:.9rem;">Notes for the artist</label>
        <p style="margin:.4rem 0 1rem; font-size:.9rem; color:{{ filled($inquiry->layout_reference_note) ? 'var(--ink)' : 'var(--ink-3)' }};">
            {{ $inquiry->layout_reference_note ?: 'None — the design speaks for itself.' }}
        </p>

        {{-- The job order opens on approval and not before. An order written
             against a design the client has not seen is a number on the books
             for something nobody has agreed to. --}}
        @if ($inquiry->layoutApproved())
            <a href="{{ route('orders.create', ['inquiry' => $inquiry->id]) }}" class="btn btn-primary btn-sm">
                Create the job order →
            </a>

        @elseif ($inquiry->layoutSubmitted())
            @php $drawings = $inquiry->layoutDrawings(); @endphp

            <div class="alert alert-success" style="margin-bottom: 0.9rem;">
                <strong>{{ $inquiry->layoutArtist?->name ?? 'The artist' }} has handed the layout back.</strong>
                Show it to the client. Approve it once they say yes.
            </div>

            @if ($drawings->isNotEmpty())
                <div style="display: flex; flex-wrap: wrap; gap: 0.7rem; margin-bottom: 0.9rem;">
                    @foreach ($inquiry->layout_files as $index => $file)
                        @continue(($file['kind'] ?? '') !== 'layout')
                        <a href="{{ route('inquiries.layout.file', [$inquiry, 'index' => $index]) }}" target="_blank"
                           style="border:1px solid var(--border); border-radius:8px; padding:.5rem; width:150px; text-align:center; text-decoration:none;">
                            @if (str_starts_with($file['mime'] ?? '', 'image/'))
                                <img src="{{ route('inquiries.layout.file', [$inquiry, 'index' => $index]) }}" alt="{{ $file['original_name'] }}"
                                     style="max-width:100%; max-height:130px; border-radius:4px; display:block; margin:0 auto;">
                            @else
                                <div style="font-size:1.8rem;">📄</div>
                            @endif
                            <div style="font-size:.7rem; color:var(--ink-3); margin-top:.3rem; word-break:break-all;">{{ $file['original_name'] }}</div>
                        </a>
                    @endforeach
                </div>
            @endif

            <div style="display: flex; gap: 0.6rem; flex-wrap: wrap; align-items: flex-start;">
                <form method="POST" action="{{ route('inquiries.layout.approve', $inquiry) }}">
                    @csrf
                    <button type="submit" class="btn btn-primary btn-sm">✓ Client approved — write the job order</button>
                </form>

                <form method="POST" action="{{ route('inquiries.layout.revise', $inquiry) }}"
                      style="display: flex; gap: 0.5rem; align-items: flex-start; flex-wrap: wrap;">
                    @csrf
                    <input type="text" name="layout_revision_note" maxlength="2000" required
                           placeholder="What the client wants changed" style="min-width: 260px;">
                    <button type="submit" class="btn btn-ghost btn-sm">↩ Send back</button>
                </form>
            </div>
            @error('layout_revision_note')<div class="error" style="margin-top:.5rem;">{{ $message }}</div>@enderror

        @else
            <div class="alert alert-info" style="margin-bottom: 0;">
                Waiting on {{ $inquiry->layoutArtist?->name ?? 'an artist' }} to draw the layout.
                The job order opens once the client has approved it.
            </div>
        @endif
    @else
        <form method="POST" action="{{ route('inquiries.layout.complete', $inquiry) }}">
            @csrf
            <label for="reference_note" style="font-weight:600; font-size:.9rem;">Notes for the artist <span style="font-weight:400; color:var(--ink-3);">(anything the design doesn't show — text/colors/size, must-keep details)</span></label>
            <textarea id="reference_note" name="reference_note" rows="4" maxlength="2000" placeholder="e.g. keep the team colors, make the logo bigger on the back" style="width:100%; margin:.4rem 0 .8rem;">{{ old('reference_note', $inquiry->layout_reference_note) }}</textarea>
            @error('layout')<div class="error" style="margin-bottom:.7rem;">{{ $message }}</div>@enderror
            <button type="submit" class="btn btn-primary btn-sm">📤 Send to artist for layout</button>
            <span style="color:var(--ink-3); font-size:.8rem; margin-left:.4rem;">Next: New Job Order.</span>
        </form>
    @endif
</div>
@endsection
