@extends('layouts.admin')

@section('title', $currentTab === 'exercises' ? 'Exercises' : 'Leadsheets')

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
            <span class="sbn-stat-value">{{ $stats['exercises'] }}</span>
            <span class="sbn-stat-label">Exercises</span>
        </div>
        <div class="sbn-stat-card">
            <span class="sbn-stat-value">{{ $stats['composers'] }}</span>
            <span class="sbn-stat-label">Composers</span>
        </div>
        <div class="sbn-stat-card">
            <span class="sbn-stat-value">{{ $stats['keys'] }}</span>
            <span class="sbn-stat-label">Keys</span>
        </div>
    </div>

    {{-- Tab Bar --}}
    <div class="sbn-tabs" style="margin-bottom: 24px;">
        <a href="{{ route('admin.leadsheets.index', array_merge(request()->query(), ['tab' => 'leadsheets'])) }}" 
           class="sbn-tab {{ $currentTab === 'leadsheets' ? 'active' : '' }}">
           Leadsheets ({{ $stats['total'] }})
        </a>
        <a href="{{ route('admin.leadsheets.index', array_merge(request()->query(), ['tab' => 'exercises'])) }}" 
           class="sbn-tab {{ $currentTab === 'exercises' ? 'active' : '' }}">
           Exercises ({{ $stats['exercises'] }})
        </a>
    </div>

    {{-- Style filter pills --}}
    @if($currentTab === 'leadsheets')
    <div class="sbn-prog-cat-pills" style="margin-bottom: 16px;">
        <a href="{{ route('admin.leadsheets.index', array_merge(request()->except('style'), ['tab' => $currentTab])) }}"
           class="sbn-prog-cat-pill {{ !request('style') ? 'is-active' : '' }}">All</a>
        @foreach($styles as $slug)
        @php
            $pillClr = match($slug) {
                'bossa-nova', 'bossa' => 'var(--clr-style-bossa)',
                'jazz'                => 'var(--clr-style-jazz)',
                'classical'           => 'var(--clr-style-classical)',
                'pop'                 => 'var(--clr-style-pop)',
                default               => 'var(--clr-style-bossa)',
            };
            $pillLabel = \App\Models\ChordProgression::CATEGORY_LABELS[$slug] ?? ucfirst($slug);
        @endphp
        <a href="{{ route('admin.leadsheets.index', array_merge(request()->except('style'), ['tab' => $currentTab, 'style' => $slug])) }}"
           class="sbn-prog-cat-pill {{ request('style') === $slug ? 'is-active' : '' }}"
           style="--pill-clr: {{ $pillClr }}">{{ $pillLabel }}</a>
        @endforeach
    </div>
    @endif

    {{-- Filter bar --}}
    <div class="sbn-filter-bar">
        <form method="GET" action="{{ route('admin.leadsheets.index') }}" class="sbn-filter-form">
            <input type="hidden" name="tab" value="{{ $currentTab }}">
            @if(request('style'))<input type="hidden" name="style" value="{{ request('style') }}">@endif
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
            @if(request()->hasAny(['search', 'key', 'composer', 'style']))
                <a href="{{ route('admin.leadsheets.index', ['tab' => $currentTab]) }}" class="sbn-filter-clear">Clear</a>
            @endif
        </form>
    </div>

    @if($items->count())
        <div class="sbn-table-wrap">
            <table class="sbn-table">
                <thead>
                    <tr>
                        <th class="col-title">Title</th>
                        <th class="col-composer">Composer</th>
                        <th class="col-key">Key</th>
                        <th class="col-tempo">BPM</th>
                        <th class="col-time">Time</th>
                        <th class="col-style" style="width:110px;">Style</th>
                        @if($currentTab === 'leadsheets')
                            <th class="col-status">Status</th>
                        @endif
                        <th class="col-cover">Cover image</th>
                        <th class="col-actions"></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($items as $ls)
                    <tr x-ref="row{{ $ls->id }}" class="sbn-ls-row">
                        <td class="col-title">
                            @if($currentTab === 'leadsheets')
                                <a href="{{ route('admin.leadsheets.edit', $ls) }}" class="sbn-ls-title">{{ $ls->title }}</a>
                            @else
                                <a href="{{ route('admin.exercises.edit', $ls) }}" class="sbn-ls-title">{{ $ls->title }}</a>
                            @endif
                        </td>
                        <td class="col-composer sbn-text-dim">{{ $ls->composer ?: '—' }}</td>
                        <td class="col-key">
                            @php $keyVal = ($currentTab === 'leadsheets') ? $ls->song_key : $ls->key_center; @endphp
                            @if($keyVal)
                                <span class="sbn-badge sbn-badge-muted">{{ $keyVal }}</span>
                            @else
                                —
                            @endif
                        </td>
                        <td class="col-tempo sbn-text-muted">{{ ($currentTab === 'leadsheets' ? $ls->tempo : $ls->bpm_default) ?: '—' }}</td>
                        <td class="col-time sbn-text-muted">{{ ($currentTab === 'leadsheets' ? $ls->time_signature : $ls->time_sig) ?: '4/4' }}</td>
                        <td class="col-style">
                            @if($currentTab === 'leadsheets')
                                @php
                                    $styleSlug = $ls->style_slug;
                                    $styleCat = match($styleSlug) {
                                        'bossa-nova', 'bossa' => 'var(--clr-style-bossa)',
                                        'jazz'                => 'var(--clr-style-jazz)',
                                        'classical'           => 'var(--clr-style-classical)',
                                        'pop'                 => 'var(--clr-style-pop)',
                                        default               => 'var(--clr-style-bossa)',
                                    };
                                    $styleLabel = \App\Models\ChordProgression::CATEGORY_LABELS[$styleSlug]
                                        ?? \App\Models\ChordProgression::CATEGORY_LABELS[$ls->genre ?? '']
                                        ?? null;
                                @endphp
                                @if($styleLabel)
                                    <span class="sbn-cat-badge sbn-cat-badge-filled" style="--cat-clr: {{ $styleCat }}">{{ $styleLabel }}</span>
                                @endif
                            @endif
                        </td>
                        @if($currentTab === 'leadsheets')
                        <td class="col-status">
                            <button type="button"
                                x-data="{ status: '{{ $ls->status }}' }"
                                @click="toggleStatus({{ $ls->id }}, status).then(s => { if (s) status = s; })"
                                class="sbn-badge sbn-status-toggle"
                                :class="status === 'publish' ? 'sbn-badge-success' : 'sbn-badge-muted'"
                                :title="status === 'publish' ? 'Published — click to unpublish' : 'Draft — click to publish'">
                                <span x-text="status === 'publish' ? 'Published' : 'Draft'"></span>
                            </button>
                        </td>
                        @endif
                        <td class="col-cover">
                            <div x-data="{ editing: false }">
                                <div x-show="!editing" class="sbn-ls-desc-row">
                                    <span class="sbn-ls-cover-text" x-ref="coverText{{ $ls->id }}">
                                        @if($ls->cover_image_path)
                                            <span title="{{ $ls->cover_image_path }}" style="font-size:0.75em;color:var(--clr-text-dim);">{{ \Illuminate\Support\Str::limit($ls->cover_image_path, 28) }}</span>
                                        @else
                                            <span class="sbn-text-placeholder">No image</span>
                                        @endif
                                    </span>
                                    <button class="sbn-ls-desc-edit" @click="editing = true" title="Set cover image">✎</button>
                                </div>
                                <div x-show="editing" x-cloak>
                                    <input type="text" class="sbn-ls-desc-textarea" style="width:100%;padding:4px 6px;font-size:0.8em;"
                                        x-ref="coverInput{{ $ls->id }}"
                                        x-init="$watch('editing', v => { if (v) $nextTick(() => $refs['coverInput{{ $ls->id }}'].focus()) })"
                                        value="{{ $ls->cover_image_path ?? '' }}"
                                        placeholder="my-song.webp"
                                    >
                                    <div class="sbn-ls-desc-actions">
                                        <button class="sbn-btn sbn-btn-xs sbn-btn-primary"
                                            @click="saveCoverImage({{ $ls->id }}, $refs['coverInput{{ $ls->id }}'].value); editing = false">Save</button>
                                        <button class="sbn-btn sbn-btn-xs" @click="editing = false">Cancel</button>
                                    </div>
                                </div>
                            </div>
                        </td>
                        <td class="col-actions" style="white-space:nowrap;">
                            <button class="sbn-btn sbn-btn-xs sbn-btn-secondary"
                                    title="Edit description"
                                    data-ls-desc='{!! htmlspecialchars($ls->description ?? '', ENT_QUOTES) !!}'
                                    data-ls-meta='{!! htmlspecialchars(json_encode([
                                        'title'    => $ls->title    ?? '',
                                        'composer' => $ls->composer ?? '',
                                        'genre'    => $ls->genre    ?? '',
                                    ]), ENT_QUOTES) !!}'
                                    @click="
                                        const btn = $event.currentTarget;
                                        window.__descEditor.open({ initial: btn.dataset.lsDesc, eventName: 'desc-editor:save:ls-{{ $ls->id }}', placeholder: 'Song description, teaching notes…', entityType: 'leadsheet', entityMeta: JSON.parse(btn.dataset.lsMeta) });
                                        document.addEventListener('desc-editor:save:ls-{{ $ls->id }}', function h(e) {
                                            document.removeEventListener('desc-editor:save:ls-{{ $ls->id }}', h);
                                            saveDescription({{ $ls->id }}, e.detail, '{{ $currentTab }}');
                                        });
                                    ">
                                Desc
                            </button>
                            @if($currentTab === 'leadsheets' && $ls->slug)
                            <a href="{{ route('library.songs.show', $ls->slug) }}" target="_blank"
                               class="sbn-btn sbn-btn-xs sbn-btn-ghost" title="Preview on site">
                                Preview ↗
                            </a>
                            @endif
                            <button class="sbn-btn-delete"
                                @click="deleteItem({{ $ls->id }}, '{{ addslashes($ls->title) }}', '{{ $currentTab }}')"
                                title="Delete">
                                <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M2 4h12M5 4V2h6v2M6 7v5M10 7v5M3 4l1 9h8l1-9"/></svg>
                            </button>
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        @if($items->hasPages())
            <div class="sbn-pagination">
                {{ $items->links() }}
            </div>
        @endif
    @else
        <div class="sbn-empty-state">
            <div class="sbn-empty-icon">🎸</div>
            <h3>No {{ $currentTab }}{{ request()->hasAny(['search', 'key', 'composer']) ? ' matching your filters' : ' yet' }}</h3>
            <p>
                @if(request()->hasAny(['search', 'key', 'composer']))
                    Try adjusting your search or <a href="{{ route('admin.leadsheets.index', ['tab' => $currentTab]) }}">clear filters</a>.
                @else
                    {{ $currentTab === 'leadsheets' ? 'Import a MusicXML file to create your first interactive leadsheet.' : 'Create an exercise from a leadsheet to get started.' }}
                @endif
            </p>
        </div>
    @endif

    @include('admin.leadsheets._blank-modal')
    @include('admin.leadsheets._progression-modal')
    @include('admin.leadsheets._lookup-modal')
