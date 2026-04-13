@extends('layouts.admin')

@section('title', 'Rhythm Patterns')

@section('actions')
    <a href="{{ route('admin.rhythms.create') }}" class="sbn-btn sbn-btn-primary">+ New Pattern</a>
@endsection

@push('styles')
    <link rel="stylesheet" href="{{ asset('css/rhythms.css') }}">
@endpush

@section('content')

    @if($patterns->isEmpty())

        <div class="sbn-empty">
            <h3>No rhythm patterns yet</h3>
            <p>Create reusable rhythm patterns for your leadsheets and lessons.</p>
            <a href="{{ route('admin.rhythms.create') }}" class="sbn-btn sbn-btn-primary" style="margin-top: 8px;">
                Create Pattern
            </a>
        </div>

    @else

        {{-- Category-grouped cards --}}
        @foreach($grouped as $category => $catPatterns)

            <div class="rhythm-category-label">
                <span class="cat-dot cat-dot--{{ $category }}"></span>
                {{ ucfirst($category) }}
                <span style="color: var(--clr-text-muted); font-weight: 400; font-size: 11px; margin-left: 4px;">
                    ({{ $catPatterns->count() }})
                </span>
            </div>

            <div class="patterns-grid">
                @foreach($catPatterns as $p)
                    <div class="pattern-card"
                         x-data="{ deleting: false }"
                         id="pattern-{{ $p->id }}">

                        <div class="pattern-card-head">
                            <h3>{{ $p->name }}</h3>
                            <span class="pattern-slug">{{ $p->slug }}</span>
                        </div>

                        <div class="pattern-meta">
                            <span class="sbn-badge sbn-badge-muted">{{ $p->time_signature }}</span>
                            <span class="sbn-badge sbn-badge-muted">{{ $p->default_bpm }} BPM</span>
                            @if($p->grid_type && $p->grid_type !== 'sixteenth')
                                <span class="sbn-badge sbn-badge-accent">{{ $p->grid_type }}</span>
                            @endif
                        </div>

                        {{-- Mini rhythm grid --}}
                        <div class="mini-grid"
                             x-data="miniGrid('{{ $p->rhythm_pattern }}', '{{ $p->thumb_pattern }}', {{ $p->beats }}, '{{ $p->time_signature }}', '{{ $p->grid_type ?? 'sixteenth' }}')"
                             x-html="html">
                        </div>

                        @if($p->description)
                            <p class="pattern-desc">{{ $p->description }}</p>
                        @endif

                        <div class="pattern-card-actions">
                            <a href="{{ route('admin.rhythms.edit', $p) }}" class="sbn-btn sbn-btn-secondary" style="padding: 5px 12px; font-size: 12px;">Edit</a>
                            <button class="sbn-btn sbn-btn-danger" style="padding: 5px 12px; font-size: 12px;"
                                    x-show="!deleting"
                                    @click="if(confirm('Delete this rhythm pattern?')) {
                                        deleting = true;
                                        fetch('{{ route('admin.rhythms.destroy', $p) }}', {
                                            method: 'DELETE',
                                            headers: {
                                                'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content,
                                                'Accept': 'application/json',
                                            }
                                        }).then(r => r.json()).then(d => {
                                            if (d.success) {
                                                document.getElementById('pattern-{{ $p->id }}').remove();
                                                sbnToast('Pattern deleted', 'success');
                                            } else {
                                                deleting = false;
                                                sbnToast('Error deleting', 'error');
                                            }
                                        });
                                    }">
                                Delete
                            </button>
                        </div>

                        <div class="pattern-shortcode">
                            <code>[rhythm pattern="{{ $p->slug }}"]</code>
                            <button class="copy-btn" title="Copy shortcode"
                                    @click="navigator.clipboard.writeText('[rhythm pattern=&quot;{{ $p->slug }}&quot;]'); sbnToast('Copied!', 'success')">
                                📋
                            </button>
                        </div>
                    </div>
                @endforeach
            </div>

        @endforeach

    @endif

@endsection

@push('scripts')
<script>
    // -- Toast helper --
    function sbnToast(message, type) {
        const el = document.createElement('div');
        el.className = `sbn-toast sbn-toast-${type || 'info'}`;
        el.textContent = message;
        document.body.appendChild(el);
        setTimeout(() => { el.style.opacity = '0'; setTimeout(() => el.remove(), 300); }, 3000);
    }

    // -- Mini-grid renderer (Alpine component) --
    function miniGrid(rhythm, thumb, beats, timeSig, gridType) {
        return {
            html: '',
            init() {
                const r = (rhythm || '').split('');
                const t = (thumb || '').split('');
                const hasThumb = t.some(c => c.toLowerCase() === 'x');
                const labels = sbnBeatLabels(beats, timeSig, gridType);

                let h = '';

                h += '<div class="mini-grid-row"><span class="mini-grid-label"></span>';
                for (let i = 0; i < beats; i++)
                    h += `<div class="mini-grid-cell is-beat">${labels[i]}</div>`;
                h += '</div>';

                h += `<div class="mini-grid-row"><span class="mini-grid-label">${hasThumb ? 'Fingers' : 'Rhythm'}</span>`;
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

    // -- Shared beat label generator --
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
            else if (gridType === 'triplet') labels.push(s === 1 ? 'trip' : 'let');
            else if (gridType === 'eighth') labels.push('+');
            else labels.push(['e', '+', 'a'][s - 1] || '');
        }
        return labels;
    }
</script>
@endpush
