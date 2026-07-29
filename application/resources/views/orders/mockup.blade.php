@extends('layouts.app')

@section('title', 'Mockup — '.$order->order_number)
@section('page-title', 'Mockup')

@section('content')
@php
    $mockupTask = $order->tasks->firstWhere('department', 'Final mockup');
    $layoutTask = $order->tasks->firstWhere('department', 'Layout');
    $imgTask = ($mockupTask && $mockupTask->files->isNotEmpty()) ? $mockupTask : $layoutTask;
    $mockupFiles = $imgTask?->files->where('round', ($imgTask->revision_count ?? 0) + 1) ?? collect();
    $taskType = $mockupTask && $mockupTask->files->isNotEmpty() ? 'Final Mockup' : 'Layout';
@endphp

<style>
    .mockup-container {
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        min-height: 80vh;
        gap: 2rem;
        padding: 2rem;
        background: linear-gradient(135deg, #f5f5f5 0%, #ffffff 100%);
    }
    
    .mockup-card {
        background: white;
        border-radius: 12px;
        padding: 2rem;
        box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
        max-width: 90%;
        display: flex;
        flex-direction: column;
        align-items: center;
        gap: 1.5rem;
    }
    
    .mockup-image-wrapper {
        border: 2px solid var(--border);
        border-radius: 8px;
        padding: 1rem;
        background: #fafafa;
        display: flex;
        align-items: center;
        justify-content: center;
        min-height: 300px;
    }
    
    .mockup-image-wrapper img {
        max-width: 100%;
        max-height: 600px;
        object-fit: contain;
        border-radius: 4px;
    }
    
    .mockup-header {
        text-align: center;
        width: 100%;
    }
    
    .mockup-header h1 {
        margin: 0 0 0.5rem;
        color: var(--accent);
    }
    
    .mockup-header .task-type {
        font-size: 0.9rem;
        color: var(--ink-3);
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: 0.05em;
    }
    
    .mockup-order-info {
        display: flex;
        gap: 1rem;
        justify-content: center;
        flex-wrap: wrap;
        font-size: 0.85rem;
        color: var(--ink-3);
        padding-top: 1rem;
        border-top: 1px solid var(--border);
    }
    
    .mockup-actions {
        display: flex;
        gap: 0.5rem;
        justify-content: center;
        flex-wrap: wrap;
        width: 100%;
        padding-top: 1rem;
        border-top: 1px solid var(--border);
    }
    
    .mockup-no-image {
        text-align: center;
        color: var(--ink-3);
        padding: 3rem 2rem;
    }
    
    .mockup-no-image svg {
        width: 64px;
        height: 64px;
        opacity: 0.3;
        margin-bottom: 1rem;
    }
</style>

<div class="mockup-container">
    <div class="mockup-card">
        <div class="mockup-header">
            <h1>{{ $order->order_number }}</h1>
            <div class="task-type">{{ $taskType }}</div>
        </div>
        
        @if ($mockupFiles->isNotEmpty())
            @foreach ($mockupFiles as $f)
                @if ($f->isImage())
                    <div class="mockup-image-wrapper">
                        <img src="{{ route('tasks.file.view', $f) }}" alt="{{ $f->label }}" title="{{ $f->label }}">
                    </div>
                @endif
            @endforeach
        @else
            <div class="mockup-no-image">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <rect x="3" y="3" width="18" height="18" rx="2" ry="2"></rect>
                    <circle cx="8.5" cy="8.5" r="1.5"></circle>
                    <polyline points="21 15 16 10 5 21"></polyline>
                </svg>
                <p style="margin: 0; font-weight: 600;">No mockup available yet</p>
                <p style="margin: 0.5rem 0 0; font-size: 0.8rem;">The artist is working on the {{ $taskType | lower }}.</p>
            </div>
        @endif
        
        <div class="mockup-order-info">
            <span><strong>Customer:</strong> {{ $order->customer_name }}</span>
            <span><strong>Quantity:</strong> {{ number_format($order->quantity) }} pcs</span>
            @if ($order->due_date)
                <span><strong>Due:</strong> {{ $order->due_date->format('M j, Y') }}</span>
            @endif
        </div>
        
        <div class="mockup-actions">
            @if (auth()->user()->canCreateOrders())
                <a href="{{ route('orders.show', $order) }}" class="btn btn-primary btn-sm">← Back to order</a>
                <a href="{{ route('orders.job-order', $order) }}" class="btn btn-ghost btn-sm">📋 Job order</a>
            @else
                <a href="{{ route('orders.show', $order) }}" class="btn btn-ghost btn-sm">← Back</a>
            @endif
            <button onclick="window.print()" class="btn btn-ghost btn-sm">🖨 Print</button>
        </div>
    </div>
</div>

@endsection
