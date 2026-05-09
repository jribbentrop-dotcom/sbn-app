@extends('layouts.admin')

@section('title', 'Rhythm Patterns')

@section('actions')
    <a href="{{ route('admin.rhythms.create') }}" class="sbn-btn sbn-btn-primary">+ New Pattern</a>
@endsection

@push('styles')
    <link rel="stylesheet" href="{{ asset('css/rhythms.css') }}">
    <style>
        /* Compact table-specific overrides */
        .sbn-table td { padding: 10px 16px; }
        .mini-grid-compact {
            background: var(--clr-surface-2);
            border-radius: 4px;
            padding: 6px 8px;
            display: inline-block;
            vertical-align: middle;
        }
        .mini-grid-compact .mini-grid-row {
            display: flex;
            gap: 2px;
            margin-bottom: 2px;
        }
        .mini-grid-compact .mini-grid-row:last-child { margin-bottom: 0; }
        .mini-grid-compact .mini-grid-cell {
            width: 14px;
            height: 14px;
            border: 1px solid var(--clr-border);
            border-radius: 2px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 7px;
            background: var(--clr-white);
            color: transparent;
        }
        .mini-grid-compact .mini-grid-cell.is-beat {
            border: none;
            background: transparent;
            color: var(--clr-text-muted);
            font-size: 8px;
        }
        .mini-grid-compact .mini-grid-cell.is-hit {
            background: #fef3f2;
            border-color: var(--clr-red);
            color: var(--clr-red);
        }
        .mini-grid-compact .mini-grid-cell.is-accent {
            background: var(--clr-red);
            border-color: #c0392b;
            color: #fff;
        }
        .mini-grid-compact .mini-grid-label {
            width: 40px;
            font-size: 8px;
            color: var(--clr-text-muted);
            text-align: right;
            padding-right: 6px;
            line-height: 14px;
            flex-shrink: 0;
        }

        .sbn-rhythm-slug {
            font-family: var(--font-mono);
            font-size: 11px;
            color: var(--clr-text-muted);
            background: var(--clr-surface-3);
            padding: 1px 6px;
            border-radius: 4px;
        }



        .sbn-title-link {
            text-decoration: none;
            color: var(--clr-text);
            transition: color 0.15s;
        }
        .sbn-title-link:hover {
            color: var(--clr-accent);
        }
        .sbn-title-link strong {
            font-weight: 600;
            font-size: 14px;
        }

        .col-pattern { width: 300px; }
        .col-shortcode { width: 220px; }

        .sbn-shortcode-cell {
            display: flex;
            align-items: center;
            gap: 6px;
            background: var(--clr-text);
            padding: 4px 10px;
            border-radius: 4px;
            width: fit-content;
        }
        .sbn-shortcode-cell code {
            font-family: var(--font-mono);
            font-size: 10.5px;
            color: #93c5fd;
        }
        .sbn-copy-icon {
            cursor: pointer;
            opacity: 0.6;
            transition: opacity 0.15s;
            color: #93c5fd;
            font-size: 12px;
        }
        .sbn-copy-icon:hover { opacity: 1; }
    </style>
@endpush

