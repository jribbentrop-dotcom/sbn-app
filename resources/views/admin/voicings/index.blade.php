@extends('layouts.admin')

@section('title', 'Voicing Crossref')

@section('actions')
    <button class="sbn-btn sbn-btn-secondary" id="sbnReprocessAll">
        <svg viewBox="0 0 20 20" fill="currentColor" style="width:16px;height:16px;vertical-align:-2px;margin-right:4px;">
            <path fill-rule="evenodd" d="M4 2a1 1 0 011 1v2.101a7.002 7.002 0 0111.601 2.566 1 1 0 11-1.885.666A5.002 5.002 0 005.999 7H9a1 1 0 010 2H4a1 1 0 01-1-1V3a1 1 0 011-1zm.008 9.057a1 1 0 011.276.61A5.002 5.002 0 0014.001 13H11a1 1 0 110-2h5a1 1 0 011 1v5a1 1 0 11-2 0v-2.101a7.002 7.002 0 01-11.601-2.566 1 1 0 01.61-1.276z" clip-rule="evenodd"/>
        </svg>
        Reprocess All
    </button>
@endsection

@section('content')

{{-- ============================================================
     STATS ROW
     ============================================================ --}}
<div class="sbn-stats-grid sbn-voicing-stats">
    <div class="sbn-stat-card">
        <div class="sbn-stat-icon" style="--accent: var(--clr-mod-voicing)">
            <svg viewBox="0 0 20 20" fill="currentColor"><path d="M11 17a1 1 0 001.447.894l4-2A1 1 0 0017 15V9.236a1 1 0 00-1.447-.894l-4 2a1 1 0 00-.553.894V17z"/><path d="M15.211 6.276a1 1 0 000-1.788l-4.764-2.382a1 1 0 00-.894 0L4.789 4.488a1 1 0 000 1.788l4.764 2.382a1 1 0 00.894 0l4.764-2.382z"/></svg>
        </div>
        <div class="sbn-stat-info">
            <span class="sbn-stat-number">{{ number_format($stats['total_matches']) }}</span>
            <span class="sbn-stat-label">Matched Voicings</span>
        </div>
    </div>
    <div class="sbn-stat-card">
        <div class="sbn-stat-icon" style="--accent: var(--clr-mod-chord)">
            <svg viewBox="0 0 20 20" fill="currentColor"><path d="M9 2a1 1 0 000 2h2a1 1 0 100-2H9z"/><path fill-rule="evenodd" d="M4 5a2 2 0 012-2 3 3 0 003 3h2a3 3 0 003-3 2 2 0 012 2v11a2 2 0 01-2 2H6a2 2 0 01-2-2V5z" clip-rule="evenodd"/></svg>
        </div>
        <div class="sbn-stat-info">
            <span class="sbn-stat-number">{{ $stats['leadsheets_matched'] }}<span class="sbn-stat-of">/{{ $stats['total_leadsheets'] }}</span></span>
            <span class="sbn-stat-label">Leadsheets Processed</span>
        </div>
    </div>
    <div class="sbn-stat-card">
        <div class="sbn-stat-icon" style="--accent: var(--clr-success)">
            <svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M6.267 3.455a3.066 3.066 0 001.745-.723 3.066 3.066 0 013.976 0 3.066 3.066 0 001.745.723 3.066 3.066 0 012.812 2.812c.051.643.304 1.254.723 1.745a3.066 3.066 0 010 3.976 3.066 3.066 0 00-.723 1.745 3.066 3.066 0 01-2.812 2.812 3.066 3.066 0 00-1.745.723 3.066 3.066 0 01-3.976 0 3.066 3.066 0 00-1.745-.723 3.066 3.066 0 01-2.812-2.812 3.066 3.066 0 00-.723-1.745 3.066 3.066 0 010-3.976 3.066 3.066 0 00.723-1.745 3.066 3.066 0 012.812-2.812zm7.44 5.252a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/></svg>
        </div>
        <div class="sbn-stat-info">
            <span class="sbn-stat-number">{{ $stats['diagrams_with_match'] }}<span class="sbn-stat-of">/{{ $stats['total_diagrams'] }}</span></span>
            <span class="sbn-stat-label">Diagrams Used</span>
        </div>
    </div>
    <div class="sbn-stat-card {{ $stats['pending_drafts'] > 0 ? 'sbn-stat-highlight' : '' }}">
        <div class="sbn-stat-icon" style="--accent: var(--clr-warning)">
            <svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z" clip-rule="evenodd"/></svg>
        </div>
        <div class="sbn-stat-info">
            <span class="sbn-stat-number">{{ number_format($stats['pending_drafts']) }}</span>
            <span class="sbn-stat-label">Pending Review</span>
        </div>
    </div>
