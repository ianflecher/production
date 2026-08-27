@extends('layouts.app')

@section('title', 'Artist Layout Brief — Imprint Production')
@section('page-title', 'Artist Layout Brief')

@push('styles')
<style>
    .layout-brief-page { max-width: 1220px; margin: 0 auto; }
    .layout-page-head {
        display: flex; align-items: flex-end; justify-content: space-between;
        gap: 1rem; margin: 0 0 1.2rem; padding: 0 .15rem;
    }
    .layout-page-head h1 {
        margin: .18rem 0 .25rem; font-size: clamp(1.35rem, 2vw, 1.8rem);
        letter-spacing: -.025em;
    }
    .layout-eyebrow {
        display: block; color: var(--accent); font-size: .72rem;
        font-weight: 800; letter-spacing: .1em; text-transform: uppercase;
    }
    .layout-workspace {
        overflow: hidden; margin-bottom: 1.4rem; border: 1px solid #dfe7f3;
        border-top: 4px solid var(--accent); box-shadow: 0 18px 48px rgba(31, 54, 91, .1);
    }
    .layout-workspace-head {
        display: flex; align-items: center; gap: .85rem; padding: 1rem 1.2rem;
        background: linear-gradient(100deg, #f5f8ff 0%, #fff8f8 100%);
        border-bottom: 1px solid #e6ebf3;
    }
    .layout-workspace-head h2 { margin: 0 0 .18rem; font-size: 1.05rem; }
    .layout-workspace-head p { margin: 0; color: var(--ink-2); font-size: .82rem; }
    .layout-step-mark {
        display: grid; place-items: center; width: 40px; height: 40px; flex: 0 0 auto;
        border-radius: 12px; color: #fff; background: linear-gradient(135deg, var(--accent), #6d5dfc);
        box-shadow: 0 7px 16px rgba(38, 91, 220, .24); font-weight: 800; font-size: .82rem;
    }
    .layout-status-pill {
        margin-left: auto; white-space: nowrap; border-radius: 999px;
        padding: .38rem .68rem; font-size: .72rem; font-weight: 800;
        color: #1559ba; background: #e8f1ff; border: 1px solid #c9ddff;
    }
    .layout-status-pill.is-success { color: var(--success-ink); background: var(--success-soft); border-color: var(--success-border); }
    .layout-workspace-body { display: grid; grid-template-columns: minmax(0, 1.15fr) minmax(310px, .85fr); }
    .layout-pane { min-width: 0; padding: 1.2rem; }
    .layout-status-pane { background: #fbfcfe; border-left: 1px solid #e4eaf3; }
    .layout-section-head {
        display: flex; align-items: center; gap: .55rem; margin-bottom: .85rem;
        color: var(--ink); font-weight: 750; font-size: .88rem;
    }
    .layout-section-head span {
        display: grid; place-items: center; width: 26px; height: 26px;
        border-radius: 8px; background: var(--surface-2); color: var(--accent);
    }
    .layout-file-grid {
        display: grid; grid-template-columns: repeat(auto-fit, minmax(145px, 220px));
        gap: .8rem; margin-bottom: 1rem;
    }
    .layout-file-wrap { position: relative; min-width: 0; }
    .layout-file-wrap .layout-file-card { height: 100%; }
    .layout-file-card {
        display: flex; flex-direction: column; min-width: 0; padding: .5rem;
        border: 1px solid #dce4f0; border-radius: 12px; background: #fff;
        text-align: center; text-decoration: none; transition: transform .15s ease, box-shadow .15s ease, border-color .15s ease;
    }
    .layout-file-card:hover { transform: translateY(-2px); border-color: #b9cae8; box-shadow: 0 10px 22px rgba(31, 54, 91, .1); }
    .layout-file-remove { position: absolute; z-index: 2; top: -.42rem; right: -.42rem; margin: 0; }
    .layout-file-remove button {
        display: grid; place-items: center; width: 25px; height: 25px; padding: 0;
        border: 2px solid #fff; border-radius: 999px; background: #ef4444; color: #fff;
        box-shadow: 0 3px 9px rgba(127, 29, 29, .28); cursor: pointer;
        font-size: 1rem; font-weight: 800; line-height: 1;
    }
    .layout-file-remove button:hover { background: #c81e1e; transform: scale(1.06); }
    .layout-file-remove button:focus-visible { outline: 3px solid rgba(239, 68, 68, .28); outline-offset: 2px; }
    .layout-file-preview {
        display: grid; place-items: center; min-height: 150px; overflow: hidden;
        border-radius: 8px; background: #f4f7fb;
    }
    .layout-file-preview img { display: block; width: 100%; height: 190px; object-fit: contain; }
    .layout-file-name { padding: .48rem .2rem .05rem; color: var(--ink-2); font-size: .72rem; word-break: break-all; }
    .layout-empty {
        display: grid; place-items: center; min-height: 165px; margin-bottom: 1rem;
        border: 1px dashed #c9d5e7; border-radius: 12px; color: var(--ink-3);
        background: #f8faff; text-align: center; font-size: .82rem;
    }
    .layout-upload {
        border: 1px dashed #bbcae2; border-radius: 12px; background: #f8faff;
        padding: .85rem; margin-top: .35rem;
    }
    .layout-upload label { display: block; font-weight: 700; font-size: .86rem; margin-bottom: .18rem; }
    .layout-upload .hint { display: block; color: var(--ink-3); font-size: .76rem; margin-bottom: .6rem; }
    .layout-upload form { display: flex; gap: .65rem; flex-wrap: wrap; align-items: center; }
    .layout-upload input[type=file] { max-width: 100%; font-size: .8rem; }
    .layout-upload input[type=file]::file-selector-button {
        margin-right: .6rem; padding: .44rem .7rem; border: 1px solid #b9c7da;
        border-radius: 8px; background: #fff; color: var(--ink); font-weight: 650; cursor: pointer;
    }
    .layout-sent-note {
        display: inline-flex; align-items: center; gap: .35rem; margin: .1rem 0 0;
        border-radius: 999px; padding: .38rem .65rem; color: var(--success-ink);
        background: var(--success-soft); border: 1px solid var(--success-border); font-size: .78rem; font-weight: 700;
    }
    .layout-status-pane .layout-artist { margin-bottom: 1rem; padding: .75rem; background: #fff; }
    .layout-note-card {
        margin-bottom: 1rem; padding: .85rem; border: 1px solid #e1e7f0;
        border-radius: 12px; background: #fff;
    }
    .layout-note-card strong { display: block; margin-bottom: .32rem; font-size: .78rem; color: var(--ink-2); text-transform: uppercase; letter-spacing: .045em; }
    .layout-note-card p { margin: 0; color: var(--ink); font-size: .88rem; line-height: 1.55; }
    .layout-next { border-radius: 12px; }
    .layout-pre-send textarea { min-height: 105px; resize: vertical; }
    @media (max-width: 820px) {
        .layout-page-head { align-items: flex-start; flex-direction: column; }
        .layout-workspace-body { grid-template-columns: 1fr; }
        .layout-status-pane { border-left: 0; border-top: 1px solid #e4eaf3; }
    }
    @media (max-width: 520px) {
        .layout-pane, .layout-workspace-head { padding: .9rem; }
        .layout-status-pill { display: none; }
        .layout-file-grid { grid-template-columns: 1fr 1fr; gap: .55rem; }
        .layout-file-preview { min-height: 120px; }
        .layout-file-preview img { height: 145px; }
    }
</style>
@endpush

@section('content')
<div class="layout-brief-page">
@include('partials.intake-steps', ['on' => 2])

@php
    $files = $inquiry->layout_files ?? [];
    $layoutStatus = match (true) {
        $inquiry->layoutApproved() => ['Ready for job order', true],
        $inquiry->layoutSubmitted() => ['Client review', false],
        filled($inquiry->layout_sent_at) => ['With artist', false],
        default => ['Preparing brief', false],
    };
    $layoutHeading = $inquiry->layoutApproved()
        ? 'Layout approved'
        : ($inquiry->layoutSubmitted()
            ? 'Layout ready for client review'
            : ($inquiry->layout_sent_at ? 'Layout in progress' : 'Layout — send to an artist first'));
    $layoutSummary = $inquiry->layout_sent_at
        ? 'The brief is locked while it moves through artist work and client approval.'
        : 'Prepare the artist\'s design brief. No downpayment is needed until the client approves the layout.';
@endphp

<div class="layout-page-head">
    <div>
        <span class="layout-eyebrow">Step 2 · Design brief</span>
        <h1>{{ $inquiry->client->fullName() }}</h1>
        <p class="sub" style="margin:0;">Add the design and instructions the artist will work from, then continue to the New Job Order.</p>
    </div>

    <a href="{{ route('inquiries.design-brief', $inquiry) }}" class="btn btn-ghost btn-sm">
        📝 Design questionnaire &amp; ChatGPT prompt
    </a>
</div>

<div class="card layout-workspace">
    <div class="layout-workspace-head">
        <span class="layout-step-mark">02</span>
        <div>
            <h2>{{ $layoutHeading }}</h2>
            <p>{{ $layoutSummary }}</p>
        </div>
        <span @class(['layout-status-pill', 'is-success' => $layoutStatus[1]])>{{ $layoutStatus[0] }}</span>
    </div>

    <div class="layout-workspace-body">
    <section class="layout-pane layout-files-pane">
        <div class="layout-section-head"><span>▧</span> Design files</div>

    @if (count($files))
        <div class="layout-file-grid">
            @foreach ($files as $index => $file)
                <div class="layout-file-wrap">
                    <a href="{{ route('inquiries.layout.file', [$inquiry, 'index' => $index]) }}" target="_blank" class="layout-file-card">
                        <span class="layout-file-preview">
                            @if (str_starts_with($file['mime'] ?? '', 'image/'))
                                <img src="{{ route('inquiries.layout.file', [$inquiry, 'index' => $index]) }}" alt="{{ $file['original_name'] }}">
                            @else
                                <span style="font-size:2rem;">📄</span>
                            @endif
                        </span>
                        <span class="layout-file-name">{{ $file['original_name'] }}</span>
                    </a>

                    @if (! $inquiry->layout_sent_at)
                        <form method="POST" action="{{ route('inquiries.layout.file.delete', [$inquiry, 'index' => $index]) }}"
                              class="layout-file-remove" onsubmit="return confirm('Remove this design file?');">
                            @csrf
                            <button type="submit" aria-label="Remove {{ $file['original_name'] }}" title="Remove wrong file">×</button>
                        </form>
                    @endif
                </div>
            @endforeach
        </div>
    @else
        <div class="layout-empty">
            <span><strong style="display:block; color:var(--ink-2); margin-bottom:.2rem;">No design image yet</strong>Add an image below or give the artist complete notes.</span>
        </div>
    @endif

    @if (! $inquiry->layout_sent_at)
        <div class="layout-upload">
            <label>ChatGPT design output</label>
            <span class="hint">Choose an image and it uploads immediately. This is what the artist works from.</span>
            <form method="POST" action="{{ route('inquiries.layout.upload', $inquiry) }}" enctype="multipart/form-data">
                @csrf
                <input type="file" name="reference_files[]" multiple accept=".jpg,.jpeg,.png,.webp,.gif,.pdf,.ai,.psd,.eps,.cdr,.zip" onchange="if(this.files.length){ this.form.submit(); }">
                @if (count($files))
                    <span style="color:var(--success-ink); font-size:.78rem; font-weight:700;">✓ Uploaded and ready</span>
                @else
                    <span style="color:var(--danger-ink); font-size:.78rem; font-weight:700;">Required unless the notes are complete</span>
                @endif
            </form>
        </div>
    @elseif (count($files))
        <p class="layout-sent-note">✓ Design sent to the artist</p>
    @endif

    </section>
    <section class="layout-pane layout-status-pane">
        <div class="layout-section-head"><span>◎</span> Artist and next step</div>

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
    @elseif (! $inquiry->layout_sent_at)
        <div class="layout-note-card">
            <strong>Artist assignment</strong>
            <p style="color:var(--ink-3);">An available artist will be assigned when you send this brief.</p>
        </div>
    @endif

    @if ($inquiry->layout_sent_at)
        {{-- Already sent. Sending twice would hand the same brief out again, so
             what is left to do here is the job order — and that is the only
             button. The notes stay on screen because the artist is working
             from them and the officer may be asked what they said. --}}
        <div class="layout-note-card">
            <strong>Notes for the artist</strong>
            <p style="color:{{ filled($inquiry->layout_reference_note) ? 'var(--ink)' : 'var(--ink-3)' }};">
                {{ $inquiry->layout_reference_note ?: 'None — the design speaks for itself.' }}
            </p>
        </div>

        {{-- The job order opens on approval and not before. An order written
             against a design the client has not seen is a number on the books
             for something nobody has agreed to. --}}
        @if ($inquiry->layoutApproved())
            <div class="alert alert-success layout-next" style="margin-bottom:0;">
                <strong style="display:block; margin-bottom:.55rem;">The client approved this layout.</strong>
                <a href="{{ route('orders.create', ['inquiry' => $inquiry->id]) }}" class="btn btn-primary btn-sm">
                    Create the job order →
                </a>
            </div>

        @elseif ($inquiry->layoutSubmitted())
            @php $drawings = $inquiry->layoutDrawings(); @endphp

            <div class="alert alert-success layout-next" style="margin-bottom: 0.9rem;">
                <strong>{{ $inquiry->layoutArtist?->name ?? 'The artist' }} has handed the layout back.</strong>
                Show it to the client. Approve it once they say yes.
            </div>

            @if ($drawings->isNotEmpty())
                <div class="layout-file-grid" style="grid-template-columns:repeat(auto-fit,minmax(120px,170px));">
                    @foreach ($inquiry->layout_files as $index => $file)
                        @continue(($file['kind'] ?? '') !== 'layout')
                        <a href="{{ route('inquiries.layout.file', [$inquiry, 'index' => $index]) }}" target="_blank" class="layout-file-card">
                            <span class="layout-file-preview" style="min-height:110px;">
                                @if (str_starts_with($file['mime'] ?? '', 'image/'))
                                    <img src="{{ route('inquiries.layout.file', [$inquiry, 'index' => $index]) }}" alt="{{ $file['original_name'] }}" style="height:130px;">
                                @else
                                    <span style="font-size:1.8rem;">📄</span>
                                @endif
                            </span>
                            <span class="layout-file-name">{{ $file['original_name'] }}</span>
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
            <div class="alert alert-info layout-next" style="margin-bottom: 0;">
                Waiting on {{ $inquiry->layoutArtist?->name ?? 'an artist' }} to draw the layout.
                The job order opens once the client has approved it.
            </div>
        @endif
    @else
        <form method="POST" action="{{ route('inquiries.layout.complete', $inquiry) }}" class="layout-pre-send">
            @csrf
            <label for="reference_note" style="font-weight:700; font-size:.86rem;">Notes for the artist</label>
            <span style="display:block; color:var(--ink-3); font-size:.76rem; margin:.18rem 0 .5rem;">Anything the design doesn't show — text, colours, sizes, or must-keep details.</span>
            <textarea id="reference_note" name="reference_note" rows="4" maxlength="2000" placeholder="e.g. keep the team colors, make the logo bigger on the back" style="width:100%; margin:.4rem 0 .8rem;">{{ old('reference_note', $inquiry->layout_reference_note) }}</textarea>
            @error('layout')<div class="error" style="margin-bottom:.7rem;">{{ $message }}</div>@enderror
            <button type="submit" class="btn btn-primary btn-sm">📤 Send to artist for layout</button>
            <span style="display:inline-block; color:var(--ink-3); font-size:.78rem; margin-left:.4rem;">The job order opens after client approval.</span>
        </form>
    @endif
    </section>
    </div>
</div>
</div>
@endsection
