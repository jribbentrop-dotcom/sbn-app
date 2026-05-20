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
                        @foreach($course->genres ?? [] as $genre)
                            <span class="sbn-badge sbn-badge-muted" style="margin-right:3px;">{{ $genre }}</span>
                        @endforeach
                    </td>
                    <td>
                        <span class="sbn-badge {{ $course->status === 'publish' ? 'sbn-badge-success' : 'sbn-badge-muted' }}">
                            {{ $course->status }}
                        </span>
                    </td>
                    <td class="sbn-text-dim">{{ $course->lessons_count }}</td>
                    <td style="text-align:right; white-space:nowrap;">
                        <a href="{{ route('admin.courses.edit', $course) }}" class="sbn-btn sbn-btn-ghost sbn-btn-sm">Edit</a>
                        <form method="POST" action="{{ route('admin.courses.destroy', $course) }}"
                              style="display:inline;"
                              x-data
                              @submit.prevent="if(confirm('Delete this course and all its lessons?')) $el.submit()">
                            @csrf @method('DELETE')
                            <button type="submit" class="sbn-btn sbn-btn-ghost sbn-btn-sm" style="color:var(--clr-danger);">Delete</button>
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

@endsection
