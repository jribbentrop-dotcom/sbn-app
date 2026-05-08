@extends('layouts.admin')

@section('title', 'New Course')

@section('actions')
    <a href="{{ route('admin.courses.index') }}" class="sbn-btn sbn-btn-secondary">← Back to Courses</a>
@endsection

@section('content')

<form method="POST" action="{{ route('admin.courses.store') }}"
      x-data="courseForm()"
      x-init="init()">
    @csrf

    {{-- Hidden JSON arrays built from comma-separated inputs --}}
    <input type="hidden" name="genres"  x-bind:value="JSON.stringify(arrayField('genres_raw'))">
    <input type="hidden" name="levels"  x-bind:value="JSON.stringify(arrayField('levels_raw'))">
    <input type="hidden" name="topics"  x-bind:value="JSON.stringify(arrayField('topics_raw'))">

    @include('admin.courses._form')

    <div style="margin-top:16px; display:flex; gap:10px;">
        <button type="submit" class="sbn-btn sbn-btn-primary">Create Course</button>
        <a href="{{ route('admin.courses.index') }}" class="sbn-btn sbn-btn-ghost">Cancel</a>
    </div>
</form>

@push('scripts')
<script>
function courseForm() {
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
        arrayField(name) {
            const el = document.querySelector(`[name="${name}"]`);
            if (!el || !el.value.trim()) return [];
            return el.value.split(',').map(s => s.trim()).filter(Boolean);
        },
    };
}
</script>
@endpush

@endsection
