@extends('layouts.admin')

@section('title', 'Fretboards')

@section('actions')
    <a href="{{ route('admin.fretboards.create') }}" class="sbn-btn sbn-btn-primary">+ New Fretboard</a>
@endsection

@section('content')

@if(session('success'))
    <div class="sbn-flash sbn-flash-success" style="margin-bottom:16px;">{{ session('success') }}</div>
@endif

@if($fretboards->isEmpty())
    <div class="sbn-empty-state">
        <div class="sbn-empty-icon">🎸</div>
        <h3>No fretboards yet</h3>
        <p>Create interactive fretboard diagrams for chord shapes, scale positions, and voice-leading sequences.</p>
        <a href="{{ route('admin.fretboards.create') }}" class="sbn-btn sbn-btn-primary">Create your first fretboard</a>
    </div>
@else
    <div class="sbn-list-table-wrap">
        <table class="sbn-table">
            <thead>
                <tr>
                    <th>Title</th>
                    <th>Slug</th>
                    <th>Mode</th>
                    <th>Frames</th>
                    <th style="width:120px;"></th>
                </tr>
            </thead>
            <tbody>
                @foreach($fretboards as $fb)
                <tr>
                    <td>
                        <strong>{{ $fb->title }}</strong>
                        @if($fb->description)
                            <div style="font-size:12px;color:var(--clr-text-dim);margin-top:2px;">{{ Str::limit($fb->description, 60) }}</div>
                        @endif
                    </td>
                    <td>
                        <code style="font-size:12px;">{{ $fb->slug }}</code>
                        <button type="button"
                                class="sbn-btn-icon"
                                title="Copy tag"
                                onclick="navigator.clipboard.writeText('<sbn-fretboard slug=&quot;{{ $fb->slug }}&quot;>').then(() => sbnToast('Tag copied', 'success'))">
                            <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5" width="13" height="13"><rect x="5" y="5" width="9" height="9" rx="1.5"/><path d="M11 5V3.5A1.5 1.5 0 009.5 2H2.5A1.5 1.5 0 001 3.5v7A1.5 1.5 0 002.5 12H4"/></svg>
                        </button>
                    </td>
                    <td>
                        <span class="sbn-badge sbn-badge-{{ $fb->display_mode === 'chord' ? 'blue' : ($fb->display_mode === 'scale' ? 'green' : 'purple') }}">
                            {{ ucfirst($fb->display_mode) }}
                        </span>
                    </td>
                    <td style="font-size:13px;color:var(--clr-text-dim);">
                        {{ count($fb->voicings ?? []) }}
                    </td>
                    <td>
                        <div class="sbn-list-actions">
                            <a href="{{ route('admin.fretboards.edit', $fb) }}" class="sbn-btn-sm sbn-btn-sm-secondary">Edit</a>
                            <form method="POST" action="{{ route('admin.fretboards.destroy', $fb) }}"
                                  onsubmit="return confirm('Delete {{ addslashes($fb->title) }}?')">
                                @csrf @method('DELETE')
                                <button type="submit" class="sbn-btn-sm sbn-btn-sm-danger">Del</button>
                            </form>
                        </div>
                    </td>
                </tr>
                @endforeach
            </tbody>
        </table>
    </div>
@endif

@endsection

@push('scripts')
<script src="{{ asset('js/chords.js') }}"></script>
@endpush
