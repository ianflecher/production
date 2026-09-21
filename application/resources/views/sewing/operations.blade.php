@extends('layouts.app')

@section('title', 'Sewing sheet')
@section('page-title', 'Sewing sheet')

@section('content')
{{-- The layout already floats session('success') and the first error, so this
     page does not print them a second time. --}}
<div style="max-width:980px; margin:0 auto;">
    <p style="margin:0 0 1rem; font-size:0.87rem; color:var(--ink-2); line-height:1.6;">
        What each garment takes on the sewing line, as the shop worked it out.
        The same sheet is on every sewing station's page, where a line can be
        dropped straight into the record of who sewed what.
    </p>

    @include('partials.sewing-operations', [
        'sheet' => $sheet,
        'garment' => $garment,
        'canEdit' => $canEdit,
        'withAdd' => false,
    ])
</div>
@endsection
