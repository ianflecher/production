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
        @include('partials.tech-pack', ['order' => $order, 'editable' => true])

        <div class="tp-save no-print">
            <button class="btn btn-primary" name="finish_editing" value="1">Save Tech Pack and continue</button>
            <span class="hint">
                Saves your changes, then opens the button that sends it to your account officer.
            </span>
        </div>
    </form>
@else
    @include('partials.tech-pack', ['order' => $order])
@endisset

@endsection
