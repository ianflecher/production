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
    .layout-order-ready {
        display: flex; align-items: center; gap: .85rem; justify-content: space-between;
        padding: .9rem 1rem;
    }
    .layout-order-copy { min-width: 0; }
    .layout-order-kicker { display: block; margin-bottom: .14rem; font-size: .72rem; font-weight: 800; letter-spacing: .06em; text-transform: uppercase; }
    .layout-order-title { display: block; font-size: .92rem; line-height: 1.28; }
    .layout-order-lock { display: block; margin-top: .3rem; font-size: .75rem; color: var(--ink-2); }
    .layout-order-ready .btn { flex: 0 0 auto; white-space: nowrap; }
    .layout-pre-send textarea { min-height: 105px; resize: vertical; }
    @media (max-width: 820px) {
        .layout-page-head { align-items: flex-start; flex-direction: column; }
        .layout-workspace-body { grid-template-columns: 1fr; }
        .layout-status-pane { border-left: 0; border-top: 1px solid #e4eaf3; }
        .layout-order-ready { align-items: flex-start; flex-direction: column; }
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

    {{-- The designs on this brief.

         A client who wants six is ordinary, and they are not all one person's
         work: five can be Cristal's and the sixth Mick's. Each is listed with
         whose desk it is on and answered on its own, so approving five and
         sending one back is something this page can actually say. --}}
    @php $designs = $inquiry->designs; @endphp

    @error('designs')<div class="error" style="margin-bottom:.6rem;">{{ $message }}</div>@enderror

    {{-- Moving the whole brief at once. A leader watching an artist go home
         sick does not want to move six designs one at a time; what the client
         has already approved stays with whoever drew it. --}}
    @if (auth()->user()->isLeader() && $artists->isNotEmpty() && $designs->isNotEmpty())
        <form method="POST" action="{{ route('inquiries.layout.artist', $inquiry) }}"
              style="display:flex; gap:.5rem; align-items:flex-end; flex-wrap:wrap; margin:.6rem 0 1rem;">
            @csrf
            <div class="field" style="margin:0;">
                <label for="layout_artist_id">Hand it to someone else</label>
                <select id="layout_artist_id" name="layout_artist_id" required>
                    @foreach ($artists as $candidate)
                        <option value="{{ $candidate->id }}" @selected($inquiry->layout_artist_id === $candidate->id)>
                            {{ $candidate->name }}@if (! $candidate->isPresentToday()) - not in today @endif
                        </option>
                    @endforeach
                </select>
            </div>
            <button type="submit" class="btn btn-ghost btn-sm">Move every design</button>
        </form>
        @error('layout_artist_id')<div class="error" style="margin-bottom:.6rem;">{{ $message }}</div>@enderror
    @endif

    @if ($designs->isEmpty())
        <div class="layout-note-card">
            <strong>No designs listed yet</strong>
            <p style="color:var(--ink-3);">Add one below for each design this client wants. Five for one artist and one for another is fine - each is drawn and approved on its own.</p>
        </div>
    @else
        <div class="layout-note-card" style="margin-bottom:1rem;">
            <strong>{{ $designs->count() }} {{ \Illuminate\Support\Str::plural('design', $designs->count()) }} on this brief</strong>
            <p style="color:var(--ink-3); margin:.2rem 0 0;">
                {{ $designs->where('status', \App\Models\InquiryDesign::STATUS_APPROVED)->count() }} approved
                &middot; {{ $designs->where('status', \App\Models\InquiryDesign::STATUS_SUBMITTED)->count() }} waiting on the client
                &middot; {{ $designs->where('status', \App\Models\InquiryDesign::STATUS_WITH_ARTIST)->count() }} with the artists
            </p>
        </div>
    @endif

    @foreach ($designs as $design)
        <div style="border:1px solid var(--border); border-radius:10px; padding:.8rem; margin-bottom:.8rem;">
            <div style="display:flex; justify-content:space-between; gap:.5rem; align-items:center; flex-wrap:wrap;">
                <strong>{{ $design->name() }}</strong>
                <span class="sub" style="margin:0;">
                    @if ($design->approved())
                        &#10003; approved
                    @elseif ($design->submitted())
                        waiting on the client
                    @else
                        with {{ $design->artist?->name ?? 'an artist' }}
                        @if ($design->revision_count > 0)
                            &middot; revision {{ $design->revision_count }} of {{ \App\Models\InquiryDesign::REVISION_LIMIT }}
                        @endif
                    @endif
                </span>
            </div>

            {{-- Each design carries its own instruction. A jacket and a jersey
                 on the same brief often need different logos, colours, or
                 placement notes, so one shared note was too easy to misread. --}}
            @if (! $inquiry->layout_sent_at)
                <form method="POST" action="{{ route('inquiries.designs.description', [$inquiry, $design]) }}" style="margin:.65rem 0;">
                    @csrf
                    <label for="design_description_{{ $design->id }}" style="display:block; font-size:.78rem; font-weight:700; margin-bottom:.25rem;">Notes / description for {{ $design->name() }}</label>
                    <textarea id="design_description_{{ $design->id }}" name="description" rows="3" maxlength="2000"
                              placeholder="What makes this design different — text, colours, logos, placement, sizes…" style="width:100%;">{{ old('description', $design->description) }}</textarea>
                    <button type="submit" class="btn btn-ghost btn-sm" style="margin-top:.35rem;">Save description</button>
                </form>
            @elseif (filled($design->description))
                <div class="layout-note-card" style="margin:.65rem 0; padding:.6rem .7rem;">
                    <strong style="font-size:.78rem;">Notes / description</strong>
                    @include('partials.note-lines', ['note' => $design->description])
                </div>
            @endif

            {{-- Whose desk it is on, and moving it. Per design: an artist who
                 goes home sick takes only their share of the set with them.

                 Who may: the officer while the brief is still a draft, because
                 they are arranging the set; a leader after it has gone out,
                 because moving work somebody has started is their call. --}}
            @if (! $design->approved() && $artists->isNotEmpty()
                 && (auth()->user()->isLeader() || ! $inquiry->layout_sent_at))
                <form method="POST" action="{{ route('inquiries.designs.artist', [$inquiry, $design]) }}"
                      style="display:flex; gap:.4rem; align-items:flex-end; flex-wrap:wrap; margin:.5rem 0;">
                    @csrf
                    <div class="field" style="margin:0;">
                        <select name="artist_id" required style="min-width:170px;">
                            @foreach ($artists as $candidate)
                                <option value="{{ $candidate->id }}" @selected($design->artist_id === $candidate->id)>
                                    {{ $candidate->name }}@if (! $candidate->isPresentToday()) - not in today @endif
                                </option>
                            @endforeach
                        </select>
                    </div>
                    <button type="submit" class="btn btn-ghost btn-sm">Give it to them</button>
                </form>
            @endif

            @if ($design->drawings()->isNotEmpty())
                <div class="layout-file-grid" style="grid-template-columns:repeat(auto-fit,minmax(120px,170px)); margin:.6rem 0;">
                    @foreach ($design->drawings() as $index => $file)
                        <a href="{{ route('inquiries.designs.file', [$design, 'index' => $index]) }}" target="_blank" class="layout-file-card">
                            <span class="layout-file-preview" style="min-height:110px;">
                                @if (str_starts_with($file['mime'] ?? '', 'image/'))
                                    <img src="{{ route('inquiries.designs.file', [$design, 'index' => $index]) }}" alt="{{ $file['original_name'] }}" style="height:130px;">
                                @else
                                    <span style="font-size:1.8rem;">&#128196;</span>
                                @endif
                            </span>
                            <span class="layout-file-name">{{ $file['original_name'] }}</span>
                        </a>
                    @endforeach
                </div>
            @endif

            {{-- Answered one at a time. The job order still opens on the whole
                 set: an order written against a design the client has not seen
                 is a number on the books for something nobody agreed to. --}}
            @if ($design->submitted())
                @php $spent = $design->revisionsUsedUp() && ! auth()->user()->isLeader(); @endphp
                <div style="display:flex; gap:.5rem; flex-wrap:wrap; align-items:flex-start; margin-top:.5rem;">
                    <form method="POST" action="{{ route('inquiries.designs.approve', [$inquiry, $design]) }}">
                        @csrf
                        <button type="submit" class="btn btn-primary btn-sm">&#10003; Client approved</button>
                    </form>

                    <form method="POST" action="{{ route('inquiries.designs.revise', [$inquiry, $design]) }}"
                          style="display:flex; gap:.4rem; align-items:flex-start; flex-wrap:wrap;">
                        @csrf
                        <input type="text" name="revision_note" maxlength="2000" required
                               placeholder="What the client wants changed" style="min-width:230px;">
                        <button type="submit" class="btn btn-ghost btn-sm" @disabled($spent)>
                            &#8617; Send back
                            @if ($design->revision_count > 0) ({{ $design->revisionsLeft() }} left) @endif
                        </button>
                    </form>
                </div>
                @if ($spent)
                    <div class="alert alert-error" style="margin-top:.5rem;">
                        {{ $design->name() }} has had its {{ \App\Models\InquiryDesign::REVISION_LIMIT }} revisions. A leader can send it back again.
                    </div>
                @endif
            @endif

            {{-- Taken off the list only while nothing has been drawn on it. --}}
            @if (! $design->approved() && $design->drawings()->isEmpty())
                <form method="POST" action="{{ route('inquiries.designs.delete', [$inquiry, $design]) }}" style="margin-top:.5rem;">
                    @csrf
                    <button type="submit" class="btn btn-ghost btn-sm">Remove this design</button>
                </form>
            @endif
        </div>
    @endforeach

    {{-- Adding them. Several at once, because a kit is listed in one go rather
         than six visits to this form. --}}
    <form method="POST" action="{{ route('inquiries.designs.store', $inquiry) }}"
          style="display:flex; gap:.45rem; align-items:flex-end; flex-wrap:wrap; margin:.8rem 0 1rem;">
        @csrf
        <div class="field" style="margin:0;">
            <label for="design_label" style="font-size:.78rem;">What to call it</label>
            <input id="design_label" type="text" name="label" maxlength="120" placeholder="Jersey" style="min-width:150px;">
        </div>
        <div class="field" style="margin:0;">
            <label for="design_how_many" style="font-size:.78rem;">How many</label>
            <input id="design_how_many" type="number" name="how_many" value="1" min="1" max="20" style="width:80px;">
        </div>
        @if ($artists->isNotEmpty())
            <div class="field" style="margin:0;">
                <label for="design_artist" style="font-size:.78rem;">Who draws them</label>
                <select id="design_artist" name="artist_id">
                    <option value="">Whoever is in today</option>
                    @foreach ($artists as $candidate)
                        <option value="{{ $candidate->id }}">
                            {{ $candidate->name }}@if (! $candidate->isPresentToday()) - not in today @endif
                        </option>
                    @endforeach
                </select>
            </div>
        @endif
        <div class="field" style="margin:0; flex-basis:100%;">
            <label for="design_description" style="font-size:.78rem;">Initial notes / description</label>
            <textarea id="design_description" name="description" rows="2" maxlength="2000" placeholder="Optional — you can give every new design its own description after adding it." style="width:100%;"></textarea>
        </div>
        <button type="submit" class="btn btn-ghost btn-sm">+ Add design</button>
    </form>

    @if ($inquiry->layout_sent_at)
        {{-- One approved design can open the paperwork; the unfinished ones
             remain on the Layout board until the client approves them. --}}
        @php $approvedCount = $designs->where('status', \App\Models\InquiryDesign::STATUS_APPROVED)->count(); @endphp
        @if ($approvedCount > 0)
            <div class="alert alert-success layout-next layout-order-ready" style="margin-bottom:0;">
                <div class="layout-order-copy">
                    <span class="layout-order-kicker">Ready for job order</span>
                    <strong class="layout-order-title">
                        {{ $approvedCount }} of {{ $designs->count() }} {{ \Illuminate\Support\Str::plural('design', $approvedCount) }} approved
                    </strong>
                    @if (! $inquiry->layoutApproved())
                        <span class="layout-order-lock">The remaining designs stay in Layout. Sample and pre-production can continue; mass production waits for all approvals.</span>
                    @endif
                </div>
                <a href="{{ route('orders.create', ['inquiry' => $inquiry->id]) }}" class="btn btn-primary btn-sm">
                    Create job order &rarr;
                </a>
            </div>
        @else
            <div class="alert alert-info layout-next" style="margin-bottom: 0;">
                {{ $inquiry->designsOutstanding()->count() }} of {{ $designs->count() }}
                {{ \Illuminate\Support\Str::plural('design', $designs->count()) }} still to be approved.
                The job order opens once the client has said yes to at least one design.
            </div>
        @endif
    @else
        <form method="POST" action="{{ route('inquiries.layout.complete', $inquiry) }}" class="layout-pre-send">
            @csrf
            <strong style="display:block; font-size:.86rem;">Ready to send?</strong>
            <span style="display:block; color:var(--ink-3); font-size:.76rem; margin:.18rem 0 .7rem;">Each design's notes are saved on its own card above, so the artist sees only the instructions for that design.</span>
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
