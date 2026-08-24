@extends('layouts.app')

{{-- The account officer's half of the tech pack.

     This used to be its own yellow-boxed job order form, filled in beside the
     pack and then copied onto it. Same answers, two documents. Now the officer
     fills the pack itself, right after the downpayment: the client's spec goes
     straight onto the sheet the floor will pin up.

     They get the spec boxes only. The pictures, the printed sizes and the file
     paths belong to the artist and do not exist yet — see $officerFields in
     partials/tech-pack.blade.php. --}}

@section('title', 'Tech pack — '.$order->order_number)
@section('page-title', 'Tech pack — '.$order->order_number)

@section('content')
<link rel="stylesheet" href="{{ asset('css/tech-pack.css') }}?v={{ filemtime(public_path('css/tech-pack.css')) }}">

<div class="tp-actions no-print">
    <a href="{{ route('orders.show', $order) }}" class="btn btn-ghost btn-sm">← Back to the order</a>
</div>

@if ($errors->any())
    <div class="alert-error" style="max-width:1180px; margin:0 auto 1rem;">
        @foreach ($errors->all() as $e){{ $e }}<br>@endforeach
    </div>
@endif

@if ($jobOrder->leader_note)
    <div class="alert-error" style="max-width:1180px; margin:0 auto 1rem;">
        <strong>The leader sent this back:</strong> {{ $jobOrder->leader_note }}
    </div>
@endif

<form method="POST" action="{{ route('job-orders.update', $order) }}">
    @csrf

    @include('partials.tech-pack', ['order' => $order, 'mode' => 'officer'])

    <div class="tp-save no-print">
        <button type="submit" class="btn btn-primary">Save &amp; next: production details →</button>
        <span class="hint">The artist fills the pictures and the print sizes once the client approves the mockup.</span>
    </div>
</form>
@endsection
