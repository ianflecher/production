@extends('layouts.app')

@section('title', 'Inventory — Imprint Production')
@section('page-title', 'Raw Materials Inventory')

@section('content')

@push('styles')
    <link rel="stylesheet" href="{{ asset('css/inventory-index.css') }}?v={{ filemtime(public_path('css/inventory-index.css')) }}">
@endpush

@php
    $totalItems = $items->count();
    $outCount = $items->filter(fn ($item) => (float) $item->quantity <= 0)->count();
    $inStockCount = $totalItems - $outCount;
@endphp


<div class="inventory-page">
    <section class="inv-hero">
        <div class="inv-title-wrap">
            <div class="inv-title-icon" aria-hidden="true">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/>
                    <polyline points="3.29 7 12 12 20.71 7"/>
                    <line x1="12" y1="22" x2="12" y2="12"/>
                </svg>
            </div>
            <div>
                <h1>Raw materials inventory</h1>
                <p class="muted">Manage stock levels, material requests, and CSV imports in one place.</p>
            </div>
        </div>

        <div class="inv-actions">
            <a href="{{ route('inventory.requests') }}" class="btn btn-sm inv-action {{ $pendingCount > 0 ? 'inv-action-primary' : 'btn-ghost' }}">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                Material requests
                @if ($pendingCount > 0)
                    <span class="count-pill">{{ $pendingCount }}</span>
                @endif
            </a>

            <a href="{{ route('inventory.history') }}" class="btn btn-sm inv-action inv-action-history">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><polyline points="12 7 12 12 15 14"/></svg>
                Stock history
            </a>

            <a href="{{ route('inventory.export') }}" class="btn btn-sm inv-action inv-action-export">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                Export CSV
            </a>
        </div>
    </section>

    <section class="inv-kpis" aria-label="Inventory summary">
        <article class="inv-kpi inv-kpi-blue">
            <div class="inv-kpi-label">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/></svg>
                Total materials
            </div>
            <div class="inv-kpi-value">{{ number_format($totalItems) }}</div>
            <div class="inv-kpi-note">All listed raw-material items</div>
        </article>

        <article class="inv-kpi inv-kpi-green">
            <div class="inv-kpi-label">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 6 9 17l-5-5"/></svg>
                In stock
            </div>
            <div class="inv-kpi-value">{{ number_format($inStockCount) }}</div>
            <div class="inv-kpi-note">Materials currently available</div>
        </article>

        <article class="inv-kpi inv-kpi-red">
            <div class="inv-kpi-label">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="9"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                Out of stock
            </div>
            <div class="inv-kpi-value">{{ number_format($outCount) }}</div>
            <div class="inv-kpi-note">Materials needing replenishment</div>
        </article>

        <article class="inv-kpi inv-kpi-orange">
            <div class="inv-kpi-label">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 4h16v14H6l-2 2V4z"/><path d="M8 9h8M8 13h5"/></svg>
                Pending requests
            </div>
            <div class="inv-kpi-value">{{ number_format($pendingCount) }}</div>
            <div class="inv-kpi-note">Material requests awaiting action</div>
        </article>
    </section>

    <section class="inv-tools-grid">
        <article class="inv-tool-card add-card">
            <div class="inv-tool-head">
                <div class="inv-tool-icon" aria-hidden="true">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 5v14M5 12h14"/></svg>
                </div>
                <div>
                    <h2>Add material</h2>
                    <p class="sub">Create a new raw-material item and set its opening stock.</p>
                </div>
            </div>

            <form method="POST" action="{{ route('inventory.store') }}" class="inv-form-grid" enctype="multipart/form-data">
                @csrf
                <div class="field material-field">
                    <label for="material_name">Material name</label>
                    <input id="material_name" type="text" name="name" required maxlength="255" value="{{ old('name') }}" placeholder="e.g. Aircool fabric">
                </div>

                <div class="field">
                    <label for="material_category">Category</label>
                    <select id="material_category" name="category" required>
                        <option value="">— Choose —</option>
                        @foreach (\App\Models\InventoryItem::CATEGORIES as $key => $label)
                            <option value="{{ $key }}" @selected(old('category') === $key)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>

                <div class="field">
                    <label for="material_code">Code</label>
                    <input id="material_code" type="text" name="code" maxlength="60" value="{{ old('code') }}" placeholder="e.g. FAB-001">
                </div>

                <div class="field">
                    <label for="material_unit">Unit</label>
                    <input id="material_unit" type="text" name="unit" required maxlength="30" value="{{ old('unit', 'pcs') }}" placeholder="pcs / rolls">
                </div>

                <div class="field">
                    <label for="material_size">Size</label>
                    <input id="material_size" type="text" name="size" maxlength="60" value="{{ old('size') }}" placeholder="e.g. XL / 60in">
                </div>

                <div class="field">
                    <label for="material_color">Color</label>
                    <input id="material_color" type="text" name="color" maxlength="60" value="{{ old('color') }}" placeholder="e.g. Navy blue">
                </div>

                <div class="field">
                    <label for="material_quantity">Beginning stock</label>
                    <input id="material_quantity" type="number" name="quantity" required step="0.01" min="0" value="{{ old('quantity', 0) }}">
                </div>

                <div class="field material-field">
                    <label for="material_photo">Photo</label>
                    <input id="material_photo" type="file" name="photo" accept="image/*" class="no-caps">
                </div>

                <button class="inv-submit inv-submit-add" type="submit">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 5v14M5 12h14"/></svg>
                    Add to inventory
                </button>
            </form>
        </article>

        <article class="inv-tool-card import-card">
            <div class="inv-tool-head">
                <div class="inv-tool-icon" aria-hidden="true">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 3v12"/><polyline points="8 7 12 3 16 7"/><path d="M4 15v4a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-4"/></svg>
                </div>
                <div>
                    <h2>Import from Excel</h2>
                    <p class="sub">Upload a CSV containing <strong>name, unit, quantity</strong>. Existing names are updated automatically.</p>
                </div>
            </div>

            <form method="POST" action="{{ route('inventory.import') }}" enctype="multipart/form-data" class="inv-import-form">
                @csrf
                <input type="file" name="file" accept=".csv,.txt" required>
                <button class="inv-submit inv-submit-import" type="submit">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 3v12"/><polyline points="8 7 12 3 16 7"/><path d="M4 15v4a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-4"/></svg>
                    Import CSV
                </button>
            </form>
        </article>
    </section>

    <section class="inv-stock-card">
        <div class="inv-stock-head">
            <div class="inv-stock-title">
                <div class="inv-stock-title-icon" aria-hidden="true">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 7l9-4 9 4-9 4-9-4z"/><path d="M3 12l9 4 9-4"/><path d="M3 17l9 4 9-4"/></svg>
                </div>
                <div>
                    <h2>Stock on hand</h2>
                    <p>{{ number_format($totalItems) }} {{ Str::plural('material', $totalItems) }} in inventory</p>
                </div>
            </div>

            @if ($items->isNotEmpty())
                <div class="inv-stock-controls">
                    @if ($outCount > 0)
                        <span class="inv-out-badge">
                            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="9"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                            {{ $outCount }} out of stock
                        </span>
                    @endif

                    @php
                        $colorOptions = $items->pluck('color')->map(fn ($c) => trim((string) $c))->filter()->unique()->sort()->values();
                        $sizeOptions = $items->pluck('size')->map(fn ($s) => trim((string) $s))->filter()->unique()->sort()->values();
                    @endphp
                    <select id="invCategory" class="inv-cat-filter" aria-label="Filter by category">
                        <option value="">All categories</option>
                        @foreach (\App\Models\InventoryItem::CATEGORIES as $key => $label)
                            <option value="{{ $key }}">{{ $label }}</option>
                        @endforeach
                    </select>

                    <select id="invColor" class="inv-cat-filter" aria-label="Filter by color">
                        <option value="">All colors</option>
                        @foreach ($colorOptions as $c)
                            <option value="{{ strtolower($c) }}">{{ $c }}</option>
                        @endforeach
                    </select>

                    <select id="invSize" class="inv-cat-filter" aria-label="Filter by size">
                        <option value="">All sizes</option>
                        @foreach ($sizeOptions as $s)
                            <option value="{{ strtolower($s) }}">{{ $s }}</option>
                        @endforeach
                    </select>

                    <div class="inv-search">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                        <input type="search" id="invSearch" placeholder="Search name, code, size, color…" autocomplete="off" aria-label="Search materials">
                    </div>

                    <span id="invCount" class="inv-count"></span>
                </div>
            @endif
        </div>

        @if ($items->isEmpty())
            <div class="inv-empty">
                <div class="inv-empty-icon" aria-hidden="true">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/><polyline points="3.29 7 12 12 20.71 7"/></svg>
                </div>
                <h3>No materials yet</h3>
                <p>Add your first material above or import an existing CSV inventory file.</p>
            </div>
        @else
            <div class="inv-table-wrap">
                <table class="inv-table">
                    <thead>
                        <tr>
                            {{-- Same running figures as the stock sheet:
                                 BEG BAL → RECEIVED → TOTAL → LESS → REMAINING --}}
                            <th>Material</th>
                            <th>Beginning</th>
                            <th>Received</th>
                            <th>Total</th>
                            <th>Less</th>
                            <th>Remaining</th>
                            <th aria-label="Actions"></th>
                        </tr>
                    </thead>
                    <tbody id="invBody">
                        @foreach ($items as $item)
                            @php
                                $isOut = (float) $item->quantity <= 0;
                                $initials = collect(preg_split('/\s+/', trim($item->name)))
                                    ->filter()
                                    ->take(2)
                                    ->map(fn ($word) => mb_strtoupper(mb_substr($word, 0, 1)))
                                    ->implode('');
                            @endphp

                            <tr data-search="{{ strtolower($item->name.' '.$item->code.' '.$item->categoryLabel().' '.$item->size.' '.$item->color.' '.$item->unit) }}" data-category="{{ $item->category }}" data-color="{{ strtolower(trim((string) $item->color)) }}" data-size="{{ strtolower(trim((string) $item->size)) }}" class="{{ $isOut ? 'is-out' : '' }}">
                                <td>
                                    <div class="material-cell">
                                        @if ($item->photo)
                                            <img class="material-icon js-photo-view" src="{{ asset('storage/'.$item->photo) }}" alt="{{ $item->name }}" style="object-fit: cover; cursor: zoom-in;"
                                                 data-full="{{ asset('storage/'.$item->photo) }}" data-name="{{ $item->name }}">
                                        @else
                                            <div class="material-icon">{{ $initials ?: 'RM' }}</div>
                                        @endif
                                        <div>
                                            <div class="material-name">{{ $item->name }}</div>
                                            <div class="material-unit" style="display:flex; flex-wrap:wrap; gap:0.3rem; align-items:center; margin-top:0.15rem;">
                                                <span class="badge" style="background:var(--accent-soft); color:#1d4ed8;">{{ $item->categoryLabel() }}</span>
                                                @if ($item->code)<span style="color:var(--ink-3); font-family:ui-monospace,Consolas,monospace; font-size:0.72rem;">{{ $item->code }}</span>@endif
                                                @if ($item->size)<span style="color:var(--ink-3); font-size:0.72rem;">📐 {{ $item->size }}</span>@endif
                                                @if ($item->color)<span style="color:var(--ink-3); font-size:0.72rem;">🎨 {{ $item->color }}</span>@endif
                                            </div>
                                        </div>
                                    </div>
                                </td>

                                <td>
                                    <span style="font-weight:700; color:var(--ink-2);">{{ $item->beginningForHumans() }}</span>
                                    <small style="color:var(--ink-3);">{{ $item->unit }}</small>
                                </td>

                                <td>
                                    <span style="font-weight:600; color:var(--success-ink);">+{{ number_format($item->receivedTotal(), 0) }}</span>
                                </td>

                                <td>
                                    <span style="font-weight:600; color:var(--ink-2);">{{ number_format($item->runningTotal(), 0) }}</span>
                                </td>

                                <td>
                                    <span style="font-weight:600; color:{{ $item->lessTotal() > 0 ? 'var(--danger-ink)' : 'var(--ink-3)' }};">
                                        @if ($item->lessTotal() > 0)−@endif{{ number_format($item->lessTotal(), 0) }}
                                    </span>
                                </td>

                                <td>
                                    @if ($isOut)
                                        <span class="stock-out">
                                            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="9"/><line x1="8" y1="12" x2="16" y2="12"/></svg>
                                            Out of stock
                                        </span>
                                    @else
                                        <span class="stock-value">
                                            {{ $item->qtyForHumans() }}
                                            <small>{{ $item->unit }}</small>
                                        </span>
                                    @endif
                                </td>

                                <td style="text-align: right;">
                                    <div style="display: inline-flex; gap: 0.35rem; align-items: center;">
                                        <button type="button" class="btn btn-ghost btn-sm js-restock-open"
                                                data-action="{{ route('inventory.update', $item) }}"
                                                data-name="{{ $item->name }}"
                                                data-unit="{{ $item->unit }}"
                                                data-current="{{ (float) $item->quantity }}"
                                                data-stock="{{ $item->qtyForHumans() }}">✎ Update</button>
                                        <form method="POST" action="{{ route('inventory.destroy', $item) }}" onsubmit="return confirm('Remove {{ addslashes($item->name) }} from inventory?');">
                                            @csrf
                                            <button class="stock-delete" type="submit" title="Remove material" aria-label="Remove {{ $item->name }}">
                                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14H6L5 6"/><path d="M10 11v6M14 11v6"/><path d="M9 6V4h6v2"/></svg>
                                            </button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div id="invEmpty" hidden class="inv-empty">
                <div class="inv-empty-icon" aria-hidden="true">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                </div>
                <h3>No matching materials</h3>
                <p>Try another material name or unit.</p>
            </div>
        @endif
    </section>