</div>

{{-- ============================================================
     MOST POPULAR VOICINGS
     ============================================================ --}}
@if($popularVoicings->isNotEmpty())
<div class="sbn-voicing-popular" x-data="{ open: false }">
    <button class="sbn-section-toggle" @click="open = !open">
        <svg viewBox="0 0 20 20" fill="currentColor" class="sbn-chevron" :class="{ 'sbn-chevron-open': open }">
            <path fill-rule="evenodd" d="M7.293 14.707a1 1 0 010-1.414L10.586 10 7.293 6.707a1 1 0 011.414-1.414l4 4a1 1 0 010 1.414l-4 4a1 1 0 01-1.414 0z" clip-rule="evenodd"/>
        </svg>
        <span class="sbn-section-title">Most Popular Voicings</span>
        <span class="sbn-count-badge">{{ $popularVoicings->count() }}</span>
    </button>

    <div class="sbn-popular-table-wrap" x-show="open" x-collapse>
        <table class="sbn-table">
            <thead>
                <tr>
                    <th>Voicing</th>
                    <th>Quality</th>
                    <th>Type</th>
                    <th>Root String</th>
                    <th>Used In</th>
                </tr>
            </thead>
            <tbody>
                @foreach($popularVoicings as $v)
                <tr>
                    <td>
                        <a href="{{ route('admin.chords.edit', $v->id) }}" class="sbn-link-name">{{ $v->name }}</a>
                    </td>
                    <td><span class="sbn-badge sbn-badge-muted">{{ $v->quality_label }}</span></td>
                    <td>{{ $v->category_label }}</td>
                    <td>{{ $v->root_string_label }}</td>
                    <td><strong>{{ $v->popularity }}</strong> song{{ $v->popularity !== 1 ? 's' : '' }}</td>
                </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</div>
@endif

{{-- ============================================================
     PENDING DRAFTS (Unmatched Voicings)
     ============================================================ --}}
<div class="sbn-voicing-drafts" x-data="draftManager()">

    <div class="sbn-drafts-header">
        <h3 class="sbn-drafts-title">
            Unmatched Voicings
            @if($stats['pending_drafts'] > 0)
                <span class="sbn-count-badge sbn-count-badge-warning">{{ $stats['pending_drafts'] }}</span>
            @endif
        </h3>
        @if($groupedDrafts->isNotEmpty())
        <button class="sbn-btn sbn-btn-danger sbn-btn-sm" @click="clearAll()" x-show="!clearing">
            <svg viewBox="0 0 20 20" fill="currentColor" style="width:14px;height:14px;vertical-align:-2px;margin-right:3px;">
                <path fill-rule="evenodd" d="M9 2a1 1 0 00-.894.553L7.382 4H4a1 1 0 000 2v10a2 2 0 002 2h8a2 2 0 002-2V6a1 1 0 100-2h-3.382l-.724-1.447A1 1 0 0011 2H9zM7 8a1 1 0 012 0v6a1 1 0 11-2 0V8zm5-1a1 1 0 00-1 1v6a1 1 0 102 0V8a1 1 0 00-1-1z" clip-rule="evenodd"/>
            </svg>
            Clear All
        </button>
        <span class="sbn-btn sbn-btn-danger sbn-btn-sm" x-show="clearing" style="opacity:.6">Clearing…</span>
        @endif
    </div>

    @if($groupedDrafts->isEmpty())
        <div class="sbn-empty">
            <div class="sbn-empty-icon">✅</div>
            <h3>All voicings matched!</h3>
            <p>Every voicing in your leadsheets has been matched to a chord diagram in your library.</p>
        </div>
    @else
        <p class="sbn-drafts-intro-text">
            These voicings from your leadsheets don't match any chord diagram in your library.
            You can <strong>add them</strong> to the chord diagram library or <strong>dismiss</strong> them.
        </p>

        @foreach($groupedDrafts as $leadsheetId => $group)
        <div class="sbn-draft-group" data-leadsheet="{{ $leadsheetId }}">
            <h4 class="sbn-draft-group-title">
                {{ $group['title'] }}
                <span class="sbn-draft-count" x-text="countRemaining({{ $leadsheetId }})">{{ $group['drafts']->count() }} unmatched</span>
            </h4>

            <div class="sbn-draft-cards">
                @foreach($group['drafts'] as $draft)
                <div class="sbn-draft-card"
                     x-ref="draft{{ $draft->id }}"
                     x-show="!dismissed.includes({{ $draft->id }})"
                     x-transition:leave="transition ease-in duration-200"
                     x-transition:leave-start="opacity-100 scale-100"
                     x-transition:leave-end="opacity-0 scale-95">

                    <div class="sbn-draft-card-header">
                        <span class="sbn-draft-chord-name">{{ $draft->chord_name }}</span>
                        <code class="sbn-draft-frets">{{ $draft->fret_string }}{{ $draft->position > 1 ? ' @'.$draft->position : '' }}</code>
                    </div>

                    <div class="sbn-draft-diagram"
                         data-diagram="{{ json_encode($draft->toDiagramData()) }}"
                         data-start-fret="{{ max(1, $draft->position ?? 1) }}">
                    </div>

                    @if($draft->root_note && $draft->quality)
                    <div class="sbn-draft-meta">
                        Root: {{ $draft->root_note }} · Quality: {{ $draft->quality }}
                        @if($draft->bass_note) · Bass: {{ $draft->bass_note }} @endif
                    </div>
                    @endif

                    <div class="sbn-draft-actions">
                        <button class="sbn-btn sbn-btn-primary sbn-btn-sm"
                                @click="promote({{ $draft->id }})"
                                :disabled="promoting === {{ $draft->id }}">
                            <span x-show="promoting !== {{ $draft->id }}">Add to Library</span>
                            <span x-show="promoting === {{ $draft->id }}">Adding…</span>
                        </button>
                        <button class="sbn-btn sbn-btn-secondary sbn-btn-sm"
                                @click="dismissDraft({{ $draft->id }})"
                                :disabled="dismissing === {{ $draft->id }}">
                            <span x-show="dismissing !== {{ $draft->id }}">Dismiss</span>
                            <span x-show="dismissing === {{ $draft->id }}">…</span>
                        </button>
                    </div>
                </div>
                @endforeach
            </div>
        </div>
        @endforeach
    @endif
