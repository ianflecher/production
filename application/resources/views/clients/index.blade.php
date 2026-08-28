@extends('layouts.app')

@section('title', 'Clients — Imprint Production')
@section('page-title', 'Clients')

@section('content')

<div class="page-head">
    <div>
        <p class="sub">
            Everybody the shop has written down, by surname. A name gets here by being
            saved on an inquiry — this page reads the book, it does not add to it.
        </p>
    </div>

    <a href="{{ route('inquiries.index') }}" class="btn">Follow-ups</a>
</div>

@if ($clients->isEmpty())
    <div class="card panel">
        <p class="sub" style="margin: 0;">
            No clients yet. The first one is written down on a new inquiry.
        </p>
    </div>
@else
    <div class="card panel">
        <table class="tbl">
            <thead>
                <tr>
                    <th>Name</th>
                    <th>Company</th>
                    <th>Contact</th>
                    <th>Delivery address</th>
                    <th>Inquiries</th>
                    <th>Orders</th>
                    <th>Written down by</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($clients as $client)
                    <tr>
                        <td><strong>{{ $client->listName() }}</strong></td>
                        <td>{{ $client->company ?: '—' }}</td>
                        <td>{{ $client->contact_number ?: '—' }}</td>
                        <td>{{ $client->delivery_address ?: $client->office_address ?: '—' }}</td>
                        <td>{{ $client->inquiries_count }}</td>
                        <td>{{ $client->orders_count }}</td>
                        <td>
                            {{ $client->creator?->name ?? '—' }}
                            <br>
                            <small class="sub">{{ $client->created_at?->format('d M Y') }}</small>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
@endif

@endsection
