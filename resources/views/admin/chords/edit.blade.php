@extends('layouts.admin')

@section('title', $isNew ? 'New Chord Shape' : 'Edit Chord Shape')

@section('actions')
    <a href="{{ route('admin.chords.index') }}" class="sbn-btn sbn-btn-secondary">← Back to Library</a>
    @if(!$isNew)
        <a href="{{ route('library.chords.show', $chord->slug) }}" target="_blank" class="sbn-btn sbn-btn-ghost">Preview ↗</a>
    @endif
@endsection

@push('styles')
    <link rel="stylesheet" href="{{ asset('css/chords.css') }}">
@endpush

@section('content')
<form
    method="POST"
    action="{{ $isNew ? route('admin.chords.store') : route('admin.chords.update', $chord) }}"
    x-data="chordEditor()"
    x-init="init()"
    @submit.prevent="submitForm($el)"
>
    @csrf
    @unless($isNew) @method('PUT') @endunless

    @if($errors->any())
        <div class="sbn-flash" style="margin: 0 0 20px; background: rgba(239,68,68,0.08); border: 1px solid rgba(239,68,68,0.2); color: var(--clr-error);">
            <ul style="margin:0; padding-left: 18px;">
                @foreach($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <div class="sbn-chord-edit-layout">

        {{-- ============================================================
             SECTION 1: Chord Properties
             ============================================================ --}}
        <div class="sbn-edit-section">
            <div class="sbn-edit-section-header">
                <h2>Chord Properties</h2>
                <p>Define the chord identity. The shape slug and name auto-generate from these fields.</p>
            </div>

            <div class="sbn-edit-grid sbn-edit-grid-4">
                <div class="sbn-edit-field">
                    <label for="root_note">Root Note</label>
                    <select name="root_note" id="root_note" x-model="fields.root_note" @change="onFieldChange()">
                        @foreach($rootNotes as $n)
                            <option value="{{ $n }}" @selected(old('root_note', $chord->root_note ?? 'C') === $n)>{{ $n }}</option>
                        @endforeach
                    </select>
                    <span class="sbn-edit-hint">Stored position</span>
                </div>

                <div class="sbn-edit-field">
                    <label for="quality">Chord Quality</label>
                    <select name="quality" id="quality" x-model="fields.quality" @change="onFieldChange()">
                        @foreach($chordQualities as $key => $label)
                            <option value="{{ $key }}" @selected(old('quality', $chord->quality ?? 'maj7') === $key)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>

                <div class="sbn-edit-field">
                    <label for="extensions_field">Extensions</label>
                    <input type="text" name="extensions" id="extensions_field" x-model="fields.extensions"
                           @input="onFieldChange()" placeholder="e.g. 9, b9, #11"
                           value="{{ old('extensions', $chord->extensions ?? '') }}">
                </div>

                <div class="sbn-edit-field">
                    <label for="voicing_category">Voicing Type</label>
                    <select name="voicing_category" id="voicing_category" x-model="fields.voicing_category" @change="onFieldChange()">
                        @foreach($voicingCategories as $key => $label)
                            <option value="{{ $key }}" @selected(old('voicing_category', $chord->voicing_category ?? 'drop2') === $key)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
            </div>

            <div class="sbn-edit-grid sbn-edit-grid-4">
                <div class="sbn-edit-field">
                    <label for="root_string">Root String</label>
                    <select name="root_string" id="root_string" x-model="fields.root_string" @change="onFieldChange()">
                        @foreach($rootStrings as $key => $label)
                            <option value="{{ $key }}" @selected(old('root_string', $chord->root_string ?? 'roota') === $key)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>

                <div class="sbn-edit-field">
                    <label for="inversion">Inversion</label>
                    <select name="inversion" id="inversion" x-model="fields.inversion" @change="onFieldChange()">
                        @foreach($inversions as $key => $label)
                            <option value="{{ $key }}" @selected(old('inversion', $chord->inversion ?? 'root') === $key)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>

                <div class="sbn-edit-field">
                    <label for="bass_note">Slash Bass Note</label>
                    <select name="bass_note" id="bass_note" x-model="fields.bass_note" @change="onFieldChange()">
                        <option value="">— none —</option>
                        @foreach($rootNotes as $n)
                            <option value="{{ $n }}" @selected(old('bass_note', $chord->bass_note ?? '') === $n)>{{ $n }}</option>
                        @endforeach
                    </select>
                    <span class="sbn-edit-hint">For slash chords only</span>
                </div>

                <div class="sbn-edit-field">
                    <label for="shape_family">Shape Family</label>
                    <input type="text" name="shape_family" id="shape_family"
                           value="{{ old('shape_family', $chord->shape_family ?? '') }}"
                           placeholder="e.g. archetype-e">
                    <span class="sbn-edit-hint">Groups related shapes</span>
                </div>
            </div>

            <div class="sbn-edit-grid sbn-edit-grid-2">
                <div class="sbn-edit-field"
                     x-data="{ descHtml: {{ Js::from(old('description', $chord->description ?? '')) }} }"
                     x-init="document.addEventListener('desc-editor:save:chord', (e) => { descHtml = e.detail; })">
                    <label>Description</label>
                    <input type="hidden" name="description" :value="descHtml">
                    <div class="sbn-desc-preview" x-html="descHtml || '<span style=\'color:var(--clr-text-muted);font-style:italic\'>No description yet…</span>'"></div>
                    <button type="button" class="sbn-btn sbn-btn-secondary" style="margin-top:8px;font-size:12px;"
                            data-chord-meta='{!! htmlspecialchars(json_encode([
                                'name'    => $chord->name             ?? '',
                                'quality' => $chord->quality_label    ?? $chord->quality          ?? '',
                                'voicing' => $chord->category_label   ?? $chord->voicing_category ?? '',
                                'style'   => $chord->shape_family     ?? '',
                            ]), ENT_QUOTES) !!}'
                            @click="window.__descEditor.open({ initial: descHtml, eventName: 'desc-editor:save:chord', placeholder: 'Notes about this voicing…', entityType: 'chord', entityMeta: JSON.parse($el.dataset.chordMeta) })">
                        Edit Description
                    </button>
                </div>
                <div class="sbn-edit-field" style="display:flex; align-items:end; gap:16px;">
                    <label class="sbn-edit-checkbox">
                        <input type="hidden" name="is_fixed_position" value="0">
                        <input type="checkbox" name="is_fixed_position" value="1"
                               @checked(old('is_fixed_position', $chord->is_fixed_position ?? false))>
                        <span>Fixed position (not transposable)</span>
                    </label>
                </div>
            </div>
        </div>

        {{-- ============================================================
             SECTION 2: Fretboard Editor
             ============================================================ --}}
        <div class="sbn-edit-section">
            <div class="sbn-edit-section-header">
                <h2>Fretboard</h2>
                <p>Click to place/remove fingers. Click string indicators to cycle muted (×) → open (○) → normal. Right-click a dot to change finger number.</p>
            </div>

            <div class="sbn-edit-fretboard-controls">
                <div class="sbn-edit-field" style="width:120px;">
                    <label for="start_fret">Start Fret</label>
                    <input type="number" name="start_fret" id="start_fret"
                           x-model.number="startFret" @change="render()"
                           min="1" max="24" value="{{ old('start_fret', $chord->start_fret ?? 1) }}">
                </div>
                <button type="button" class="sbn-btn sbn-btn-secondary" @click="clearDiagram()" style="align-self:end;">
                    Clear All
                </button>
                <div class="sbn-finger-legend-inline">
                    <span class="sbn-finger-pill" style="--fc:#3b82f6;">1 Index</span>
                    <span class="sbn-finger-pill" style="--fc:#10b981;">2 Middle</span>
                    <span class="sbn-finger-pill" style="--fc:#f39c12;">3 Ring</span>
                    <span class="sbn-finger-pill" style="--fc:#ef4444;">4 Pinky</span>
                    <span class="sbn-finger-pill" style="--fc:#8b5cf6;">T Thumb</span>
                </div>
            </div>

            {{-- The interactive fretboard --}}
            <div class="sbn-editor-diagram-wrap">
                <div class="sbn-editor-diagram" id="sbnEditorDiagram"></div>
            </div>

            {{-- Hidden fields --}}
            <input type="hidden" name="diagram_data" :value="JSON.stringify(diagramData)">

            {{-- Computed fields (read-only display) --}}
            <div class="sbn-edit-grid sbn-edit-grid-2" style="margin-top:16px;">
                <div class="sbn-edit-field">
                    <label>Interval Labels <span class="sbn-edit-hint-inline">(auto-computed on save)</span></label>
                    <input type="text" readonly value="{{ old('interval_labels', $chord->interval_labels ?? '') }}"
                           class="sbn-readonly-field">
                </div>
                <div class="sbn-edit-field">
                    <label>Note Names <span class="sbn-edit-hint-inline">(auto-computed on save)</span></label>
                    <input type="text" readonly value="{{ old('notes', $chord->notes ?? '') }}"
                           class="sbn-readonly-field">
                </div>
            </div>

            {{-- Slug & Name (moved below intervals) --}}
            <div class="sbn-edit-slug-row" style="margin-top:12px;">
                <div class="sbn-slug-display">
                    <span class="sbn-slug-label">Slug</span>
                    <code x-text="generatedSlug"></code>
                </div>
                <div class="sbn-slug-display">
                    <span class="sbn-slug-label">Name</span>
                    <span x-text="generatedName" style="font-weight:600;"></span>
                </div>
            </div>
            <input type="hidden" name="slug" :value="generatedSlug">
            <input type="hidden" name="name" :value="generatedName">
        </div>

        {{-- ============================================================
             SECTION 3: Aliases (only shown for existing diagrams)
             ============================================================ --}}
        @unless($isNew)
        <div class="sbn-edit-section" x-data="aliasManager({{ $chord->id }}, @js($aliases))">
            <div class="sbn-edit-section-header">
                <h2>Aliases</h2>
                <p>Alternative chord identities for the same physical shape (e.g. Cmaj7 shape = Am9 rootless). Intervals are computed automatically.</p>
            </div>

            {{-- Aliases table --}}
            <div class="sbn-alias-table-wrap" x-show="aliases.length > 0">
                <table class="sbn-table">
                    <thead>
                        <tr>
                            <th>Alias Name</th>
                            <th>Intervals</th>
                            <th>Notes</th>
                            <th style="width:60px;"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <template x-for="alias in aliases" :key="alias.id">
                            <tr>
                                <td><strong x-text="alias.alt_name"></strong></td>
                                <td><code style="font-size:11px; background:none; padding:0;" x-text="alias.interval_labels || '—'"></code></td>
                                <td><code style="font-size:11px; background:none; padding:0;" x-text="alias.notes || '—'"></code></td>
                                <td>
                                    <button type="button" class="sbn-btn-sm sbn-btn-sm-danger"
                                            @click="deleteAlias(alias.id)">Del</button>
                                </td>
                            </tr>
                        </template>
                    </tbody>
                </table>
            </div>

            <div x-show="aliases.length === 0" class="sbn-alias-empty">
                No aliases yet.
            </div>

            {{-- Add alias form --}}
            <div class="sbn-alias-form">
                <div class="sbn-edit-grid sbn-edit-grid-5">
                    <div class="sbn-edit-field">
                        <label>Root Note</label>
                        <select x-model="newAlias.root_note">
                            @foreach($rootNotes as $n)
                                <option value="{{ $n }}">{{ $n }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="sbn-edit-field">
                        <label>Quality</label>
                        <select x-model="newAlias.quality">
                            @foreach($chordQualities as $key => $label)
                                <option value="{{ $key }}">{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="sbn-edit-field">
                        <label>Extensions</label>
                        <input type="text" x-model="newAlias.extensions" placeholder="e.g. b9, #11">
                    </div>
                    <div class="sbn-edit-field">
                        <label>Bass Note</label>
                        <input type="text" x-model="newAlias.bass_note" placeholder="e.g. Ab" style="width:80px;">
                    </div>
                    <div class="sbn-edit-field" style="display:flex; align-items:end;">
                        <button type="button" class="sbn-btn sbn-btn-secondary" @click="addAlias()" :disabled="addingAlias">
                            <span x-text="addingAlias ? 'Adding…' : '+ Add Alias'"></span>
                        </button>
                    </div>
                </div>
            </div>
        </div>
        @endunless

        {{-- ============================================================
             SECTION 4: Save
             ============================================================ --}}
        <div class="sbn-edit-actions">
            <button type="submit" class="sbn-btn sbn-btn-primary sbn-btn-lg" :disabled="saving">
                <span x-text="saving ? 'Saving…' : '{{ $isNew ? 'Create Shape' : 'Update Shape' }}'"></span>
            </button>
            <a href="{{ route('admin.chords.index') }}" class="sbn-btn sbn-btn-secondary sbn-btn-lg">Cancel</a>
        </div>

    </div>
</form>

{{-- Context menu for finger selection --}}
<div class="sbn-ctx-menu" id="sbnCtxMenu">
    <div class="sbn-ctx-title">Finger</div>
    <div class="sbn-ctx-fingers">
        <button type="button" class="sbn-ctx-f" data-finger="1" style="background:#3b82f6;">1</button>
        <button type="button" class="sbn-ctx-f" data-finger="2" style="background:#10b981;">2</button>
        <button type="button" class="sbn-ctx-f" data-finger="3" style="background:#f39c12;">3</button>
        <button type="button" class="sbn-ctx-f" data-finger="4" style="background:#ef4444;">4</button>
        <button type="button" class="sbn-ctx-f" data-finger="t" style="background:#8b5cf6;">T</button>
    </div>
    <button type="button" class="sbn-ctx-remove">Remove</button>
</div>
@endsection

@push('scripts')
<div id="desc-editor-root"></div>
@vite('resources/js/admin/description-editor.ts')
<script>
/**
 * Chord Editor — Phase 4b
 */

const FRETS_TO_SHOW = 5;
const FINGER_COLORS = { 1:'#3b82f6', 2:'#10b981', 3:'#f39c12', 4:'#ef4444', t:'#8b5cf6' };

const QUALITY_NAME = {
    'maj':'Maj','min':'min','aug':'Aug','dim':'Dim','5':'5','sus4':'sus4','sus2':'sus2','add9':'add9','madd9':'madd9','quartal':'Quartal',
    'maj7':'Maj7','m7':'m7','dom7':'7','m7b5':'m7♭5','o7':'°7','maj6':'Maj6','m6':'m6','mMaj7':'mMaj7','aug7':'Aug7'
};
const CAT_LABEL = @json($voicingCategories);
const ROOT_STR_LABEL = { roote:'E', roota:'A', rootd:'D', rootg:'G', custom:'?' };
const INV_LABEL = { root:'', inv1:'1st Inv', inv2:'2nd Inv', inv3:'3rd Inv' };

function chordEditor() {
    return {
        diagramData: @json($diagramData),
        startFret: {{ old('start_fret', $chord->start_fret ?? 1) }},
        saving: false,
        ctxTarget: null,

        fields: {
            root_note: '{{ old('root_note', $chord->root_note ?? 'C') }}',
            quality: '{{ old('quality', $chord->quality ?? 'maj7') }}',
            extensions: '{{ old('extensions', $chord->extensions ?? '') }}',
            voicing_category: '{{ old('voicing_category', $chord->voicing_category ?? 'drop2') }}',
            root_string: '{{ old('root_string', $chord->root_string ?? 'roota') }}',
            inversion: '{{ old('inversion', $chord->inversion ?? 'root') }}',
            bass_note: '{{ old('bass_note', $chord->bass_note ?? '') }}',
        },

        get generatedSlug() {
            const f = this.fields;
            let parts = [f.quality, f.voicing_category, f.root_string];
            if (f.inversion && f.inversion !== 'root') parts.push(f.inversion);
            if (f.extensions) parts.push(f.extensions.replace(/[#♯]/g,'s').replace(/♭/g,'b').replace(/\s/g,''));
            if (f.bass_note) parts.push('over' + f.bass_note);
            return parts.join('-');
        },

        get generatedName() {
            const f = this.fields;
            let n = (QUALITY_NAME[f.quality] || f.quality);
            if (f.extensions) n += f.extensions;
            n += ' ' + (CAT_LABEL[f.voicing_category] || f.voicing_category);
            if (f.inversion && f.inversion !== 'root') n += ' ' + (INV_LABEL[f.inversion] || f.inversion);
            n += ' (Root ' + (ROOT_STR_LABEL[f.root_string] || f.root_string) + ')';
            return n;
        },

        init() {
            this.render();
            this.setupCtxMenu();
        },

        onFieldChange() {},

        // ==================================================================
        // DIAGRAM DATA MANIPULATION
        // ==================================================================

        addPosition(string, fret, finger) {
            this.diagramData.positions = this.diagramData.positions.filter(p => !(p.string === string && p.fret === fret));
            this.diagramData.positions.push({ string, fret, finger: finger || 1 });
            this.diagramData.muted = this.diagramData.muted.filter(s => s !== string);
            this.diagramData.open = this.diagramData.open.filter(s => s !== string);
            this.render();
        },

        removePosition(index) {
            this.diagramData.positions.splice(index, 1);
            this.render();
        },

        setFinger(index, finger) {
            if (this.diagramData.positions[index]) {
                this.diagramData.positions[index].finger = finger;
            }
            this.render();
        },

        toggleStringState(string) {
            const m = this.diagramData.muted;
            const o = this.diagramData.open;
            const isMuted = m.includes(string);
            const isOpen = o.includes(string);

            this.diagramData.muted = m.filter(s => s !== string);
            this.diagramData.open = o.filter(s => s !== string);

            if (!isMuted && !isOpen) {
                this.diagramData.muted.push(string);
                this.diagramData.positions = this.diagramData.positions.filter(p => p.string !== string);
            } else if (isMuted) {
                this.diagramData.open.push(string);
            }
            this.render();
        },

        clearDiagram() {
            if (!confirm('Clear all finger positions?')) return;
            this.diagramData = { positions: [], barres: [], muted: [], open: [] };
            this.render();
        },

        // ==================================================================
        // RENDER
        // ==================================================================

        render() {
            const el = document.getElementById('sbnEditorDiagram');
            if (!el) return;

            const sf = this.startFret;
            const dd = this.diagramData;
            let html = '';

            if (sf > 1) {
                html += '<span class="sbn-ed-fret-num">' + sf + 'fr</span>';
            }

            html += '<div class="sbn-ed-indicators">';
            for (let s = 1; s <= 6; s++) {
                if (dd.muted.includes(s)) {
                    html += '<div class="sbn-ed-ind sbn-ed-ind-muted" data-string="' + s + '">×</div>';
                } else if (dd.open.includes(s)) {
                    html += '<div class="sbn-ed-ind sbn-ed-ind-open" data-string="' + s + '">○</div>';
                } else {
                    html += '<div class="sbn-ed-ind sbn-ed-ind-normal" data-string="' + s + '"></div>';
                }
            }
            html += '</div>';

            if (sf === 1) {
                html += '<div class="sbn-ed-nut"></div>';
            }

            html += '<div class="sbn-ed-frets">';
            for (let f = 0; f < FRETS_TO_SHOW; f++) {
                const actualFret = sf + f;
                html += '<div class="sbn-ed-row" data-fret="' + actualFret + '">';
                for (let s = 1; s <= 6; s++) {
                    html += '<div class="sbn-ed-cell" data-string="' + s + '" data-fret="' + actualFret + '"></div>';
                }
                html += '</div>';
            }
            html += '</div>';

            el.innerHTML = html;

            dd.positions.forEach((pos, idx) => {
                const fIdx = pos.fret - sf;
                if (fIdx < 0 || fIdx >= FRETS_TO_SHOW) return;
                const cell = el.querySelector('.sbn-ed-cell[data-string="' + pos.string + '"][data-fret="' + pos.fret + '"]');
                if (!cell) return;
                const dot = document.createElement('div');
                dot.className = 'sbn-ed-dot';
                dot.textContent = pos.finger || '';
                dot.dataset.index = idx;
                dot.style.background = FINGER_COLORS[pos.finger] || '#666';
                cell.appendChild(dot);
            });

            if (dd.barres.length) {
                requestAnimationFrame(() => {
                    dd.barres.forEach((barre, idx) => {
                        const fIdx = barre.fret - sf;
                        if (fIdx < 0 || fIdx >= FRETS_TO_SHOW) return;
                        const row = el.querySelector('.sbn-ed-row[data-fret="' + barre.fret + '"]');
                        if (!row) return;
                        const fromCell = row.querySelector('.sbn-ed-cell[data-string="' + barre.fromString + '"]');
                        const toCell = row.querySelector('.sbn-ed-cell[data-string="' + barre.toString + '"]');
                        if (!fromCell || !toCell) return;
                        const fL = fromCell.offsetLeft + fromCell.offsetWidth / 2;
                        const tL = toCell.offsetLeft + toCell.offsetWidth / 2;
                        const barreEl = document.createElement('div');
                        barreEl.className = 'sbn-ed-barre';
                        barreEl.textContent = barre.finger || '';
                        barreEl.dataset.index = idx;
                        barreEl.style.background = FINGER_COLORS[barre.finger] || '#666';
                        barreEl.style.left = Math.min(fL, tL) + 'px';
                        barreEl.style.width = Math.max(Math.abs(tL - fL), 24) + 'px';
                        barreEl.style.top = (row.offsetHeight / 2) + 'px';
                        row.appendChild(barreEl);
                    });
                });
            }

            this.bindEditorEvents(el);
        },

        bindEditorEvents(el) {
            el.querySelectorAll('.sbn-ed-ind').forEach(ind => {
                ind.addEventListener('click', (e) => {
                    e.stopPropagation();
                    this.toggleStringState(parseInt(ind.dataset.string));
                });
            });

            el.querySelectorAll('.sbn-ed-cell').forEach(cell => {
                cell.addEventListener('click', (e) => {
                    if (e.target.classList.contains('sbn-ed-dot')) return;
                    const s = parseInt(cell.dataset.string);
                    const f = parseInt(cell.dataset.fret);
                    const existing = this.diagramData.positions.findIndex(p => p.string === s && p.fret === f);
                    if (existing >= 0) {
                        this.removePosition(existing);
                    } else {
                        this.addPosition(s, f, 1);
                    }
                });
            });

            el.querySelectorAll('.sbn-ed-dot').forEach(dot => {
                dot.addEventListener('click', (e) => {
                    e.stopPropagation();
                    this.showCtxMenu(e.pageX, e.pageY, parseInt(dot.dataset.index), 'position');
                });
                dot.addEventListener('contextmenu', (e) => {
                    e.preventDefault();
                    e.stopPropagation();
                    this.showCtxMenu(e.pageX, e.pageY, parseInt(dot.dataset.index), 'position');
                });
            });

            el.querySelectorAll('.sbn-ed-barre').forEach(b => {
                b.addEventListener('click', (e) => {
                    e.stopPropagation();
                    this.showCtxMenu(e.pageX, e.pageY, parseInt(b.dataset.index), 'barre');
                });
            });
        },

        // ==================================================================
        // CONTEXT MENU
        // ==================================================================

        showCtxMenu(x, y, index, type) {
            this.ctxTarget = { index, type };
            const menu = document.getElementById('sbnCtxMenu');
            menu.style.display = 'block';
            menu.style.left = Math.min(x, window.innerWidth - 220) + 'px';
            menu.style.top = Math.min(y, window.innerHeight - 140) + 'px';
        },

        hideCtxMenu() {
            document.getElementById('sbnCtxMenu').style.display = 'none';
            this.ctxTarget = null;
        },

        setupCtxMenu() {
            const menu = document.getElementById('sbnCtxMenu');

            menu.querySelectorAll('.sbn-ctx-f').forEach(btn => {
                btn.addEventListener('click', (e) => {
                    e.stopPropagation();
                    if (!this.ctxTarget) return;
                    const finger = btn.dataset.finger;
                    if (this.ctxTarget.type === 'position') {
                        this.setFinger(this.ctxTarget.index, finger);
                    } else if (this.ctxTarget.type === 'barre' && this.diagramData.barres[this.ctxTarget.index]) {
                        this.diagramData.barres[this.ctxTarget.index].finger = finger;
                        this.render();
                    }
                    this.hideCtxMenu();
                });
            });

            menu.querySelector('.sbn-ctx-remove').addEventListener('click', (e) => {
                e.stopPropagation();
                if (!this.ctxTarget) return;
                if (this.ctxTarget.type === 'position') {
                    this.removePosition(this.ctxTarget.index);
                } else if (this.ctxTarget.type === 'barre') {
                    this.diagramData.barres.splice(this.ctxTarget.index, 1);
                    this.render();
                }
                this.hideCtxMenu();
            });

            document.addEventListener('click', (e) => {
                if (!e.target.closest('#sbnCtxMenu') && !e.target.closest('.sbn-ed-dot') && !e.target.closest('.sbn-ed-barre')) {
                    this.hideCtxMenu();
                }
            });
        },

        // ==================================================================
        // FORM SUBMISSION
        // ==================================================================

        submitForm(formEl) {
            this.saving = true;
            formEl.submit();
        },
    };
}

/**
 * Alias Manager — separate Alpine component for the aliases section.
 */
function aliasManager(diagramId, initialAliases) {
    return {
        diagramId: diagramId,
        aliases: initialAliases || [],
        addingAlias: false,
        newAlias: {
            root_note: 'C',
            quality: 'maj7',
            extensions: '',
            bass_note: '',
        },

        async addAlias() {
            if (!this.newAlias.root_note || !this.newAlias.quality) return;
            this.addingAlias = true;

            try {
                const res = await fetch('/api/admin/chords/' + this.diagramId + '/aliases', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content,
                        'Accept': 'application/json',
                    },
                    body: JSON.stringify({
                        alt_root_note: this.newAlias.root_note,
                        alt_quality: this.newAlias.quality,
                        alt_extensions: this.newAlias.extensions,
                        alt_bass_note: this.newAlias.bass_note,
                    }),
                });

                if (res.ok) {
                    const json = await res.json();
                    this.aliases.push(json.data);
                    this.newAlias.extensions = '';
                    this.newAlias.bass_note = '';
                    sbnToast('Alias added', 'success');
                } else {
                    const err = await res.json();
                    sbnToast('Error: ' + (err.message || 'Unknown error'), 'error');
                }
            } catch (e) {
                sbnToast('Network error', 'error');
            }

            this.addingAlias = false;
        },

        async deleteAlias(aliasId) {
            if (!confirm('Delete this alias?')) return;

            try {
                const res = await fetch('/api/admin/chords/aliases/' + aliasId, {
                    method: 'DELETE',
                    headers: {
                        'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content,
                        'Accept': 'application/json',
                    },
                });

                if (res.ok) {
                    this.aliases = this.aliases.filter(a => a.id !== aliasId);
                    sbnToast('Alias deleted', 'success');
                } else {
                    sbnToast('Error deleting alias', 'error');
                }
            } catch (e) {
                sbnToast('Network error', 'error');
            }
        },
    };
}

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
</script>
@endpush