</div>

@endsection

@push('styles')
<link rel="stylesheet" href="{{ asset('css/voicings.css') }}">
@endpush

@push('scripts')
<script>
// =============================================
// DRAFT MANAGER (Alpine component)
// =============================================
function draftManager() {
    return {
        dismissed: [],
        promoting: null,
        dismissing: null,
        clearing: false,

        csrfToken: document.querySelector('meta[name="csrf-token"]').content,

        async dismissDraft(id) {
            this.dismissing = id;
            try {
                const res = await fetch(`/api/admin/voicings/${id}/dismiss`, {
                    method: 'POST',
                    headers: { 'X-CSRF-TOKEN': this.csrfToken, 'Accept': 'application/json' },
                });
                const data = await res.json();
                if (data.success) {
                    this.dismissed.push(id);
                    sbnToast('Draft dismissed', 'success');
                } else {
                    sbnToast(data.error || 'Error dismissing draft', 'error');
                }
            } catch (e) {
                sbnToast('Request failed', 'error');
            }
            this.dismissing = null;
        },

        async promote(id) {
            this.promoting = id;
            try {
                const res = await fetch(`/api/admin/voicings/${id}/promote`, {
                    method: 'POST',
                    headers: { 'X-CSRF-TOKEN': this.csrfToken, 'Accept': 'application/json' },
                });
                const data = await res.json();
                if (data.success && data.edit_url) {
                    window.location.href = data.edit_url;
                } else {
                    sbnToast(data.error || 'Error promoting draft', 'error');
                    this.promoting = null;
                }
            } catch (e) {
                sbnToast('Request failed', 'error');
                this.promoting = null;
            }
        },

        async clearAll() {
            const count = document.querySelectorAll('.sbn-draft-card').length;
            if (!confirm(`Delete all ${count} unmatched voicings? This cannot be undone.`)) return;

            this.clearing = true;
            try {
                const res = await fetch('/api/admin/voicings/clear-all', {
                    method: 'POST',
                    headers: { 'X-CSRF-TOKEN': this.csrfToken, 'Accept': 'application/json' },
                });
                const data = await res.json();
                if (data.success) {
                    window.location.reload();
                } else {
                    sbnToast('Error clearing drafts', 'error');
                    this.clearing = false;
                }
            } catch (e) {
                sbnToast('Request failed', 'error');
                this.clearing = false;
            }
        },

        countRemaining(leadsheetId) {
            const group = document.querySelector(`.sbn-draft-group[data-leadsheet="${leadsheetId}"]`);
            if (!group) return '';
            const total = group.querySelectorAll('.sbn-draft-card').length;
            const hidden = this.dismissed.filter(id => {
                const el = this.$refs['draft' + id];
                return el && el.closest('.sbn-draft-group')?.dataset.leadsheet == leadsheetId;
            }).length;
            const remaining = total - hidden;
            return remaining + ' unmatched';
        },
    };
}