@section('content')
<div x-data="rhythmsPage()" x-cloak>

    @if($patterns->isEmpty())

        <div class="sbn-empty">
            <h3>No rhythm patterns yet</h3>
            <p>Create reusable rhythm patterns for your leadsheets and lessons.</p>
            <a href="{{ route('admin.rhythms.create') }}" class="sbn-btn sbn-btn-primary" style="margin-top: 8px;">
                Create Pattern
            </a>
        </div>

    @else

        {{-- Filter bar --}}
        <div class="sbn-filter-bar">
            <div class="sbn-prog-cat-pills">
                <button class="sbn-prog-cat-pill" :class="!filterCat && 'is-active'" @click="filterCat = ''">All</button>
                @foreach($categories as $cat)
                    <button class="sbn-prog-cat-pill" 
                            :class="filterCat === '{{ $cat }}' && 'is-active'"
                            @click="filterCat = '{{ $cat }}'"
                            style="--pill-clr: {{ $cat === 'brazilian' ? 'var(--clr-style-bossa)' : ($cat === 'jazz' ? 'var(--clr-style-jazz)' : ($cat === 'latin' ? 'var(--clr-style-latin)' : 'var(--clr-style-general)')) }}">
                        {{ ucfirst($cat) }}
                    </button>
                @endforeach
            </div>
            <div class="sbn-search-wrap" style="max-width: 260px;">
                <svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M8 4a4 4 0 100 8 4 4 0 000-8zM2 8a6 6 0 1110.89 3.476l4.817 4.817a1 1 0 01-1.414 1.414l-4.816-4.816A6 6 0 012 8z" clip-rule="evenodd"/></svg>
                <input type="text" class="sbn-search-input" placeholder="Search patterns…" x-model="search">
            </div>
        </div>

        <div class="sbn-table-wrap">
            <table class="sbn-table">
                <thead>
                    <tr>
                        <th class="sbn-th-sort" @click="sortBy('name')">
                            Name <span class="sbn-sort-arrow" :class="sortCol === 'name' && (sortAsc ? 'is-asc' : 'is-desc')"></span>
                        </th>
                        <th>Category</th>
                        <th>Grid</th>
                        <th class="col-pattern">Pattern Preview</th>
                        <th style="width: 100px;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <template x-for="p in filtered" :key="p.id">
                        <tr x-data="{ deleting: false }">
                            <td>
                                <a :href="'{{ route('admin.rhythms.index') }}/' + p.id + '/edit'" class="sbn-title-link">
                                    <strong x-text="p.name"></strong>
                                </a>
                            </td>
                            <td>
                                <span class="sbn-cat-badge" 
                                      :style="'--cat-clr:' + getCatColor(p.category)"
                                      x-text="p.category || 'general'"></span>
                            </td>
                            <td class="sbn-text-dim">
                                <span x-text="p.time_signature"></span><br>
                                <span x-text="p.grid_type"></span>
                            </td>
                            <td class="col-pattern">
                                <div class="mini-grid-compact"
                                     x-data="miniGridCompact(p.rhythm_pattern, p.thumb_pattern, p.beats, p.time_signature, p.grid_type)"
                                     x-html="html">
                                </div>
                            </td>

                            <td>
                                <div style="display: flex; gap: 6px;">
                                    <a :href="'{{ route('admin.rhythms.index') }}/' + p.id + '/edit'" class="sbn-btn-sm" title="Edit">✎</a>
                                    <button class="sbn-btn-sm sbn-btn-sm-danger" 
                                            @click="if(confirm('Delete pattern?')) deletePattern(p.id, $el)"
                                            title="Delete">×</button>
                                </div>
                            </td>
                        </tr>
                    </template>
                </tbody>
            </table>
        </div>

    @endif

</div>
@endsection

