@extends('layouts.admin')

@section('title', 'New Product')

@section('actions')
    <a href="{{ route('admin.products.index') }}" class="sbn-btn sbn-btn-secondary">← Back to Products</a>
@endsection

@section('content')

<form method="POST" action="{{ route('admin.products.store') }}"
      x-data="productForm()"
      x-init="init()">
    @csrf

    @include('admin.products._form')

    <div style="margin-top:16px; display:flex; gap:10px;">
        <button type="submit" class="sbn-btn sbn-btn-primary">Create Product</button>
        <a href="{{ route('admin.products.index') }}" class="sbn-btn sbn-btn-ghost">Cancel</a>
    </div>
</form>

@push('scripts')
<script>
function productForm() {
    return {
        form: { title: '', slug: '' },
        manualSlug: false,

        init() {
            this.form.title = document.getElementById('title').value;
            this.form.slug  = document.getElementById('slug').value;
        },
        autoSlug() {
            if (!this.manualSlug) {
                this.form.slug = this.form.title
                    .toLowerCase()
                    .replace(/[^a-z0-9]+/g, '-')
                    .replace(/^-+|-+$/g, '');
            }
        },
    };
}
</script>
@endpush

@endsection