// =============================================
// REPROCESS BUTTON
// =============================================
document.getElementById('sbnReprocessAll')?.addEventListener('click', async function() {
    const btn = this;
    btn.disabled = true;
    btn.textContent = 'Processing…';

    try {
        const res = await fetch('/api/admin/voicings/reprocess', {
            method: 'POST',
            headers: {
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                'Accept': 'application/json',
            },
        });
        const data = await res.json();
        if (data.success) {
            sbnToast('Reprocessing complete! Reloading…', 'success');
            setTimeout(() => window.location.reload(), 1500);
        } else {
            sbnToast(data.message || 'Reprocessing not yet available', 'warning');
        }
    } catch (e) {
        sbnToast('Request failed', 'error');
    }

    btn.disabled = false;
    btn.innerHTML = '<svg viewBox="0 0 20 20" fill="currentColor" style="width:16px;height:16px;vertical-align:-2px;margin-right:4px;"><path fill-rule="evenodd" d="M4 2a1 1 0 011 1v2.101a7.002 7.002 0 0111.601 2.566 1 1 0 11-1.885.666A5.002 5.002 0 005.999 7H9a1 1 0 010 2H4a1 1 0 01-1-1V3a1 1 0 011-1zm.008 9.057a1 1 0 011.276.61A5.002 5.002 0 0014.001 13H11a1 1 0 110-2h5a1 1 0 011 1v5a1 1 0 11-2 0v-2.101a7.002 7.002 0 01-11.601-2.566 1 1 0 01.61-1.276z" clip-rule="evenodd"/></svg> Reprocess All';
});

// =============================================
// TOAST HELPER (if not already defined)
// =============================================
if (typeof sbnToast === 'undefined') {
    window.sbnToast = function(msg, type) {
        const toast = document.createElement('div');
        toast.className = 'sbn-flash sbn-flash-' + (type || 'success');
        toast.textContent = msg;
        toast.style.cssText = 'position:fixed;top:16px;right:16px;z-index:9999;padding:12px 20px;border-radius:8px;font-size:14px;font-weight:500;box-shadow:0 4px 12px rgba(0,0,0,.12);animation:sbnToastIn .3s ease;';
        document.body.appendChild(toast);
        setTimeout(() => { toast.style.opacity = '0'; toast.style.transition = 'opacity .3s'; }, 3000);
        setTimeout(() => toast.remove(), 3400);
    };
}

