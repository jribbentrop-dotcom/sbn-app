@extends('layouts.admin')

@section('title', 'Edit: ' . $course->title)

@section('actions')
    <a href="{{ route('admin.courses.index') }}" class="sbn-btn sbn-btn-secondary">← Back to Courses</a>
    <a href="{{ route('admin.courses.lessons.create', $course) }}" class="sbn-btn sbn-btn-primary">+ Add Lesson</a>
@endsection

@section('content')

<div style="display:grid; grid-template-columns:1.5fr 1fr; gap:20px; align-items:start;">

    {{-- Left: Lesson list --}}
    <div class="sbn-editor-card"
         x-data="lessonTable('{{ route('admin.courses.lessons.reorder', $course) }}')">
        <div class="sbn-editor-card-header" style="display:flex; align-items:center; justify-content:space-between;">
            <h2>Lessons <span style="font-weight:400; color:var(--clr-text-muted);">({{ $course->lessons->count() }})</span></h2>
            <a href="{{ route('admin.courses.lessons.create', $course) }}" class="sbn-btn sbn-btn-primary sbn-btn-sm">+ Add Lesson</a>
        </div>
        <div class="sbn-editor-card-body" style="padding:0; background:#ffffff;">
            @if($course->lessons->isEmpty())
                <p style="padding:20px; color:var(--clr-text-muted);">No lessons yet — add one above.</p>
            @else
                <table class="sbn-table" style="width:100%;">
                    <thead>
                        <tr>
                            <th style="width:36px;"></th>
                            <th>#</th>
                            <th>Title</th>
                            <th>Section</th>
                            <th>Status</th>
                            <th>Preview</th>
                            <th style="width:120px;"></th>
                        </tr>
                    </thead>
                    <tbody id="lesson-list">
                        @foreach($course->lessons->sortBy('sort_order') as $lesson)
                        <tr data-id="{{ $lesson->id }}" data-sort="{{ $lesson->sort_order }}">
                            <td style="cursor:grab; color:var(--clr-text-muted); text-align:center;">⠿</td>
                            <td style="color:var(--clr-text-muted); font-size:12px;">{{ $loop->iteration }}</td>
                            <td>
                                <a href="{{ route('admin.lessons.edit', $lesson) }}" class="sbn-table-title">{{ $lesson->title }}</a>
                                <div style="font-size:11px; color:var(--clr-text-muted);">{{ $lesson->slug }}</div>
                            </td>
                            <td x-data="{ editing: false, val: @js($lesson->section_title) }" style="font-size:12px; position:relative;">
                                <div x-show="!editing" 
                                     @click="editing = true; $nextTick(() => $refs.input.focus())" 
                                     style="cursor:pointer; min-height:1.2em; color:var(--clr-text-muted);">
                                    <span x-text="val || '—'"></span>
                                </div>
                                <input x-show="editing"
                                       x-ref="input"
                                       type="text"
                                       x-model="val"
                                       @keydown.enter="saveField('{{ route('admin.lessons.update-field', $lesson) }}', 'section_title', val); editing = false"
                                       @blur="saveField('{{ route('admin.lessons.update-field', $lesson) }}', 'section_title', val); editing = false"
                                       style="width:100%; padding:2px 6px; font-size:12px; border:1.5px solid var(--clr-accent); border-radius:4px; outline:none; background:#fff;">
                            </td>
                            <td x-data="{ status: '{{ $lesson->status }}' }">
                                <button @click="fetch('{{ route('admin.lessons.updateStatus', $lesson) }}', { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content }, body: JSON.stringify({ status: status === 'publish' ? 'draft' : 'publish' }) }).then(r => r.json()).then(d => { if (d.success) status = d.status })"
                                        class="sbn-badge sbn-status-toggle"
                                        :class="status === 'publish' ? 'sbn-badge-success' : 'sbn-badge-muted'"
                                        :title="status === 'publish' ? 'Published — click to unpublish' : 'Draft — click to publish'"
                                        x-text="status">
                                </button>
                            </td>
                            <td>
                                @if($lesson->is_preview)
                                    <span class="sbn-badge sbn-badge-muted">Preview</span>
                                @endif
                            </td>
                            <td style="text-align:right;">
                                <form method="POST" action="{{ route('admin.lessons.destroy', $lesson) }}"
                                      style="display:inline;"
                                      x-data
                                      @submit.prevent="if(confirm('Delete this lesson?')) $el.submit()">
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
            @endif
        </div>
    </div>

    {{-- Right: Meta info --}}
    <div class="sbn-editor-sidebar">
        <form method="POST" action="{{ route('admin.courses.update', $course) }}"
              x-data="courseForm()"
              x-init="init()">
            @csrf @method('PUT')

            <input type="hidden" name="genres"  x-bind:value="JSON.stringify(arrayField('genres_raw'))">
            <input type="hidden" name="levels"  x-bind:value="JSON.stringify(arrayField('levels_raw'))">
            <input type="hidden" name="topics"  x-bind:value="JSON.stringify(arrayField('topics_raw'))">

            @include('admin.courses._form')

            <div style="margin-top:16px; display:flex; gap:10px;">
                <button type="submit" class="sbn-btn sbn-btn-primary">Save Course</button>
            </div>
        </form>
    </div>

</div>

@push('scripts')
<script>
function courseForm() {
    return {
        form: { title: '', slug: '' },
        manualSlug: false,
        init() {
            this.form.title = document.getElementById('title').value;
            this.form.slug  = document.getElementById('slug').value;
            if (this.form.slug) this.manualSlug = true;
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

function lessonTable(reorderUrl) {
    return {
        init() {
            // Simple drag-reorder using the HTML5 drag API.
            const tbody = document.getElementById('lesson-list');
            if (!tbody) return;
            let dragged = null;

            tbody.querySelectorAll('tr').forEach(row => {
                row.setAttribute('draggable', 'true');
                row.addEventListener('dragstart', () => { dragged = row; row.style.opacity = '0.4'; });
                row.addEventListener('dragend',   () => { dragged = null; row.style.opacity = ''; });
                row.addEventListener('dragover',  e => { e.preventDefault(); row.style.background = 'var(--clr-bg-hover)'; });
                row.addEventListener('dragleave', () => { row.style.background = ''; });
                row.addEventListener('drop', () => {
                    row.style.background = '';
                    if (!dragged || dragged === row) return;
                    tbody.insertBefore(dragged, row);
                    this.saveOrder(tbody);
                });
            });
        },
        saveOrder(tbody) {
            const items = Array.from(tbody.querySelectorAll('tr')).map((row, i) => ({
                id: parseInt(row.dataset.id),
                sort_order: i,
            }));
            fetch(reorderUrl, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content },
                body: JSON.stringify({ items }),
            });
        },
        saveField(url, field, value) {
            fetch(url, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content },
                body: JSON.stringify({ field, value }),
            });
        }
    };
}
</script>
@endpush

@endsection
