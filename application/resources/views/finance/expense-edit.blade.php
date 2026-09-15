@extends('layouts.app')

@section('title', 'Edit expense — '.$expense->description)
@section('page-title', 'Edit expense')

@section('content')
<div class="page-head">
    <div class="grow">
        <h1>Edit expense</h1>
        <p class="muted">
            Recorded by {{ $expense->recorder?->name ?? 'somebody' }}@if ($expense->created_at)
                on {{ $expense->created_at->format('M j, Y') }}@endif.
            That does not change when this is corrected.
        </p>
    </div>
    <a href="{{ route('books.index', ['month' => $expense->spent_at?->format('Y-m')]) }}" class="btn btn-ghost">
        ← Back to the books
    </a>
</div>

<div class="card panel">
    @include('finance.partials.expense-form', ['expense' => $expense])
</div>
@endsection
