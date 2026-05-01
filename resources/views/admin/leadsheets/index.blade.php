@extends('layouts.admin')

@section('title', 'Leadsheets')

@section('actions')
    <div class="sbn-dropdown" x-data="{ open: false }">
        <button class="sbn-btn sbn-btn-primary" @click="open = !open" @click.outside="open = false">
            + New leadsheet ▾
        </button>
        <div class="sbn-dropdown-menu" x-show="open" x-cloak>
            <a href="#blank" class="sbn-dropdown-item" @click="open = false; window.blankModal && window.blankModal().open()">Blank sheet</a>
            <a href="#progression" class="sbn-dropdown-item" @click="open = false; window.progressionModal && window.progressionModal().open()">From progression</a>
            <a href="#lookup" class="sbn-dropdown-item" @click="open = false; window.lookupModal && window.lookupModal().open()">From song lookup</a>

            <a href="#" class="sbn-dropdown-item sbn-dropdown-item-disabled" title="Coming soon (L4)">From source…</a>
        </div>
    </div>
    <a href="{{ route('admin.leadsheets.create') }}" class="sbn-btn">Import XML</a>
@endsection

@push('styles')
    <link rel="stylesheet" href="{{ asset('css/leadsheets.css') }}">
    <style>
        .sbn-dropdown {
            position: relative;
            display: inline-block;
        }

        .sbn-dropdown-menu {
            position: absolute;
            top: 100%;
            left: 0;
            margin-top: 4px;
            background: white;
            border: 1px solid #d1d5db;
            border-radius: 4px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            min-width: 200px;
            z-index: 100;
        }

        .sbn-dropdown-item {
            display: block;
            padding: 8px 16px;
            color: #374151;
            text-decoration: none;
            font-size: 14px;
        }

        .sbn-dropdown-item:hover {
            background: #f3f4f6;
        }

        .sbn-dropdown-item-disabled {
            color: #9ca3af;
            cursor: not-allowed;
        }

        .sbn-dropdown-item-disabled:hover {
            background: none;
        }
    </style>
@endpush

