@extends('layouts.app')

@section('title', 'New Inquiry — Imprint Production')
@section('page-title', 'New Inquiry')

@section('content')

@include('partials.intake-steps', ['on' => 1])

<p class="sub" style="margin-bottom: 1.2rem;">
    Who is asking. This is saved on its own, so if they do not order
    today they stay on your follow-up list instead of being forgotten.
</p>

<form method="POST" action="{{ route('inquiries.store') }}" class="form-steps">
    @csrf

    <div class="card panel" style="margin-bottom: 1.4rem;">
        <h2>Client</h2>
        <p class="sub">Pick an existing client, or add a new one.</p>

        <div class="field" style="max-width: 420px;">
            <label for="client_id">Existing client</label>
            <select id="client_id" name="client_id" onchange="toggleClientMode()">
                <option value="">— New client (fill in below) —</option>
                @foreach ($clients as $client)
                    <option value="{{ $client->id }}" @selected(old('client_id') == $client->id)>{{ $client->listName() }}@if ($client->company) — {{ $client->company }}@endif</option>
                @endforeach
            </select>
        </div>

        <div id="newClient" style="{{ old('client_id') ? 'display:none;' : '' }}">
            <div class="form-grid">
                <div class="field">
                    <label for="client_name">First name <span style="color: var(--danger-ink);">*</span></label>
                    <input id="client_name" type="text" name="client_name" value="{{ old('client_name') }}" maxlength="255" placeholder="e.g. Juan" style="text-transform: capitalize;">
                    @error('client_name')<span class="error">{{ $message }}</span>@enderror
                </div>
                <div class="field">
                    <label for="client_last_name">Last name <span style="color: var(--danger-ink);">*</span></label>
                    <input id="client_last_name" type="text" name="client_last_name" value="{{ old('client_last_name') }}" maxlength="255" placeholder="e.g. Dela Cruz" style="text-transform: capitalize;">
                    @error('client_last_name')<span class="error">{{ $message }}</span>@enderror
                </div>
                <div class="field">
                    <label for="client_contact">Contact number <span style="color: var(--danger-ink);">*</span></label>
                    <input id="client_contact" type="text" name="client_contact" value="{{ old('client_contact') }}" maxlength="255" placeholder="e.g. 0917-555-1234">
                    @error('client_contact')<span class="error">{{ $message }}</span>@enderror
                </div>
                <div class="field">
                    <label for="client_company">Company (optional)</label>
                    <input id="client_company" type="text" name="client_company" value="{{ old('client_company') }}" maxlength="255" placeholder="e.g. Falcon Riders" style="text-transform: capitalize;">
                </div>
                <div class="field">
                    <label for="client_office_address">Office address <span style="color: var(--danger-ink);">*</span></label>
                    <input id="client_office_address" type="text" name="client_office_address" value="{{ old('client_office_address') }}" maxlength="255" placeholder="e.g. 12 Rizal St., Angeles City" style="text-transform: capitalize;">
                    @error('client_office_address')<span class="error">{{ $message }}</span>@enderror
                </div>
                <div class="field">
                    <label for="client_delivery_address">Delivery address <span style="color: var(--danger-ink);">*</span></label>
                    <input id="client_delivery_address" type="text" name="client_delivery_address" value="{{ old('client_delivery_address') }}" maxlength="255" placeholder="Where the order is delivered" style="text-transform: capitalize;">
                    @error('client_delivery_address')<span class="error">{{ $message }}</span>@enderror
                </div>
                <div class="field">
                    <label for="client_tin">TIN (optional — for invoice)</label>
                    <input id="client_tin" type="text" name="client_tin" value="{{ old('client_tin') }}" maxlength="50" placeholder="e.g. 123-456-789-000">
                </div>
            </div>
        </div>
    </div>

    <div class="card panel" style="margin-bottom: 1.4rem;">
        <h2>The inquiry</h2>
        <p class="sub">What they asked about. Optional — the details above are what matters. The date to chase them is set when you log a follow-up.</p>

        <div class="field">
            <label for="what_they_want">What they are asking about</label>
            <textarea id="what_they_want" name="what_they_want" rows="3" maxlength="2000"
                      placeholder="e.g. 30 riding jerseys for a club, asking for a price and a sample">{{ old('what_they_want') }}</textarea>
        </div>
    </div>

    <div style="display: flex; gap: 0.6rem; align-items: center;">
        <button type="submit" class="btn btn-primary">Save &amp; continue to the order →</button>
        <a href="{{ route('dashboard') }}" class="btn btn-ghost">Cancel</a>
    </div>
</form>

<script>
    /* A new client needs a complete contact record; an existing one already
       has theirs, so choosing one hides and disables the block. Disabled
       fields are not posted, which is what keeps the required-without rules
       from firing on a client who was picked rather than typed. */
    function toggleClientMode() {
        const isNew = !document.getElementById('client_id').value;
        const box = document.getElementById('newClient');
        box.style.display = isNew ? '' : 'none';
        box.querySelectorAll('input').forEach(i => i.disabled = !isNew);
    }

    toggleClientMode();
</script>

@endsection