</div>

@endsection

@push('scripts')
<div id="desc-editor-root"></div>
@vite('resources/js/admin/description-editor.ts')
<script>
// Lightweight toast — also used by saveDescription / saveCoverImage below.
function sbnToast(message, type) {
    type = type || 'info';
    const toast = document.createElement('div');
    toast.className = 'sbn-toast sbn-toast-' + type;
    toast.textContent = message;
    document.body.appendChild(toast);
    setTimeout(() => {
        toast.style.opacity = '0';
        toast.style.transform = 'translateY(16px)';
        setTimeout(() => toast.remove(), 300);
    }, 3000);
}

function leadsheetIndex() {
    return {
        saveDescription(id, desc, tab) {
            const endpoint = tab === 'exercises' 
                ? `/api/admin/exercises/${id}/description` 
                : `/api/admin/leadsheets/${id}/description`;

            fetch(endpoint, {
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

        saveCoverImage(id, path) {
            fetch(`/api/admin/leadsheets/${id}/cover-image`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content,
                },
                body: JSON.stringify({ cover_image_path: path }),
            })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    const textEl = this.$refs['coverText' + id];
                    if (textEl) {
                        if (data.cover_image_path) {
                            const short = data.cover_image_path.length > 28
                                ? data.cover_image_path.slice(0, 28) + '…'
                                : data.cover_image_path;
                            textEl.innerHTML = `<span title="${data.cover_image_path}" style="font-size:0.75em;color:var(--clr-text-dim);">${short}</span>`;
                        } else {
                            textEl.innerHTML = '<span class="sbn-text-placeholder">No image</span>';
                        }
                    }
                    sbnToast('Cover image saved', 'success');
                }
            })
            .catch(() => sbnToast('Failed to save', 'error'));
        },

        // Flips draft <-> publish. Resolves to the new status, or null on failure.
        toggleStatus(id, current) {
            const next = current === 'publish' ? 'draft' : 'publish';
            return fetch(`/api/admin/leadsheets/${id}/status`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content,
                },
                body: JSON.stringify({ status: next }),
            })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    sbnToast(data.status === 'publish' ? 'Published' : 'Moved to draft', 'success');
                    return data.status;
                }
                sbnToast('Failed to update status', 'error');
                return null;
            })
            .catch(() => { sbnToast('Failed to update status', 'error'); return null; });
        },

        deleteItem(id, title, tab) {
            if (!confirm(`Delete "${title}"? This cannot be undone.`)) return;

            const endpoint = tab === 'exercises'
                ? `/api/admin/exercises/${id}`
                : `/api/admin/leadsheets/${id}`;

            fetch(endpoint, {
                method: 'DELETE',
                headers: {
                    'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content,
                    'Accept': 'application/json',
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
                    sbnToast('Deleted successfully', 'success');
                }
            })
            .catch(() => sbnToast('Failed to delete', 'error'));
        },
    };
}
</script>
@endpush
