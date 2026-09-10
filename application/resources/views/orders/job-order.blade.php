@extends('layouts.app')

@section('title', 'Tech Pack '.$order->order_number)
@section('page-title', 'Tech Pack')

@push('styles')
    <link rel="stylesheet" href="{{ asset('css/tech-pack.css') }}?v={{ filemtime(public_path('css/tech-pack.css')) }}">
@endpush

@section('content')
@php
    $jo = $order->jobOrder;
@endphp


<div class="no-print">
    @include('partials.delay-alert', ['order' => $order, 'size' => 'big'])
</div>

@if ($errors->any())
    <div class="alert-error no-print" style="max-width:1180px; margin:0 auto 1rem;">
        @foreach ($errors->all() as $error){{ $error }}<br>@endforeach
    </div>
@endif

<div class="tp-actions no-print">
    @if (auth()->user()->canCreateOrders() && $jo)
        @include('partials.tech-pack-send', ['order' => $order, 'jo' => $jo])

        {{-- The typed boxes are the account officer's, and they are typed on
             their OWN copy of the sheet. This page is the read-only one that
             everybody reads, so without this button the officer arrived at
             their own tech pack, found every row locked, and had no way from
             here to the page where they could fill it in. --}}
        <a href="{{ route('job-orders.edit', $order) }}" class="btn btn-primary btn-sm">✎ Fill in the tech pack</a>

        {{-- Production details — press, cutting and the raw materials — stay
             reachable before and after sending. --}}
        <a href="{{ route('job-orders.production', $order) }}" class="btn btn-primary btn-sm">⚙ Production details</a>
    @endif
    <button type="button" onclick="window.printTechPack ? window.printTechPack() : window.print()" class="btn btn-ghost btn-sm">🖨 Print</button>
    {{-- This sheet is shown to office staff (orders.job-order) AND to artists
         (tasks.job-order), so send each back somewhere they can actually open. --}}
    @php
        $u = auth()->user();

        // Send each person back somewhere they can actually use. The mover has
        // no task list — she works from the conversations, so back means the
        // thread for this job, not an empty My Tasks she has no link to.
        [$backUrl, $backLabel] = match (true) {
            $u->isSales() || $u->isLeader() => [route('orders.show', $order), 'order'],
            $u->isMover() => [route('messages.show', $order), 'messages'],
            default => [route('tasks.mine'), 'my tasks'],
        };
    @endphp
    <a href="{{ $backUrl }}" class="btn btn-ghost btn-sm">← Back to {{ $backLabel }}</a>
</div>

{{-- The assigned artist completes the entire pack; everybody else reviews it. --}}
@isset($techPackTask)
    <form method="POST" action="{{ route('tasks.tech-pack', $techPackTask->id) }}" enctype="multipart/form-data">
        @csrf
        @php $currentPack = $order->techPackOrNew($phase ?? \App\Models\TechPack::PHASE_SAMPLE); @endphp
        <div class="card no-print" style="max-width:1180px; margin:0 auto 1rem;">
            <strong>Import complete Tech Pack image</strong>
            <p class="hint" style="margin:0.35rem 0 0.7rem;">Upload one JPEG, PNG, or WebP when the supplier already made the whole Tech Pack as an image. It will be the sheet shown for review and print.</p>
            <div style="display:flex; gap:0.7rem; align-items:center; flex-wrap:wrap;">
                <input type="file" name="imported_tech_pack" accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp"
                       onchange="if (this.files.length) this.form.requestSubmit()">
                @if ($currentPack->imported_pack_path)
                    <button type="submit" name="remove_imported_tech_pack" value="1" class="btn btn-ghost btn-sm"
                            onclick="return confirm('Remove the imported Tech Pack image?')">Remove imported image</button>
                @endif
            </div>
        </div>
        @include('partials.tech-pack', ['order' => $order, 'editable' => true, 'phase' => $phase ?? \App\Models\TechPack::PHASE_SAMPLE])

        <div class="tp-save no-print">
            <button class="btn btn-primary" name="finish_editing" value="1">Save Tech Pack and continue</button>
            <span class="hint">
                Saves your changes, then opens the button that sends it to your account officer.
            </span>
        </div>
    </form>
@else
    @include('partials.tech-pack', ['order' => $order, 'phase' => $phase ?? \App\Models\TechPack::PHASE_SAMPLE])
@endisset

@endsection