@push('scripts')
<script>
    function rhythmsPage() {
        const patterns = @json($patterns);
        
        return {
            patterns: patterns,
            search: '',
            filterCat: '',
            sortCol: 'name',
            sortAsc: true,

            get filtered() {
                let list = this.patterns;
                
                if (this.filterCat) {
                    list = list.filter(p => p.category === this.filterCat);
                }
                
                if (this.search) {
                    const q = this.search.toLowerCase();
                    list = list.filter(p => p.name.toLowerCase().includes(q) || p.slug.toLowerCase().includes(q));
                }
                
                return list.sort((a, b) => {
                    let va = a[this.sortCol] || '';
                    let vb = b[this.sortCol] || '';
                    if (typeof va === 'string') {
                        va = va.toLowerCase();
                        vb = vb.toLowerCase();
                    }
                    if (va < vb) return this.sortAsc ? -1 : 1;
                    if (va > vb) return this.sortAsc ? 1 : -1;
                    return 0;
                });
            },

            sortBy(col) {
                if (this.sortCol === col) this.sortAsc = !this.sortAsc;
                else { this.sortCol = col; this.sortAsc = true; }
            },

            getCatColor(cat) {
                const colors = {
                    'brazilian': 'var(--clr-style-bossa)',
                    'jazz':      'var(--clr-style-jazz)',
                    'latin':     'var(--clr-style-latin)',
                    'blues':     'var(--clr-style-blues)',
                    'general':   'var(--clr-text-muted)'
                };
                return colors[cat] || colors.general;
            }
        };
    }

    async function deletePattern(id, btn) {
        const csrf = document.querySelector('meta[name=csrf-token]').content;
        const resp = await fetch(`/admin/rhythms/${id}`, {
            method: 'DELETE',
            headers: {
                'X-CSRF-TOKEN': csrf,
                'Accept': 'application/json',
            }
        });
        const data = await resp.json();
        if (data.success) {
            btn.closest('tr').remove();
            sbnToast('Pattern deleted', 'success');
        }
    }

    function miniGridCompact(rhythm, thumb, beats, timeSig, gridType) {
        return {
            html: '',
            init() {
                const r = (rhythm || '').split('');
                const t = (thumb || '').split('');
                const hasThumb = t.some(c => c.toLowerCase() === 'x');
                const labels = sbnBeatLabels(beats, timeSig, gridType);

                let h = '';

                h += '<div class="mini-grid-row"><span class="mini-grid-label">Beat</span>';
                for (let i = 0; i < beats; i++)
                    h += `<div class="mini-grid-cell is-beat">${labels[i]}</div>`;
                h += '</div>';

                h += `<div class="mini-grid-row"><span class="mini-grid-label">Pattern</span>`;
                for (let i = 0; i < beats; i++) {
                    const c = r[i] || '.';
                    let cls = 'mini-grid-cell';
                    if (c.toLowerCase() === 'x') cls += ' is-hit';
                    if (c === 'X') cls += ' is-accent';
                    h += `<div class="${cls}">${c.toLowerCase() === 'x' ? '●' : ''}</div>`;
                }
                h += '</div>';

                if (hasThumb) {
                    h += '<div class="mini-grid-row"><span class="mini-grid-label">Thumb</span>';
                    for (let i = 0; i < beats; i++) {
                        const c = t[i] || '.';
                        let cls = 'mini-grid-cell is-thumb';
                        if (c.toLowerCase() === 'x') cls += ' is-hit';
                        h += `<div class="${cls}">${c.toLowerCase() === 'x' ? '●' : ''}</div>`;
                    }
                    h += '</div>';
                }

                this.html = h;
            }
        };
    }

    function sbnBeatLabels(beats, timeSig, gridType) {
        const labels = [];
        const bpb = parseInt((timeSig || '4/4').split('/')[0]) || 4;
        const sub = gridType === 'eighth' ? 2 : gridType === 'triplet' ? 3 : 4;
        const cpb = bpb * sub;

        for (let i = 0; i < beats; i++) {
            const pos = i % cpb;
            const beat = Math.floor(pos / sub) + 1;
            const s = pos % sub;
            if (s === 0) labels.push(String(beat));
            else if (gridType === 'triplet') labels.push(s === 1 ? 't' : 'l');
            else if (gridType === 'eighth') labels.push('+');
            else labels.push(['e', '+', 'a'][s - 1] || '');
        }
        return labels;
    }

    function sbnToast(message, type) {
        const el = document.createElement('div');
        el.className = `sbn-toast sbn-toast-${type || 'info'}`;
        el.textContent = message;
        document.body.appendChild(el);
        setTimeout(() => { el.style.opacity = '0'; setTimeout(() => el.remove(), 300); }, 3000);
    }
</script>
@endpush

