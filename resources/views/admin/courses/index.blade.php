@extends('layouts.admin')

@section('title', 'Courses')

@section('actions')
    <a href="{{ route('admin.courses.create') }}" class="sbn-btn sbn-btn-primary">+ New Course</a>
@endsection

@section('content')

{{-- Filters --}}
<form method="GET" action="{{ route('admin.courses.index') }}" style="display:flex; gap:10px; margin-bottom:20px; align-items:center;">
    <input type="text" name="search" class="sbn-search-input" style="width:260px;"
           value="{{ request('search') }}" placeholder="Search courses…">
    <select name="status" class="sbn-search-input" style="width:140px;">
        <option value="">All statuses</option>
        <option value="publish" @selected(request('status') === 'publish')>Published</option>
        <option value="draft"   @selected(request('status') === 'draft')>Draft</option>
    </select>
    <button type="submit" class="sbn-btn sbn-btn-secondary">Filter</button>
    @if(request('search') || request('status'))
        <a href="{{ route('admin.courses.index') }}" class="sbn-btn sbn-btn-ghost">Clear</a>
    @endif
</form>

@if($courses->isEmpty())
    <div class="sbn-empty">
        <h3>No courses yet</h3>
        <p>Create your first course to get started.</p>
        <a href="{{ route('admin.courses.create') }}" class="sbn-btn sbn-btn-primary" style="margin-top:8px;">New Course</a>
    </div>
@else
    <div class="sbn-editor-card" style="background:#ffffff;">
        <table class="sbn-table" style="width:100%;">
            <thead>
                <tr>
                    <th>Title</th>
                    <th>Genres</th>
                    <th>Status</th>
                    <th>Lessons</th>
                    <th style="width:120px;"></th>
                </tr>
            </thead>
            <tbody>
                @foreach($courses as $course)
                <tr>
                    <td>
                        <a href="{{ route('admin.courses.edit', $course) }}" class="sbn-table-title">{{ $course->title }}</a>
                        <div class="sbn-text-muted" style="font-size:11px;">{{ $course->slug }}</div>
                    </td>
                    <td>
                        @if($course->category)
                            <span class="sbn-cat-badge sbn-cat-badge-filled sbn-cat-badge--{{ $course->category }}">
                                {{ \App\Models\ChordProgression::CATEGORY_LABELS[$course->category] ?? $course->category }}
                            </span>
                        @endif
                    </td>
                    <td x-data="{ status: '{{ $course->status }}' }">
                        <button @click="fetch('{{ route('admin.courses.updateStatus', $course) }}', { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content }, body: JSON.stringify({ status: status === 'publish' ? 'draft' : 'publish' }) }).then(r => r.json()).then(d => { if (d.success) status = d.status })"
                                class="sbn-badge sbn-status-toggle"
                                :class="status === 'publish' ? 'sbn-badge-success' : 'sbn-badge-muted'"
                                :title="status === 'publish' ? 'Published — click to unpublish' : 'Draft — click to publish'"
                                x-text="status">
                        </button>
                    </td>
                    <td class="sbn-text-dim">{{ $course->lessons_count }}</td>
                    <td style="text-align:right; white-space:nowrap;"
                        x-data="{ descHtml: {{ Js::from($course->description ?? '') }} }"
                        x-init="document.addEventListener('desc-editor:save:course-{{ $course->id }}', (e) => {
                            descHtml = e.detail;
                            fetch('{{ route('admin.courses.updateDescription', $course) }}', {
                                method: 'POST',
                                headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content },
                                body: JSON.stringify({ description: e.detail }),
                            }).then(r => r.json()).then(d => { if (d.success) sbnToast('Description saved', 'success'); });
                        })">
                        <button class="sbn-btn sbn-btn-xs sbn-btn-secondary"
                                title="Edit description"
                                data-course-meta='{!! htmlspecialchars(json_encode(['title' => $course->title, 'category' => $course->category ?? '', 'difficulty' => $course->levels[0] ?? '']), ENT_QUOTES) !!}'
                                @click="window.__descEditor.open({ initial: descHtml, eventName: 'desc-editor:save:course-{{ $course->id }}', placeholder: 'Full course description…', entityType: 'course', entityMeta: JSON.parse($el.dataset.courseMeta) })">
                            Desc
                        </button>
                        <a href="{{ route('courses.show', $course->slug) }}" target="_blank"
                           class="sbn-btn sbn-btn-xs sbn-btn-ghost" title="Preview on site">
                            Preview ↗
                        </a>
                        <form method="POST" action="{{ route('admin.courses.destroy', $course) }}"
                              style="display:inline;"
                              x-data
                              @submit.prevent="if(confirm('Delete this course and all its lessons?')) $el.submit()">
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
        {{ $courses->links() }}
    </div>
@endif

@push('scripts')
<div id="desc-editor-root"></div>
@vite('resources/js/admin/description-editor.ts')
<script>
function sbnToast(message, type) {
    const el = document.createElement('div');
    el.className = `sbn-toast sbn-toast-${type || 'info'}`;
    el.textContent = message;
    document.body.appendChild(el);
    setTimeout(() => { el.style.opacity = '0'; setTimeout(() => el.remove(), 300); }, 3000);
}
</script>
@endpush

@endsection
