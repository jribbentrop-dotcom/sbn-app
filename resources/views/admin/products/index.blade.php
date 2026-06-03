@extends('layouts.admin')

@section('title', 'Products')

@section('actions')
    <a href="{{ route('admin.products.create') }}" class="sbn-btn sbn-btn-primary">+ New Product</a>
@endsection

@section('content')

{{-- Filters --}}
<form method="GET" action="{{ route('admin.products.index') }}" style="display:flex; gap:10px; margin-bottom:20px; align-items:center;">
    <input type="text" name="search" class="sbn-search-input" style="width:260px;"
           value="{{ request('search') }}" placeholder="Search products…">
    <select name="status" class="sbn-search-input" style="width:140px;">
        <option value="">All statuses</option>
        <option value="published" @selected(request('status') === 'published')>Published</option>
        <option value="draft"     @selected(request('status') === 'draft')>Draft</option>
    </select>
    <button type="submit" class="sbn-btn sbn-btn-secondary">Filter</button>
    @if(request('search') || request('status'))
        <a href="{{ route('admin.products.index') }}" class="sbn-btn sbn-btn-ghost">Clear</a>
    @endif
</form>

@if($products->isEmpty())
    <div class="sbn-empty">
        <h3>No products yet</h3>
        <p>Create your first product to get started.</p>
        <a href="{{ route('admin.products.create') }}" class="sbn-btn sbn-btn-primary" style="margin-top:8px;">New Product</a>
    </div>
@else
    <div class="sbn-editor-card" style="background:#ffffff;">
        <table class="sbn-table" style="width:100%;">
            <thead>
                <tr>
                    <th>Title</th>
                    <th>Price</th>
                    <th>Status</th>
                    <th>Stripe</th>
                    <th style="width:120px;"></th>
                </tr>
            </thead>
            <tbody>
                @foreach($products as $product)
                <tr>
                    <td>
                        <a href="{{ route('admin.products.edit', $product) }}" class="sbn-table-title">{{ $product->title }}</a>
                        <div class="sbn-text-muted" style="font-size:11px;">{{ $product->slug }}</div>
                    </td>
                    <td class="sbn-text-dim">
                        €{{ number_format($product->price_cents / 100, 2) }}
                    </td>
                    <td x-data="{ status: '{{ $product->status }}' }">
                        <button @click="fetch('{{ route('admin.products.updateStatus', $product) }}', { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content }, body: JSON.stringify({ status: status === 'published' ? 'draft' : 'published' }) }).then(r => r.json()).then(d => { if (d.success) status = d.status })"
                                class="sbn-badge sbn-status-toggle"
                                :class="status === 'published' ? 'sbn-badge-success' : 'sbn-badge-muted'"
                                :title="status === 'published' ? 'Published — click to unpublish' : 'Draft — click to publish'"
                                x-text="status">
                        </button>
                    </td>
                    <td class="sbn-text-dim" style="font-size:12px;">
                        @if($product->payment_ref)
                            <span class="sbn-badge sbn-badge-success" title="{{ $product->payment_ref }}">✓ Linked</span>
                        @else
                            <span class="sbn-text-muted">—</span>
                        @endif
                    </td>
                    <td style="text-align:right;">
                        <form method="POST" action="{{ route('admin.products.destroy', $product) }}"
                              style="display:inline;"
                              x-data
                              @submit.prevent="if(confirm('Delete this product?')) $el.submit()">
                            @csrf @method('DELETE')
                            <button type="submit" class="sbn-btn-delete" title="Delete">
                                <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M2 4h12M5 4V2h6v2M6 7v5M10 7v5M3 4l1 9h8l1-9"/></svg>
                            </button>
                        </form>
                    </td>
                </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    <div style="margin-top:16px;">
        {{ $products->links() }}
    </div>
@endif

@endsection