@section('content')
<div x-data="leadsheetIndex()">

    {{-- Stats row --}}
    <div class="sbn-stats-row">
        <div class="sbn-stat-card">
            <span class="sbn-stat-value">{{ $stats['total'] }}</span>
            <span class="sbn-stat-label">Leadsheets</span>
        </div>
        <div class="sbn-stat-card">
            <span class="sbn-stat-value">{{ $stats['composers'] }}</span>
            <span class="sbn-stat-label">Composers</span>
        </div>
        <div class="sbn-stat-card">
            <span class="sbn-stat-value">{{ $stats['keys'] }}</span>
            <span class="sbn-stat-label">Keys</span>
        </div>
        <div class="sbn-stat-card">
            <span class="sbn-stat-value">{{ $stats['withMelody'] }}</span>
            <span class="sbn-stat-label">With Tab</span>
        </div>
    </div>

    {{-- Filter bar --}}
    <div class="sbn-filter-bar">
        <form method="GET" action="{{ route('admin.leadsheets.index') }}" class="sbn-filter-form">
            <div class="sbn-search-wrap">
                <svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M8 4a4 4 0 100 8 4 4 0 000-8zM2 8a6 6 0 1110.89 3.476l4.817 4.817a1 1 0 01-1.414 1.414l-4.816-4.816A6 6 0 012 8z" clip-rule="evenodd"/></svg>
                <input type="text" name="search" class="sbn-search-input" placeholder="Search by title or composer…" value="{{ request('search') }}">
            </div>
            <select name="key" class="sbn-filter-select" onchange="this.form.submit()">
                <option value="">All Keys</option>
                @foreach($keys as $k)
                    <option value="{{ $k }}" {{ request('key') === $k ? 'selected' : '' }}>{{ $k }}</option>
                @endforeach
            </select>
            <select name="composer" class="sbn-filter-select" onchange="this.form.submit()">
                <option value="">All Composers</option>
                @foreach($composers as $c)
                    <option value="{{ $c }}" {{ request('composer') === $c ? 'selected' : '' }}>{{ $c }}</option>
                @endforeach
            </select>
            @if(request()->hasAny(['search', 'key', 'composer']))
                <a href="{{ route('admin.leadsheets.index') }}" class="sbn-filter-clear">Clear</a>
            @endif
        </form>
    </div>

    @if($leadsheets->count())
        <div class="sbn-table-wrap">
            <table class="sbn-table">
                <thead>
                    <tr>
                        <th class="col-title">Title</th>
                        <th class="col-composer">Composer</th>
                        <th class="col-key">Key</th>
                        <th class="col-tempo">BPM</th>
                        <th class="col-time">Time</th>
                        <th class="col-measures">Bars</th>
                        <th class="col-description">Description</th>
                        <th class="col-actions"></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($leadsheets as $ls)
                    <tr x-ref="row{{ $ls->id }}" class="sbn-ls-row">
                        <td class="col-title">
                            <a href="{{ route('admin.leadsheets.edit', $ls) }}" class="sbn-ls-title">
                                {{ $ls->title }}
                            </a>
                        </td>
                        <td class="col-composer sbn-text-dim">{{ $ls->composer ?: '—' }}</td>
                        <td class="col-key">
                            @if($ls->song_key)
                                <span class="sbn-badge sbn-badge-accent">{{ $ls->song_key }}</span>
                            @else
                                —
                            @endif
                        </td>
                        <td class="col-tempo sbn-text-muted">{{ $ls->tempo ?: '—' }}</td>
                        <td class="col-time sbn-text-muted">{{ $ls->time_signature ?: '4/4' }}</td>
                        <td class="col-measures sbn-text-muted">{{ $ls->measure_count ?: '—' }}</td>
                        <td class="col-description">
                            <div x-data="{ editing: false }">
                                <div x-show="!editing" class="sbn-ls-desc-row">
                                    <span class="sbn-ls-desc-text" x-ref="descText{{ $ls->id }}">
                                        @if($ls->description)
                                            {{ \Illuminate\Support\Str::words($ls->description, 12, '…') }}
                                        @else
                                            <span class="sbn-text-placeholder">Add info…</span>
                                        @endif
                                    </span>
                                    <button class="sbn-ls-desc-edit" @click="editing = true" title="Edit description">✎</button>
                                </div>
                                <div x-show="editing" x-cloak>
                                    <textarea class="sbn-ls-desc-textarea" rows="2"
                                        x-ref="descInput{{ $ls->id }}"
                                        x-init="$watch('editing', v => { if (v) $nextTick(() => $refs['descInput{{ $ls->id }}'].focus()) })"
                                    >{{ $ls->description ?? '' }}</textarea>
                                    <div class="sbn-ls-desc-actions">
                                        <button class="sbn-btn sbn-btn-xs sbn-btn-primary"
                                            @click="saveDescription({{ $ls->id }}, $refs['descInput{{ $ls->id }}'].value); editing = false">Save</button>
                                        <button class="sbn-btn sbn-btn-xs" @click="editing = false">Cancel</button>
                                    </div>
                                </div>
                            </div>
                        </td>
                        <td class="col-actions">
                            <a href="{{ route('admin.leadsheets.edit', $ls) }}" class="sbn-btn sbn-btn-xs" title="Edit">Edit</a>
                            <button class="sbn-btn sbn-btn-xs sbn-btn-danger"
                                @click="deleteLeadsheet({{ $ls->id }}, '{{ addslashes($ls->title) }}')"
                                title="Delete">×</button>
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        @if($leadsheets->hasPages())
            <div class="sbn-pagination">
                {{ $leadsheets->links() }}
            </div>
        @endif
    @else
        <div class="sbn-empty-state">
            <div class="sbn-empty-icon">🎸</div>
            <h3>No leadsheets{{ request()->hasAny(['search', 'key', 'composer']) ? ' matching your filters' : ' yet' }}</h3>
            <p>
                @if(request()->hasAny(['search', 'key', 'composer']))
                    Try adjusting your search or <a href="{{ route('admin.leadsheets.index') }}">clear filters</a>.
                @else
                    Import a MusicXML file to create your first interactive leadsheet.
                @endif
            </p>
            @unless(request()->hasAny(['search', 'key', 'composer']))
                <a href="{{ route('admin.leadsheets.create') }}" class="sbn-btn sbn-btn-primary" style="margin-top: 12px;">Import MusicXML</a>
            @endunless
        </div>
    @endif

    @include('admin.leadsheets._blank-modal')
    @include('admin.leadsheets._progression-modal')
    @include('admin.leadsheets._lookup-modal')
</div>

@endsection

@push('scripts')
<script>
function leadsheetIndex() {
    return {
        saveDescription(id, desc) {
            fetch(`/api/admin/leadsheets/${id}/description`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content,
                },
                body: JSON.stringify({ description: desc }),
            })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    // Update the visible text
                    const textEl = this.$refs['descText' + id];
                    if (textEl) {
                        if (data.description) {
                            textEl.innerHTML = data.description.split(/\s+/).slice(0, 12).join(' ');
                            if (data.description.split(/\s+/).length > 12) textEl.innerHTML += '…';
                        } else {
                            textEl.innerHTML = '<span class="sbn-text-placeholder">Add info…</span>';
                        }
                    }
                    sbnToast('Description saved', 'success');
                }
            })
            .catch(() => sbnToast('Failed to save', 'error'));
        },

        deleteLeadsheet(id, title) {
            if (!confirm(`Delete "${title}"? This cannot be undone.`)) return;

            fetch(`/api/admin/leadsheets/${id}`, {
                method: 'DELETE',
                headers: {
                    'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content,
                },
            })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    const row = this.$refs['row' + id];
                    if (row) {
                        row.style.transition = 'opacity 0.3s, transform 0.3s';
                        row.style.opacity = '0';
                        row.style.transform = 'translateX(20px)';
                        setTimeout(() => row.remove(), 300);
                    }
                    sbnToast('Leadsheet deleted', 'success');
                }
            })
            .catch(() => sbnToast('Failed to delete', 'error'));
        },
    };
}
</script>
@endpush
