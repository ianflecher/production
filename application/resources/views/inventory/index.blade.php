@extends('layouts.app')

@section('title', 'Inventory — Imprint Production')
@section('page-title', 'Raw Materials Inventory')

@section('content')
@php
    $totalItems = $items->count();
    $outCount = $items->filter(fn ($item) => (float) $item->quantity <= 0)->count();
    $inStockCount = $totalItems - $outCount;
@endphp

<style>
    .inventory-page {
        --inv-blue: #2563eb;
        --inv-violet: #7c3aed;
        --inv-cyan: #0891b2;
        --inv-green: #16a34a;
        --inv-orange: #ea580c;
        --inv-red: #dc2626;
        --inv-soft-blue: #eff6ff;
        --inv-soft-violet: #f5f3ff;
        --inv-soft-green: #f0fdf4;
        --inv-soft-orange: #fff7ed;
        --inv-soft-red: #fef2f2;
    }

    .inv-hero {
        position: relative;
        overflow: hidden;
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 1rem;
        padding: 1.35rem 1.45rem;
        margin-bottom: 1.2rem;
        border: 1px solid rgba(99, 102, 241, 0.16);
        border-radius: 20px;
        background:
            radial-gradient(circle at 0% 0%, rgba(59, 130, 246, 0.18), transparent 38%),
            radial-gradient(circle at 100% 100%, rgba(168, 85, 247, 0.16), transparent 40%),
            linear-gradient(135deg, #ffffff 0%, #f8faff 55%, #fbf7ff 100%);
        box-shadow: 0 16px 36px rgba(15, 23, 42, 0.07);
    }

    .inv-hero::after {
        content: '';
        position: absolute;
        width: 180px;
        height: 180px;
        right: -72px;
        top: -88px;
        border-radius: 50%;
        background: linear-gradient(135deg, rgba(37, 99, 235, 0.18), rgba(147, 51, 234, 0.12));
        pointer-events: none;
    }

    .inv-title-wrap {
        display: flex;
        align-items: center;
        gap: 0.9rem;
        position: relative;
        z-index: 1;
    }

    .inv-title-icon {
        flex: 0 0 auto;
        display: grid;
        place-items: center;
        width: 50px;
        height: 50px;
        border-radius: 15px;
        color: white;
        background: linear-gradient(135deg, var(--inv-blue), var(--inv-violet));
        box-shadow: 0 12px 24px rgba(79, 70, 229, 0.25);
    }

    .inv-title-icon svg { width: 25px; height: 25px; }

    .inv-hero h1 {
        margin: 0;
        font-size: clamp(1.35rem, 2vw, 1.85rem);
        letter-spacing: -0.025em;
    }

    .inv-hero p { margin: 0.25rem 0 0; }

    .inv-actions {
        position: relative;
        z-index: 1;
        display: flex;
        justify-content: flex-end;
        gap: 0.5rem;
        flex-wrap: wrap;
    }

    .inv-action {
        display: inline-flex;
        align-items: center;
        gap: 0.4rem;
        min-height: 38px;
        border-radius: 11px;
        font-weight: 700;
        text-decoration: none;
    }

    .inv-action svg { width: 16px; height: 16px; }

    .inv-action-primary {
        color: #fff;
        border-color: transparent;
        background: linear-gradient(135deg, var(--inv-blue), #4f46e5);
        box-shadow: 0 8px 18px rgba(37, 99, 235, 0.2);
    }

    .inv-action-history {
        color: #6d28d9;
        border: 1px solid #ddd6fe;
        background: var(--inv-soft-violet);
    }

    .inv-action-export {
        color: #0369a1;
        border: 1px solid #bae6fd;
        background: #f0f9ff;
    }

    .inv-kpis {
        display: grid;
        grid-template-columns: repeat(4, minmax(0, 1fr));
        gap: 0.8rem;
        margin-bottom: 1.2rem;
    }

    .inv-kpi {
        position: relative;
        overflow: hidden;
        min-height: 104px;
        padding: 1rem;
        border: 1px solid var(--border);
        border-radius: 16px;
        background: #fff;
        box-shadow: 0 8px 20px rgba(15, 23, 42, 0.045);
    }

    .inv-kpi::after {
        content: '';
        position: absolute;
        width: 74px;
        height: 74px;
        right: -20px;
        bottom: -27px;
        border-radius: 50%;
        background: currentColor;
        opacity: 0.08;
    }

    .inv-kpi-label {
        display: flex;
        align-items: center;
        gap: 0.4rem;
        margin-bottom: 0.55rem;
        color: var(--ink-3);
        font-size: 0.74rem;
        font-weight: 800;
        letter-spacing: 0.045em;
        text-transform: uppercase;
    }

    .inv-kpi-label svg { width: 15px; height: 15px; }

    .inv-kpi-value {
        font-size: 1.65rem;
        line-height: 1;
        font-weight: 850;
        letter-spacing: -0.04em;
        color: var(--ink-1);
    }

    .inv-kpi-note {
        margin-top: 0.38rem;
        color: var(--ink-3);
        font-size: 0.75rem;
    }

    .inv-kpi-blue { color: var(--inv-blue); background: linear-gradient(145deg, #fff, var(--inv-soft-blue)); }
    .inv-kpi-green { color: var(--inv-green); background: linear-gradient(145deg, #fff, var(--inv-soft-green)); }
    .inv-kpi-red { color: var(--inv-red); background: linear-gradient(145deg, #fff, var(--inv-soft-red)); }
    .inv-kpi-orange { color: var(--inv-orange); background: linear-gradient(145deg, #fff, var(--inv-soft-orange)); }

    .inv-tools-grid {
        display: grid;
        grid-template-columns: minmax(0, 1fr) minmax(0, 1fr);
        gap: 1rem;
        margin-bottom: 1.2rem;
    }

    .inv-tool-card {
        position: relative;
        overflow: hidden;
        padding: 1.15rem;
        border: 1px solid var(--border);
        border-radius: 18px;
        background: #fff;
        box-shadow: 0 10px 24px rgba(15, 23, 42, 0.05);
    }

    .inv-tool-card.add-card {
        background: linear-gradient(145deg, #fff 45%, #eff6ff 100%);
    }

    .inv-tool-card.import-card {
        background: linear-gradient(145deg, #fff 45%, #f5f3ff 100%);
    }

    .inv-tool-head {
        display: flex;
        align-items: flex-start;
        gap: 0.75rem;
        margin-bottom: 1rem;
    }

    .inv-tool-icon {
        flex: 0 0 auto;
        display: grid;
        place-items: center;
        width: 40px;
        height: 40px;
        border-radius: 12px;
    }

    .inv-tool-icon svg { width: 20px; height: 20px; }
    .add-card .inv-tool-icon { color: #1d4ed8; background: #dbeafe; }
    .import-card .inv-tool-icon { color: #6d28d9; background: #ede9fe; }

    .inv-tool-card h2 { margin: 0; font-size: 1rem; }
    .inv-tool-card .sub { margin: 0.2rem 0 0; line-height: 1.5; }

    .inv-form-grid {
        display: grid;
        grid-template-columns: repeat(3, minmax(0, 1fr));
        gap: 0.8rem 0.9rem;
        align-items: end;
    }
    /* Material name and Photo take the full width; the button sits on its own
       row, right-aligned. Everything else flows in the 3-column grid. */
    .inv-form-grid .material-field { grid-column: 1 / -1; }
    .inv-form-grid .inv-submit { grid-column: 1 / -1; justify-self: end; min-width: 210px; margin-top: 0.2rem; }

    .inv-tool-card .field { margin-bottom: 0; }
    .inv-tool-card .field label { font-size: 0.78rem; font-weight: 700; color: #47566d; margin-bottom: 0.3rem; }

    .inv-tool-card input,
    .inv-tool-card select,
    .inv-search input,
    .stock-edit input {
        border-radius: 10px;
        border-color: #dbe2ec;
        background: rgba(255, 255, 255, 0.92);
        transition: border-color 0.15s ease, box-shadow 0.15s ease, background 0.15s ease;
    }

    .inv-tool-card input:focus,
    .inv-tool-card select:focus,
    .inv-search input:focus,
    .stock-edit input:focus {
        border-color: #93c5fd;
        background: #fff;
        box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.12);
        outline: none;
    }

    /* The photo picker styled as a soft dashed drop-zone. */
    .inv-tool-card input[type="file"] {
        padding: 0.55rem 0.7rem;
        border: 1px dashed #b9c6db;
        background: rgba(248, 250, 255, 0.9);
        cursor: pointer;
    }
    .inv-tool-card input[type="file"]:hover { border-color: #93c5fd; background: #fff; }

    .inv-submit {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 0.5rem;
        min-height: 40px;
        padding: 0.55rem 1.25rem;
        border: 0;
        border-radius: 11px;
        color: #fff;
        font-size: 0.88rem;
        font-weight: 700;
        cursor: pointer;
        box-shadow: 0 6px 16px rgba(79, 70, 229, 0.22);
        transition: transform 0.12s ease, box-shadow 0.12s ease, filter 0.12s ease;
    }
    .inv-submit:hover { filter: brightness(1.05); transform: translateY(-1px); box-shadow: 0 9px 22px rgba(79, 70, 229, 0.3); }
    .inv-submit:active { transform: translateY(0); }

    .inv-submit svg { width: 16px; height: 16px; }
    .inv-submit-add { background: linear-gradient(135deg, var(--inv-blue), #4f46e5); }
    .inv-submit-import { background: linear-gradient(135deg, var(--inv-violet), #9333ea); }

    .inv-import-form {
        display: flex;
        align-items: center;
        gap: 0.65rem;
        flex-wrap: wrap;
    }

    .inv-import-form input[type="file"] {
        flex: 1;
        min-width: 210px;
        padding: 0.48rem;
    }

    .inv-stock-card {
        overflow: hidden;
        border: 1px solid var(--border);
        border-radius: 19px;
        background: #fff;
        box-shadow: 0 12px 28px rgba(15, 23, 42, 0.055);
    }

    .inv-stock-head {
        display: flex;
        flex-direction: column;
        align-items: stretch;
        gap: 0.85rem;
        padding: 1rem 1.1rem;
        border-bottom: 1px solid var(--border);
        background: linear-gradient(90deg, #ffffff, #f8faff);
    }

    .inv-stock-title {
        display: flex;
        align-items: center;
        gap: 0.7rem;
    }

    .inv-stock-title-icon {
        display: grid;
        place-items: center;
        width: 38px;
        height: 38px;
        border-radius: 11px;
        color: #0f766e;
        background: #ccfbf1;
    }

    .inv-stock-title-icon svg { width: 19px; height: 19px; }
    .inv-stock-title h2 { margin: 0; font-size: 1.05rem; }
    .inv-stock-title p { margin: 0.18rem 0 0; color: var(--ink-3); font-size: 0.76rem; }

    .inv-stock-controls {
        display: flex;
        align-items: center;
        justify-content: flex-start;
        gap: 0.55rem;
        flex-wrap: wrap;
        width: 100%;
    }
    .inv-stock-controls .inv-cat-filter { flex: 0 0 auto; }
    .inv-stock-controls .inv-search { flex: 1 1 200px; }
    .inv-stock-controls .inv-search input { width: 100%; }
    .inv-stock-controls .inv-count { margin-left: auto; }

    .inv-out-badge {
        display: inline-flex;
        align-items: center;
        gap: 0.3rem;
        padding: 0.38rem 0.55rem;
        border: 1px solid #fecaca;
        border-radius: 999px;
        color: #b91c1c;
        background: var(--inv-soft-red);
        font-size: 0.72rem;
        font-weight: 800;
    }

    .inv-cat-filter {
        width: auto;
        min-width: 150px;
        min-height: 40px;
        padding: 0.45rem 2rem 0.45rem 0.75rem;
        border-radius: 10px;
        border: 1px solid #dbe2ec;
        background: #fff;
        color: #344156;
        font-size: 0.84rem;
        font-weight: 600;
        cursor: pointer;
    }
    .inv-cat-filter:focus { border-color: #93c5fd; box-shadow: 0 0 0 3px rgba(59, 130, 246, .12); outline: none; }

    .inv-search { position: relative; }
    .inv-search svg {
        position: absolute;
        left: 0.68rem;
        top: 50%;
        width: 16px;
        height: 16px;
        color: var(--ink-3);
        transform: translateY(-50%);
        pointer-events: none;
    }

    .inv-search input {
        width: 245px;
        max-width: 100%;
        padding: 0.5rem 0.75rem 0.5rem 2.1rem;
        font-size: 0.84rem;
    }

    .inv-count {
        color: var(--ink-3);
        font-size: 0.76rem;
        white-space: nowrap;
    }

    .inv-table-wrap { overflow-x: auto; }

    .inv-table {
        width: 100%;
        border-collapse: collapse;
    }

    .inv-table thead th {
        padding: 0.72rem 0.95rem;
        color: var(--ink-3);
        background: #f8fafc;
        border-bottom: 1px solid var(--border);
        font-size: 0.68rem;
        font-weight: 850;
        letter-spacing: 0.055em;
        text-align: left;
        text-transform: uppercase;
        white-space: nowrap;
    }

    .inv-table tbody td {
        padding: 0.78rem 0.95rem;
        border-bottom: 1px solid #eef2f7;
        vertical-align: middle;
    }

    .inv-table tbody tr {
        transition: background 0.14s ease, transform 0.14s ease;
    }

    .inv-table tbody tr:hover { background: #f8faff; }
    .inv-table tbody tr:last-child td { border-bottom: 0; }
    .inv-table tbody tr.is-out { background: linear-gradient(90deg, #fff8f8, #fff); }
    .inv-table tbody tr.is-out:hover { background: #fff1f2; }

    .material-cell {
        display: flex;
        align-items: center;
        gap: 0.7rem;
        min-width: 220px;
    }

    .material-icon {
        flex: 0 0 auto;
        display: grid;
        place-items: center;
        width: 36px;
        height: 36px;
        border-radius: 11px;
        color: #1d4ed8;
        background: #dbeafe;
        font-size: 0.8rem;
        font-weight: 850;
        text-transform: uppercase;
    }

    .is-out .material-icon { color: #b91c1c; background: #fee2e2; }

    .material-name { color: var(--ink-1); font-weight: 750; }
    .material-unit { margin-top: 0.08rem; color: var(--ink-3); font-size: 0.73rem; }

    .stock-value {
        display: inline-flex;
        align-items: baseline;
        gap: 0.3rem;
        padding: 0.35rem 0.55rem;
        border-radius: 9px;
        color: #166534;
        background: #f0fdf4;
        font-weight: 850;
        white-space: nowrap;
    }

    .stock-value small { color: #4b7b58; font-size: 0.72rem; font-weight: 700; }

    .stock-out {
        display: inline-flex;
        align-items: center;
        gap: 0.3rem;
        padding: 0.35rem 0.55rem;
        border: 1px solid #fecaca;
        border-radius: 9px;
        color: #b91c1c;
        background: var(--inv-soft-red);
        font-size: 0.7rem;
        font-weight: 850;
        white-space: nowrap;
    }

    .stock-edit {
        display: flex;
        align-items: center;
        gap: 0.4rem;
        flex-wrap: wrap;
    }

    .stock-edit .qty-input { width: 105px; }
    .stock-edit .unit-input { width: 82px; }
    .stock-edit input { padding: 0.4rem 0.52rem; font-size: 0.82rem; }

    .stock-save {
        display: inline-grid;
        place-items: center;
        width: 34px;
        height: 34px;
        padding: 0;
        border: 0;
        border-radius: 9px;
        color: #fff;
        background: linear-gradient(135deg, #0ea5e9, #2563eb);
        box-shadow: 0 5px 12px rgba(37, 99, 235, 0.18);
        cursor: pointer;
    }

    .stock-save svg { width: 16px; height: 16px; }

    .stock-delete {
        display: inline-grid;
        place-items: center;
        width: 34px;
        height: 34px;
        padding: 0;
        border: 1px solid #fecaca;
        border-radius: 9px;
        color: #dc2626;
        background: #fff;
        cursor: pointer;
        transition: color 0.14s ease, background 0.14s ease, transform 0.14s ease;
    }

    .stock-delete:hover { color: #fff; background: #dc2626; transform: translateY(-1px); }
    .stock-delete svg { width: 15px; height: 15px; }

    .inv-empty {
        display: grid;
        place-items: center;
        min-height: 210px;
        padding: 2rem;
        text-align: center;
    }

    .inv-empty-icon {
        display: grid;
        place-items: center;
        width: 58px;
        height: 58px;
        margin-bottom: 0.75rem;
        border-radius: 17px;
        color: #64748b;
        background: #f1f5f9;
    }

    .inv-empty-icon svg { width: 27px; height: 27px; }
    .inv-empty h3 { margin: 0; font-size: 1rem; }
    .inv-empty p { max-width: 430px; margin: 0.35rem 0 0; color: var(--ink-3); font-size: 0.84rem; }

    @media (max-width: 1050px) {
        .inv-kpis { grid-template-columns: repeat(2, minmax(0, 1fr)); }
        .inv-form-grid { grid-template-columns: 1fr 1fr; }
        .inv-form-grid .material-field { grid-column: 1 / -1; }
        .inv-form-grid .inv-submit { grid-column: 1 / -1; }
    }

    @media (max-width: 760px) {
        .inv-hero { align-items: flex-start; flex-direction: column; padding: 1rem; border-radius: 16px; }
        .inv-actions { width: 100%; justify-content: flex-start; }
        .inv-actions .btn { flex: 1 1 auto; justify-content: center; }
        .inv-tools-grid { grid-template-columns: 1fr; }
        .inv-stock-head { align-items: flex-start; flex-direction: column; }
        .inv-stock-controls { width: 100%; justify-content: flex-start; }
        .inv-search { flex: 1; min-width: 0; }
        .inv-search input { width: 100%; }
    }

    @media (max-width: 560px) {
        .inv-kpis { grid-template-columns: 1fr 1fr; gap: 0.6rem; }
        .inv-kpi { min-height: 92px; padding: 0.82rem; }
        .inv-kpi-value { font-size: 1.35rem; }
        .inv-kpi-note { display: none; }
        .inv-title-icon { width: 44px; height: 44px; border-radius: 13px; }
        .inv-form-grid { grid-template-columns: 1fr; }
        .inv-form-grid .material-field,
        .inv-form-grid .inv-submit { grid-column: auto; }
        .inv-import-form { align-items: stretch; flex-direction: column; }
        .inv-import-form input[type="file"] { min-width: 0; width: 100%; }
        .inv-table thead { display: none; }
        .inv-table,
        .inv-table tbody,
        .inv-table tr,
        .inv-table td { display: block; width: 100%; }
        .inv-table tbody tr { padding: 0.85rem; border-bottom: 1px solid var(--border); }
        .inv-table tbody td { padding: 0.35rem 0; border: 0; }
        .inv-table tbody td:last-child { position: absolute; right: 0.85rem; top: 0.85rem; width: auto; }
        .inv-table tbody tr { position: relative; }
        .material-cell { padding-right: 48px; }
        .stock-edit { padding-top: 0.25rem; }
        .stock-edit .qty-input { flex: 1; min-width: 90px; }
        .stock-edit .unit-input { width: 78px; }
    }
</style>

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
                            <th>Material</th>
                            <th>Beginning</th>
                            <th>Current stock</th>
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
                                                data-stock="{{ $item->qtyForHumans() }}">＋ Restock</button>
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
        <form method="POST" id="rmForm">
            @csrf
            <input type="hidden" name="unit" id="rmUnit">
            <input type="hidden" name="quantity" id="rmQty">
            <div class="field">
                <label>Add to stock <span id="rmStock" style="color: var(--ink-3); font-weight: 400;"></span></label>
                <input type="number" id="rmAdd" step="0.01" min="0.01" required autocomplete="off" placeholder="e.g. 20">
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
                <button class="btn btn-primary btn-sm">＋ Add to stock</button>
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

<style>
    .rm-overlay { position: fixed; inset: 0; z-index: 3000; background: rgba(15,23,42,.55); -webkit-backdrop-filter: blur(2px); backdrop-filter: blur(2px); display: grid; place-items: center; padding: 1rem; }
    .rm-overlay[hidden] { display: none; }
    .rm-box { background: #fff; width: min(420px, 100%); border-radius: 16px; padding: 1.4rem 1.5rem; box-shadow: 0 24px 60px rgba(15,23,42,.35); animation: rmIn .16s ease both; }
    @keyframes rmIn { from { opacity: 0; transform: translateY(10px) scale(.98); } to { opacity: 1; transform: none; } }
    .rm-box h3 { font-family: var(--font-head); font-size: 1.1rem; margin-bottom: 1rem; }
    .rm-box .field { margin-bottom: 0.8rem; }
    .rm-actions { display: flex; justify-content: flex-end; gap: 0.5rem; margin-top: 0.4rem; }
</style>

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
            var current = 0;

            function openRestock(btn) {
                current = Number(btn.getAttribute('data-current') || 0);
                form.action = btn.getAttribute('data-action');
                title.textContent = 'Restock ' + btn.getAttribute('data-name');
                stock.textContent = '(now ' + btn.getAttribute('data-stock') + ' ' + btn.getAttribute('data-unit') + ')';
                unitHidden.value = btn.getAttribute('data-unit');
                addEl.value = ''; nameEl.value = ''; form.querySelector('[name="note"]').value = '';
                modal.hidden = false;
                setTimeout(function () { addEl.focus(); }, 30);
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