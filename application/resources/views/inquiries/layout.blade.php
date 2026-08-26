@extends('layouts.app')

@section('title', 'Artist Layout Brief — Imprint Production')
@section('page-title', 'Artist Layout Brief')

@section('content')
<p class="sub" style="margin-bottom: 1.2rem;">Step 2 of 3 — {{ $inquiry->client->fullName() }}. Add the design and instructions the artist will work from, then continue to the New Job Order.</p>

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
        <input type="file" name="reference_files[]" multiple accept=".jpg,.jpeg,.png,.webp,.gif,.pdf,.ai,.psd,.eps,.cdr,.zip" onchange="if(this.files.length){ this.form.submit(); }">
        <button type="submit" class="btn btn-ghost btn-sm">⬆ Upload design</button>
        @if (count($files))
            <span style="color:var(--success-ink); font-size:.82rem;">✓ design uploaded — the artist will see this</span>
        @else
            <span style="color:var(--danger-ink); font-size:.82rem; font-weight:600;">⚠ no design uploaded yet</span>
        @endif
    </form>

    <form method="POST" action="{{ route('inquiries.layout.complete', $inquiry) }}">
        @csrf
        <label for="reference_note" style="font-weight:600; font-size:.9rem;">Notes for the artist <span style="font-weight:400; color:var(--ink-3);">(anything the design doesn't show — text/colors/size, must-keep details)</span></label>
        <textarea id="reference_note" name="reference_note" rows="4" maxlength="2000" placeholder="e.g. keep the team colors, make the logo bigger on the back" style="width:100%; margin:.4rem 0 .8rem;">{{ old('reference_note', $inquiry->layout_reference_note) }}</textarea>
        @error('layout')<div class="error" style="margin-bottom:.7rem;">{{ $message }}</div>@enderror
        <button type="submit" class="btn btn-primary btn-sm">📤 Send to artist for layout</button>
        <span style="color:var(--ink-3); font-size:.8rem; margin-left:.4rem;">Next: New Job Order.</span>
    </form>
</div>
@endsection
