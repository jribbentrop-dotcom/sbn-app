@extends('layouts.admin')

@section('title', $isNew ? 'New Lesson' : 'Edit: ' . $lesson->title)

@section('actions')
    <a href="{{ route('admin.courses.edit', $course) }}" class="sbn-btn sbn-btn-secondary">← Back to {{ $course->title }}</a>
    @if(!$isNew)
        <a href="{{ route('courses.lesson', ['course' => $course->slug, 'lesson' => $lesson->slug]) }}"
           target="_blank" class="sbn-btn sbn-btn-ghost">Preview ↗</a>
    @endif
@endsection

@section('content')

<form method="POST"
      action="{{ $isNew ? route('admin.courses.lessons.store', $course) : route('admin.lessons.update', $lesson) }}"
      x-data="lessonForm()"
      x-init="init()">
    @csrf
    @if(!$isNew) @method('PUT') @endif

    <div style="display:grid; grid-template-columns:1fr 320px; gap:20px; align-items:start;">

        {{-- Left: content editor --}}
        <div>
            <div class="sbn-editor-card">
                <div class="sbn-editor-card-header">
                    <h2>{{ $isNew ? 'New Lesson' : 'Lesson Content' }}</h2>
                </div>
                <div class="sbn-editor-card-body">

                    <div class="sbn-form-row sbn-form-row-2">
                        <div class="sbn-form-group">
                            <label for="title">Title</label>
                            <input type="text" id="title" name="title" class="sbn-search-input" style="padding-left:14px;"
                                   value="{{ old('title', $lesson->title) }}"
                                   x-model="form.title"
                                   @input="autoSlug()"
                                   placeholder="e.g. The Basic Bossa Clave" required>
                            @error('title')<p class="sbn-form-hint" style="color:var(--clr-danger)">{{ $message }}</p>@enderror
                        </div>
                        <div class="sbn-form-group">
                            <label for="slug">Slug</label>
                            <input type="text" id="slug" name="slug" class="sbn-search-input" style="padding-left:14px;"
                                   value="{{ old('slug', $lesson->slug) }}"
                                   x-model="form.slug"
                                   @input="manualSlug = true"
                                   placeholder="e.g. basic-bossa-clave"
                                   pattern="[a-z0-9\-]+" required>
                            @error('slug')<p class="sbn-form-hint" style="color:var(--clr-danger)">{{ $message }}</p>@enderror
                        </div>
                    </div>

                    {{-- Content editor — TipTap Vue island (Phase 11b step 3).
                         Hidden textarea synced on every editor update; form submit
                         picks up the value unchanged. --}}
                    <div class="sbn-form-group" style="margin-top:4px;">
                        <label>Content</label>
                        <textarea id="content-sync" name="content" style="display:none;"></textarea>
                        <script id="lesson-content-data" type="application/json">{!! json_encode(old('content', $lesson->content ?? ''), JSON_HEX_TAG) !!}</script>
                        <div id="lesson-editor"
                             data-lesson-id="{{ $lesson->id }}"
                             style="border:1px solid var(--clr-border); border-radius:var(--radius-sm); overflow:hidden;">
                        </div>
                    </div>

                </div>
            </div>

            {{-- AI assistant — inline below editor --}}
            <div class="sbn-editor-card" style="margin-top:0; border-top:none; border-radius:0 0 var(--radius-sm) var(--radius-sm);">
                <div id="lesson-ai-panel"
                     data-lesson-title="{{ $lesson->title }}"
                     data-course-title="{{ $course->title }}"
                     data-course-genre="{{ $course->category ?? '' }}"
                     data-section-title="{{ $lesson->section_title ?? '' }}">
                </div>
            </div>
        </div>

        {{-- Right: meta sidebar --}}
        <div class="sbn-editor-sidebar">

            <div class="sbn-editor-card">
                <div class="sbn-editor-card-header"><h2>Publish</h2></div>
                <div class="sbn-editor-card-body">
                    <div class="sbn-form-group">
                        <label for="status">Status</label>
                        <select id="status" name="status" class="sbn-search-input" style="padding-left:14px;">
                            <option value="publish" @selected(old('status', $lesson->status ?? 'publish') === 'publish')>Published</option>
                            <option value="draft"   @selected(old('status', $lesson->status) === 'draft')>Draft</option>
                        </select>
                    </div>
                    <div class="sbn-form-group" style="margin-top:12px;">
                        <label style="display:flex; align-items:center; gap:8px; cursor:pointer;">
                            <input type="hidden" name="is_preview" value="0">
                            <input type="checkbox" name="is_preview" value="1"
                                   @checked(old('is_preview', $lesson->is_preview))>
                            Free preview (visible without purchase)
                        </label>
                    </div>
                    <div style="margin-top:16px; display:flex; gap:8px;">
                        <button type="submit" class="sbn-btn sbn-btn-primary" style="flex:1;">
                            {{ $isNew ? 'Create' : 'Save' }}
                        </button>
                        @if(!$isNew)
                        <button type="submit" form="delete-lesson-form"
                                class="sbn-btn sbn-btn-ghost" style="color:var(--clr-danger);"
                                onclick="return confirm('Delete this lesson?')">Delete</button>
                        @endif
                    </div>
                </div>
            </div>

            <div class="sbn-editor-card">
                <div class="sbn-editor-card-header"><h2>Organisation</h2></div>
                <div class="sbn-editor-card-body">
                    <div class="sbn-form-group">
                        <label for="section_title">Section heading</label>
                        <input type="text" id="section_title" name="section_title" class="sbn-search-input" style="padding-left:14px;"
                               value="{{ old('section_title', $lesson->section_title) }}"
                               placeholder="e.g. Part 1 — Fundamentals">
                        <p class="sbn-form-hint">Groups lessons under this heading in the sidebar.</p>
                    </div>
                    <div class="sbn-form-group" style="margin-top:12px;">
                        <label for="sort_order">Sort order</label>
                        <input type="number" id="sort_order" name="sort_order" class="sbn-search-input" style="padding-left:14px;"
                               value="{{ old('sort_order', $lesson->sort_order ?? 0) }}" min="0">
                    </div>
                </div>
            </div>


            <div class="sbn-editor-card">
                <div class="sbn-editor-card-header"><h2>Insert component</h2></div>
                <div class="sbn-editor-card-body" style="padding:0;">
                    <div id="lesson-palette"
                         data-lesson-id="{{ $lesson->id }}"
                         data-widgets="{{ json_encode($widgetList) }}"></div>
                </div>
            </div>

        </div>
    </div>

</form>

@if(!$isNew)
<form id="delete-lesson-form" method="POST" action="{{ route('admin.lessons.destroy', $lesson) }}" style="display:none;">
    @csrf @method('DELETE')
</form>
@endif

@push('styles')
<link rel="stylesheet" href="{{ asset('css/lesson-editor.css') }}?v={{ filemtime(public_path('css/lesson-editor.css')) }}">
@endpush

@push('scripts')
@vite('resources/js/admin/lesson-editor.ts')
<script>
function lessonForm() {
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
    };
}
</script>
@endpush

@endsection