</div>

{{-- Restock modal (proper name field) + photo lightbox. --}}
<div id="restockModal" class="rm-overlay" hidden>
    <div class="rm-box" role="dialog" aria-modal="true" aria-labelledby="rmTitle">
        <h3 id="rmTitle">Restock</h3>
        <form method="POST" id="rmForm" enctype="multipart/form-data">
            @csrf
            <input type="hidden" name="unit" id="rmUnit">
            <input type="hidden" name="quantity" id="rmQty">
            <div class="field">
                <label>Add to stock <span id="rmStock" style="color: var(--ink-3); font-weight: 400;"></span></label>
                {{-- Not required: leaving it blank means "just add the photo". --}}
                <input type="number" id="rmAdd" step="0.01" min="0" autocomplete="off" placeholder="e.g. 20 — or leave blank">
            </div>

            {{-- The stock sheet's mock-up pictures can't come through a CSV, so
                 a photo is added here instead, whenever someone next handles it. --}}
            <div class="field">
                <label>Photo <span style="color: var(--ink-3); font-weight: 400;">(optional — replaces the current one)</span></label>
                <input type="file" name="photo" id="rmPhoto" accept=".jpg,.jpeg,.png,.webp">
                <img id="rmPhotoPreview" alt="" hidden
                     style="margin-top:0.5rem; width:72px; height:72px; object-fit:cover; border-radius:8px; border:1px solid var(--border);">
            </div>
            <div class="field">
                <label>Note (optional)</label>
                <input type="text" name="note" maxlength="255" placeholder="e.g. New delivery" class="no-caps">
            </div>
            <div class="field">
                <label>Your name <span style="color: var(--danger-ink);">*</span></label>
                <input type="text" id="rmName" name="operator_name" maxlength="100" required placeholder="Your name">
            </div>
            <div class="rm-actions">
                <button type="button" class="btn btn-ghost btn-sm" id="rmCancel">Cancel</button>
                <button class="btn btn-primary btn-sm">Save</button>
            </div>
        </form>
    </div>