// =============================================
// MINI FRETBOARD RENDERER (same as chords module)
// =============================================
(function() {
    'use strict';

    function renderMiniFretboard(container) {
        const diagramData = JSON.parse(container.dataset.diagram || '{}');
        const startFret = parseInt(container.dataset.startFret) || 1;

        const positions = diagramData.positions || [];
        const barres    = diagramData.barres || [];
        const muted     = (diagramData.muted || []).map(Number);
        const open      = (diagramData.open || []).map(Number);

        const FRETS_SHOWN = 4;
        const STRING_COUNT = 6;

        // Dimensions
        const marginTop    = 22;
        const marginBottom = 6;
        const marginLeft   = 20;
        const marginRight  = 12;
        const fretH        = 22;
        const stringSpacing = 16;
        const nutH         = 3;

        const fretboardW = stringSpacing * (STRING_COUNT - 1);
        const fretboardH = fretH * FRETS_SHOWN;
        const totalW     = marginLeft + fretboardW + marginRight;
        const totalH     = marginTop + fretboardH + marginBottom;

        const showNut = (startFret === 1);

        const svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
        svg.setAttribute('viewBox', `0 0 ${totalW} ${totalH}`);
        svg.setAttribute('width', totalW);
        svg.setAttribute('height', totalH);
        svg.style.display = 'block';

        // String thickness (low to high)
        const stringWidths = [1.6, 1.3, 1.1, 0.9, 0.7, 0.6];

        // Nut or fret number
        if (showNut) {
            const nut = document.createElementNS('http://www.w3.org/2000/svg', 'rect');
            nut.setAttribute('x', marginLeft - 1);
            nut.setAttribute('y', marginTop - nutH);
            nut.setAttribute('width', fretboardW + 2);
            nut.setAttribute('height', nutH);
            nut.setAttribute('fill', '#2c3e50');
            nut.setAttribute('rx', 1);
            svg.appendChild(nut);
        } else {
            const label = document.createElementNS('http://www.w3.org/2000/svg', 'text');
            label.setAttribute('x', marginLeft - 6);
            label.setAttribute('y', marginTop + fretH / 2 + 4);
            label.setAttribute('text-anchor', 'end');
            label.setAttribute('font-size', '9');
            label.setAttribute('fill', '#8896a4');
            label.setAttribute('font-family', 'DM Sans, sans-serif');
            label.textContent = startFret + 'fr';
            svg.appendChild(label);
        }

        // Fret lines
        for (let f = 0; f <= FRETS_SHOWN; f++) {
            const y = marginTop + f * fretH;
            const line = document.createElementNS('http://www.w3.org/2000/svg', 'line');
            line.setAttribute('x1', marginLeft);
            line.setAttribute('y1', y);
            line.setAttribute('x2', marginLeft + fretboardW);
            line.setAttribute('y2', y);
            line.setAttribute('stroke', '#cbd5e0');
            line.setAttribute('stroke-width', f === 0 && !showNut ? 1.5 : 0.8);
            svg.appendChild(line);
        }

        // Strings
        for (let s = 0; s < STRING_COUNT; s++) {
            const x = marginLeft + s * stringSpacing;
            const line = document.createElementNS('http://www.w3.org/2000/svg', 'line');
            line.setAttribute('x1', x);
            line.setAttribute('y1', marginTop);
            line.setAttribute('x2', x);
            line.setAttribute('y2', marginTop + fretboardH);
            line.setAttribute('stroke', '#94a3b8');
            line.setAttribute('stroke-width', stringWidths[s]);
            svg.appendChild(line);
        }

        // String indicators (muted / open)
        for (let s = 1; s <= STRING_COUNT; s++) {
            const x = marginLeft + (s - 1) * stringSpacing;
            const y = marginTop - 10;

            if (muted.includes(s)) {
                const txt = document.createElementNS('http://www.w3.org/2000/svg', 'text');
                txt.setAttribute('x', x);
                txt.setAttribute('y', y);
                txt.setAttribute('text-anchor', 'middle');
                txt.setAttribute('font-size', '10');
                txt.setAttribute('fill', '#a0aec0');
                txt.setAttribute('font-family', 'DM Sans, sans-serif');
                txt.textContent = '×';
                svg.appendChild(txt);
            } else if (open.includes(s)) {
                const circ = document.createElementNS('http://www.w3.org/2000/svg', 'circle');
                circ.setAttribute('cx', x);
                circ.setAttribute('cy', y - 3);
                circ.setAttribute('r', 3.5);
                circ.setAttribute('fill', 'none');
                circ.setAttribute('stroke', '#718096');
                circ.setAttribute('stroke-width', 1);
                svg.appendChild(circ);
            }
        }

        // Barres
        barres.forEach(function(barre) {
            const fromX = marginLeft + (barre.from - 1) * stringSpacing;
            const toX   = marginLeft + (barre.to - 1) * stringSpacing;
            const y     = marginTop + (barre.fret - startFret) * fretH + fretH / 2;

            const rect = document.createElementNS('http://www.w3.org/2000/svg', 'rect');
            rect.setAttribute('x', fromX - 4);
            rect.setAttribute('y', y - 4);
            rect.setAttribute('width', toX - fromX + 8);
            rect.setAttribute('height', 8);
            rect.setAttribute('rx', 4);
            rect.setAttribute('fill', '#2c3e50');
            svg.appendChild(rect);
        });

        // Finger dots
        const fingerColors = {
            1: '#2c3e50', 2: '#e74c3c', 3: '#f39c12', 4: '#3b82f6',
        };

        positions.forEach(function(pos) {
            const s    = pos.string;
            const fret = pos.fret;
            const x    = marginLeft + (s - 1) * stringSpacing;
            const y    = marginTop + (fret - startFret) * fretH + fretH / 2;
            const r    = 5;

            const color = fingerColors[pos.finger] || '#2c3e50';

            const circ = document.createElementNS('http://www.w3.org/2000/svg', 'circle');
            circ.setAttribute('cx', x);
            circ.setAttribute('cy', y);
            circ.setAttribute('r', r);
            circ.setAttribute('fill', color);
            svg.appendChild(circ);
        });

        container.innerHTML = '';
        container.appendChild(svg);
    }

    // Render all draft diagrams on load
    document.addEventListener('DOMContentLoaded', function() {
        document.querySelectorAll('.sbn-draft-diagram[data-diagram]').forEach(renderMiniFretboard);
    });
})();
</script>
@endpush
