@extends('layouts.admin')

@section('title', 'Exercises')

@section('actions')
    <a href="{{ route('admin.leadsheets.index') }}" class="sbn-btn sbn-btn-secondary">← Songs</a>
    <a href="{{ route('admin.exercises.create') }}" class="sbn-btn sbn-btn-primary">+ New Exercise</a>
@endsection

@section('content')
<form method="GET" action="{{ route('admin.exercises.index') }}" style="display:flex; gap:10px; margin-bottom:20px; align-items:center;">
    <input type="text" name="search" class="sbn-search-input" style="width:280px;"
           value="{{ request('search') }}" placeholder="Search exercises�">
    <button type="submit" class="sbn-btn sbn-btn-secondary">Filter</button>
    @if(request('search'))
        <a href="{{ route('admin.exercises.index') }}" class="sbn-btn sbn-btn-ghost">Clear</a>
    @endif
</form>

@if($exercises->isEmpty())
    <div class="sbn-empty">
        <h3>No exercises yet</h3>
        <p>Create your first exercise to enable &lt;sbn-sheet&gt; embeds.</p>
        <a href="{{ route('admin.exercises.create') }}" class="sbn-btn sbn-btn-primary" style="margin-top:8px;">New Exercise</a>
    </div>
@else
    <div class="sbn-editor-card" style="background:#ffffff;">
        <table class="sbn-table" style="width:100%;">
            <thead>
                <tr>
                    <th>Title</th>
                    <th>Type</th>
                    <th>Key</th>
                    <th>BPM</th>
                    <th style="width:170px;"></th>
                </tr>
            </thead>
            <tbody>
                @foreach($exercises as $exercise)
                <tr>
                    <td>
                        <a href="{{ route('admin.exercises.edit', $exercise) }}" class="sbn-table-title">{{ $exercise->title }}</a>
                        <div class="sbn-text-muted" style="font-size:11px;">{{ $exercise->slug }}</div>
                    </td>
                    <td><span class="sbn-badge sbn-badge-muted">{{ $exercise->type }}</span></td>
                    <td class="sbn-text-dim">{{ $exercise->key_center }}</td>
                    <td class="sbn-text-dim">{{ $exercise->bpm_default }}</td>
                    <td style="text-align:right; white-space:nowrap;">
                        <a href="{{ route('admin.exercises.edit', $exercise) }}" class="sbn-btn sbn-btn-ghost sbn-btn-sm">Edit</a>
                        <form method="POST" action="{{ route('admin.exercises.destroy', $exercise) }}"
                              style="display:inline;"
                              x-data
                              @submit.prevent="if(confirm('Delete this exercise?')) $el.submit()">
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
        {{ $exercises->links() }}
    </div>
@endif
@endsection