</div>

<div id="photoLightbox" class="rm-overlay" hidden>
    <figure style="max-width: 92vw; max-height: 88vh; margin: 0; text-align: center;">
        <img id="lbImg" src="" alt="" style="max-width: 92vw; max-height: 80vh; border-radius: 14px; box-shadow: 0 24px 60px rgba(0,0,0,.5); background: #fff;">
        <figcaption id="lbCap" style="color: #fff; margin-top: 0.6rem; font-weight: 600;"></figcaption>
    </figure>
</div>


@if ($items->isNotEmpty())
<script>
    /* Restock modal (name field works) + photo lightbox. */
    (function () {
        var modal = document.getElementById('restockModal');
        if (modal) {
            var form = document.getElementById('rmForm');
            var title = document.getElementById('rmTitle');
            var stock = document.getElementById('rmStock');
            var addEl = document.getElementById('rmAdd');
            var qtyHidden = document.getElementById('rmQty');
            var unitHidden = document.getElementById('rmUnit');
            var nameEl = document.getElementById('rmName');
            var photoEl = document.getElementById('rmPhoto');
            var photoPreview = document.getElementById('rmPhotoPreview');
            var current = 0;

            function openRestock(btn) {
                current = Number(btn.getAttribute('data-current') || 0);
                form.action = btn.getAttribute('data-action');
                title.textContent = 'Update ' + btn.getAttribute('data-name');
                stock.textContent = '(now ' + btn.getAttribute('data-stock') + ' ' + btn.getAttribute('data-unit') + ')';
                unitHidden.value = btn.getAttribute('data-unit');
                addEl.value = ''; nameEl.value = ''; form.querySelector('[name="note"]').value = '';
                // Clear any photo picked the last time the dialog was open.
                if (photoEl) photoEl.value = '';
                if (photoPreview) { photoPreview.hidden = true; photoPreview.removeAttribute('src'); }
                modal.hidden = false;
                setTimeout(function () { addEl.focus(); }, 30);
            }

            // Show the chosen picture so nobody uploads the wrong one.
            if (photoEl) {
                photoEl.addEventListener('change', function () {
                    var file = photoEl.files && photoEl.files[0];
                    if (!file) { photoPreview.hidden = true; return; }
                    photoPreview.src = URL.createObjectURL(file);
                    photoPreview.hidden = false;
                    photoPreview.onload = function () { URL.revokeObjectURL(photoPreview.src); };
                });
            }
            function closeRestock() { modal.hidden = true; }

            // Controller sets the TOTAL — add the entered amount to current stock.
            form.addEventListener('submit', function () { qtyHidden.value = current + Number(addEl.value || 0); });
            document.querySelectorAll('.js-restock-open').forEach(function (b) { b.addEventListener('click', function () { openRestock(b); }); });
            document.getElementById('rmCancel').addEventListener('click', closeRestock);
            modal.addEventListener('click', function (e) { if (e.target === modal) closeRestock(); });
            document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && !modal.hidden) closeRestock(); });
        }

        // Photo lightbox
        var lb = document.getElementById('photoLightbox');
        var lbImg = document.getElementById('lbImg');
        var lbCap = document.getElementById('lbCap');
        document.querySelectorAll('.js-photo-view').forEach(function (img) {
            img.addEventListener('click', function () {
                lbImg.src = img.getAttribute('data-full');
                lbImg.alt = img.getAttribute('data-name') || '';
                lbCap.textContent = img.getAttribute('data-name') || '';
                lb.hidden = false;
            });
        });
        if (lb) {
            lb.addEventListener('click', function () { lb.hidden = true; });
            document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && !lb.hidden) lb.hidden = true; });
        }
    })();

    (function () {
        var search = document.getElementById('invSearch');
        var category = document.getElementById('invCategory');
        var color = document.getElementById('invColor');
        var size = document.getElementById('invSize');
        var body = document.getElementById('invBody');
        var emptyMsg = document.getElementById('invEmpty');
        var countEl = document.getElementById('invCount');

        if (!search || !body) return;

        var rows = Array.prototype.slice.call(body.querySelectorAll('tr[data-search]'));
        var total = rows.length;

        function apply() {
            var query = search.value.trim().toLowerCase();
            var cat = category ? category.value : '';
            var col = color ? color.value : '';
            var sz = size ? size.value : '';
            var shown = 0;

            rows.forEach(function (row) {
                var matchesText = !query || row.getAttribute('data-search').indexOf(query) !== -1;
                var matchesCat = !cat || row.getAttribute('data-category') === cat;
                var matchesColor = !col || row.getAttribute('data-color') === col;
                var matchesSize = !sz || row.getAttribute('data-size') === sz;
                var visible = matchesText && matchesCat && matchesColor && matchesSize;
                row.hidden = !visible;
                if (visible) shown++;
            });

            if (emptyMsg) emptyMsg.hidden = shown !== 0;
            if (countEl) {
                countEl.textContent = (query || cat || col || sz)
                    ? 'Showing ' + shown + ' of ' + total
                    : total + (total === 1 ? ' item' : ' items');
            }
        }

        search.addEventListener('input', apply);
        [category, color, size].forEach(function (el) { if (el) el.addEventListener('change', apply); });
        apply();
    })();
</script>
@endif
@endsection