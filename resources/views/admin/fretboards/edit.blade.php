@extends('layouts.admin')

@section('title', $isNew ? 'New Fretboard' : 'Edit Fretboard')

@section('actions')
    <a href="{{ route('admin.fretboards.index') }}" class="sbn-btn sbn-btn-secondary">← Back</a>
@endsection

@push('styles')
<style>
/* ── Layout ─────────────────────────────────────────────── */
.sbn-fb-edit-layout {
    display: grid;
    grid-template-columns: 300px 1fr;
    gap: 24px;
    align-items: start;
}
@media (max-width: 960px) {
    .sbn-fb-edit-layout { grid-template-columns: 1fr; }
}
.sbn-fb-edit-sidebar { display: flex; flex-direction: column; gap: 18px; }

/* ── Interactive fretboard editor ───────────────────────── */
.sbn-fbe-wrap {
    position: sticky;
    top: 24px;
    display: flex;
    flex-direction: column;
    gap: 14px;
}

.sbn-fbe-label {
    font-size: 11px; font-weight: 600; text-transform: uppercase;
    letter-spacing: .06em; color: var(--clr-text-dim);
}

/* The interactive grid */
.sbn-fbe-grid {
    display: flex;
    align-items: stretch;
    border-radius: 6px;
    overflow: hidden;
    border: 1px solid #333;
    background: linear-gradient(to bottom, #1a1a1a, #0d0d0d);
    min-height: 130px;
    user-select: none;
}
.sbn-fbe-grid.theme-light {
    background: linear-gradient(to bottom, #f5e6d3, #e8d4bc);
    border-color: #d4c4a8;
}

/* String name column */
.sbn-fbe-strings {
    display: flex; flex-direction: column; justify-content: space-around;
    padding: 8px 6px; background: #1a1a1a; border-right: 1px solid #333;
    min-width: 22px;
}
.sbn-fbe-grid.theme-light .sbn-fbe-strings { background: #e8dcc8; border-right-color: #d4c4a8; }
.sbn-fbe-string-name {
    font-size: 10px; font-weight: 600; color: #888; text-align: center;
    height: 22px; display: flex; align-items: center; justify-content: center;
}

/* Open/mute column */
.sbn-fbe-open-col {
    display: flex; flex-direction: column; justify-content: space-around;
    padding: 8px 6px; background: #151515; border-right: 1px solid #444;
    min-width: 30px;
}
.sbn-fbe-grid.theme-light .sbn-fbe-open-col { background: #efe4d4; }
.sbn-fbe-open-cell {
    height: 22px; display: flex; align-items: center; justify-content: center;
    cursor: pointer; font-size: 11px; font-weight: 700; color: #555;
    border-radius: 3px; transition: color .12s, background .12s;
    position: relative;
}
.sbn-fbe-open-cell::before {
    content: ''; position: absolute; left: 0; right: 0; top: 50%;
    height: 1px; background: #555; transform: translateY(-50%);
}
.sbn-fbe-open-cell[data-string="3"]::before { height: 1.5px; }
.sbn-fbe-open-cell[data-string="4"]::before { height: 2px; }
.sbn-fbe-open-cell[data-string="5"]::before { height: 2.5px; background: #4a4a4a; }
.sbn-fbe-open-cell.is-open  { color: #ccc; }
.sbn-fbe-open-cell.is-muted { color: #e55; }
.sbn-fbe-open-cell:hover    { background: rgba(255,255,255,.06); color: #bbb; }

/* Nut */
.sbn-fbe-nut {
    width: 6px; flex-shrink: 0;
    background: linear-gradient(to right, #f5f5f0, #d8d8d0, #f5f5f0);
    border-right: 1px solid #333;
    box-shadow: 1px 0 3px rgba(0,0,0,.3);
}

/* Fret columns */
.sbn-fbe-frets { display: flex; flex: 1; overflow-x: auto; padding-bottom: 20px; }
.sbn-fbe-fret-col {
    display: flex; flex-direction: column; justify-content: space-around;
    min-width: 44px; flex: 1;
    border-right: 2px solid #c0a060; padding: 8px 2px;
    position: relative;
    background: linear-gradient(to right, rgba(255,255,255,.02), transparent 20%, transparent 80%, rgba(255,255,255,.01));
}
.sbn-fbe-grid.theme-light .sbn-fbe-fret-col { border-right-color: #a89070; }
.sbn-fbe-fret-col:last-child { border-right-color: #a08040; }
.sbn-fbe-fret-col.has-marker::before {
    content: ''; position: absolute; width: 8px; height: 8px;
    background: radial-gradient(ellipse at 30% 30%, #e8e8e0, #c8c8c0, #a8a8a0);
    border-radius: 50%; left: 50%; top: 50%; transform: translate(-50%,-50%);
    opacity: .6; pointer-events: none;
}
.sbn-fbe-fret-col.double-marker::before { top: 30%; }
.sbn-fbe-fret-col.double-marker::after {
    content: ''; position: absolute; width: 8px; height: 8px;
    background: radial-gradient(ellipse at 30% 30%, #e8e8e0, #c8c8c0, #a8a8a0);
    border-radius: 50%; left: 50%; top: 70%; transform: translate(-50%,-50%);
    opacity: .6; pointer-events: none;
}
.sbn-fbe-fret-num {
    position: absolute; bottom: -16px; left: 50%; transform: translateX(-50%);
    font-size: 9px; color: #666; font-weight: 500; pointer-events: none;
}

/* Individual cell */
.sbn-fbe-cell {
    height: 22px; display: flex; align-items: center; justify-content: center;
    cursor: pointer; position: relative; border-radius: 2px;
    transition: background .1s;
}
.sbn-fbe-cell::before {
    content: ''; position: absolute; left: 0; right: 0; top: 50%;
    height: 1px; background: linear-gradient(to bottom, #888, #666);
    transform: translateY(-50%); pointer-events: none;
}
.sbn-fbe-cell[data-string="3"]::before { height: 1.5px; background: linear-gradient(to bottom,#777,#555); }
.sbn-fbe-cell[data-string="4"]::before { height: 2px;   background: linear-gradient(to bottom,#666,#444); }
.sbn-fbe-cell[data-string="5"]::before { height: 2.5px; background: linear-gradient(to bottom,#5a5a5a,#3a3a3a); }
.sbn-fbe-cell:hover { background: rgba(255,255,255,.05); }

/* Dot */
.sbn-fbe-dot {
    width: 20px; height: 20px; border-radius: 50%;
    background: linear-gradient(145deg, #e8e8e0, #c8c8c0);
    display: flex; align-items: center; justify-content: center;
    color: #333; font-size: 10px; font-weight: 700;
    box-shadow: 0 2px 4px rgba(0,0,0,.5), inset 0 1px 0 rgba(255,255,255,.5);
    position: relative; z-index: 2; pointer-events: none;
}

/* RH fingers column */
.sbn-fbe-rh-col {
    display: flex; flex-direction: column; justify-content: space-around;
    padding: 8px 8px; border-left: 1px solid #333;
    background: rgba(0,0,0,.2); min-width: 24px;
}
.sbn-fbe-rh-cell {
    height: 22px; display: flex; align-items: center; justify-content: center;
    font-size: 11px; font-weight: 600; color: #888; font-style: italic;
}

/* Controls bar */
.sbn-fbe-controls {
    display: flex; align-items: center; gap: 8px; flex-wrap: wrap;
}

/* Frame list */
.sbn-fb-frame-list { display: flex; flex-direction: column; gap: 6px; }
.sbn-fb-frame-item {
    display: flex; align-items: center; gap: 8px; padding: 7px 10px;
    background: var(--clr-bg); border: 1px solid var(--clr-border);
    border-radius: 6px; cursor: pointer; transition: border-color .12s, background .12s;
}
.sbn-fb-frame-item.is-active { border-color: var(--clr-accent); background: rgba(var(--clr-accent-rgb,232,93,59),.04); }
.sbn-fb-frame-item:hover:not(.is-active) { border-color: var(--clr-text-dim); }
.sbn-fb-frame-label { flex: 1; font-size: 13px; font-weight: 600; color: var(--clr-text); min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.sbn-fb-frame-frets { font-size: 11px; font-family: var(--font-mono,monospace); color: var(--clr-text-dim); }
.sbn-fb-frame-del {
    flex-shrink: 0; width: 20px; height: 20px; border: none; background: none;
    color: var(--clr-text-dim); cursor: pointer; border-radius: 3px; font-size: 14px;
    display: flex; align-items: center; justify-content: center; transition: color .12s, background .12s;
}
.sbn-fb-frame-del:hover { color: var(--clr-error); background: rgba(239,68,68,.08); }

/* Window rows (positions mode) */
.sbn-fb-window-item {
    display: flex; align-items: center; gap: 6px; padding: 6px 8px;
    background: var(--clr-bg); border: 1px solid var(--clr-border); border-radius: 6px;
}
.sbn-fb-win-label { flex: 1; min-width: 0; font-size: 12px; padding: 4px 6px; }
.sbn-fb-win-num { display: flex; align-items: center; gap: 3px; font-size: 10px; color: var(--clr-text-dim); }
.sbn-fb-win-num input { width: 42px; font-size: 12px; padding: 3px 4px; }
[x-cloak] { display: none !important; }

/* Tag hint */
.sbn-fb-tag-hint {
    display: flex; align-items: center; gap: 8px; padding: 10px 12px;
    background: var(--clr-bg-subtle); border: 1px solid var(--clr-border);
    border-radius: 6px; font-size: 12px; color: var(--clr-text-dim);
}
.sbn-fb-tag-hint code {
    flex: 1; font-size: 11px; background: var(--clr-bg); padding: 2px 6px;
    border-radius: 3px; border: 1px solid var(--clr-border);
}

/* Interval labels row */
.sbn-fbe-iv-row {
    display: flex; gap: 0; border-top: 1px solid var(--clr-border); margin-top: 4px;
}
.sbn-fbe-iv-cell {
    flex: 1; min-width: 0;
    border: none; border-right: 1px solid var(--clr-border);
    background: transparent; font-size: 10px; text-align: center;
    padding: 3px 2px; color: var(--clr-text-dim); font-family: var(--font-mono, monospace);
    outline: none;
}
.sbn-fbe-iv-cell:last-child { border-right: none; }
.sbn-fbe-iv-cell:focus { background: rgba(var(--clr-accent-rgb,232,93,59),.08); color: var(--clr-text); }
.sbn-fbe-iv-label {
    font-size: 10px; color: var(--clr-text-dim); font-weight: 600;
    text-align: center; padding: 2px 0 0;
    text-transform: uppercase; letter-spacing: .04em;
}
</style>
@endpush

@section('content')

@if($errors->any())
    <div class="sbn-flash" style="margin:0 0 20px;background:rgba(239,68,68,.08);border:1px solid rgba(239,68,68,.2);color:var(--clr-error);">
        <ul style="margin:0;padding-left:18px;">
            @foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach
        </ul>
    </div>
@endif

<form
    method="POST"
    action="{{ $isNew ? route('admin.fretboards.store') : route('admin.fretboards.update', $fretboard) }}"
    x-data="fretboardEditor()"
    x-init="init()"
    @submit.prevent="submitForm($el)"
>
    @csrf
    @unless($isNew) @method('PUT') @endunless
    <input type="hidden" name="voicings" :value="JSON.stringify(voicings)">
    <input type="hidden" name="windows" :value="JSON.stringify(windows)">
    <input type="hidden" name="start_window" :value="meta.start_window">

    <div class="sbn-fb-edit-layout">

        {{-- ══════════════════════════════════════════════════════
             SIDEBAR — properties + frame list + save
             ══════════════════════════════════════════════════════ --}}
        <div class="sbn-fb-edit-sidebar">

            {{-- Properties --}}
            <div class="sbn-edit-section">
                <div class="sbn-edit-section-header"><h2>Properties</h2></div>

                <div class="sbn-edit-field">
                    <label>Title</label>
                    <input type="text" name="title"
                           x-model="meta.title"
                           value="{{ old('title', $fretboard->title ?? '') }}"
                           required placeholder="e.g. Am7 Drop-2 Voice Leading">
                </div>

                <div class="sbn-edit-grid sbn-edit-grid-2" style="margin-top:10px;">
                    <div class="sbn-edit-field">
                        <label>Slug</label>
                        <input type="text" name="slug"
                               x-model="meta.slug"
                               value="{{ old('slug', $fretboard->slug ?? '') }}"
                               placeholder="auto-generated">
                        <span class="sbn-edit-hint">&lt;sbn-fretboard slug="…"&gt;</span>
                    </div>
                    <div class="sbn-edit-field">
                        <label>Mode</label>
                        <select name="display_mode" x-model="meta.display_mode" @change="loadFrame(activeFrame); render()">
                            <option value="chord">Chord</option>
                            <option value="scale">Scale</option>
                            <option value="sequence">Sequence</option>
                            <option value="positions">Positions (sliding)</option>
                        </select>
                    </div>
                </div>

                <div class="sbn-edit-field" style="margin-top:10px;">
                    <label>Root note</label>
                    <select name="root_note" x-model="meta.root_note">
                        <option value="">— none —</option>
                        <template x-for="note in ['C','C#','Db','D','D#','Eb','E','F','F#','Gb','G','G#','Ab','A','A#','Bb','B']" :key="note">
                            <option :value="note" x-text="note"></option>
                        </template>
                    </select>
                    <span class="sbn-edit-hint">The key this record is authored in — enables <code>&lt;sbn-fretboard key="…"&gt;</code> transposition on the course tag.</span>
                </div>

                <div class="sbn-edit-field" style="margin-top:10px;">
                    <label>Description</label>
                    <input type="text" name="description"
                           value="{{ old('description', $fretboard->description ?? '') }}"
                           placeholder="Optional notes">
                </div>

                <div class="sbn-edit-grid sbn-edit-grid-2" style="margin-top:10px;">
                    <div class="sbn-edit-field">
                        <label>Frets shown</label>
                        <input type="number" name="fret_count"
                               x-model.number="meta.fret_count" @change="render()"
                               min="4" max="24"
                               value="{{ old('fret_count', $fretboard->fret_count ?? 12) }}">
                    </div>
                    <div class="sbn-edit-field">
                        <label>Start fret</label>
                        <input type="number" name="start_fret"
                               x-model.number="meta.start_fret" @change="render()"
                               min="1" max="20"
                               value="{{ old('start_fret', $fretboard->start_fret ?? 1) }}">
                    </div>
                </div>

                <div class="sbn-edit-grid sbn-edit-grid-2" style="margin-top:10px;">
                    <div class="sbn-edit-field">
                        <label>Theme</label>
                        <select name="theme" x-model="meta.theme" @change="render()">
                            <option value="dark">Dark (Ebony)</option>
                            <option value="light">Light (Maple)</option>
                        </select>
                    </div>
                    <div class="sbn-edit-field" style="display:flex;align-items:end;gap:12px;flex-wrap:wrap;">
                        <label class="sbn-edit-checkbox">
                            <input type="hidden" name="show_guide_tones" value="0">
                            <input type="checkbox" name="show_guide_tones" value="1"
                                   x-model="meta.show_guide_tones" @change="render()"
                                   @checked(old('show_guide_tones', $fretboard->show_guide_tones ?? false))>
                            <span>Guide tones</span>
                        </label>
                        <label class="sbn-edit-checkbox">
                            <input type="hidden" name="show_rh_fingers" value="0">
                            <input type="checkbox" name="show_rh_fingers" value="1"
                                   x-model="meta.show_rh_fingers" @change="render()"
                                   @checked(old('show_rh_fingers', $fretboard->show_rh_fingers ?? false))>
                            <span>RH fingers</span>
                        </label>
                    </div>
                </div>
            </div>

            {{-- Frames --}}
            <div class="sbn-edit-section">
                <div class="sbn-edit-section-header">
                    <h2>Frames</h2>
                    <p>One frame = chord/scale. Multiple = sequence (‹ ›).</p>
                </div>

                <div class="sbn-fb-frame-list" style="margin-bottom:10px;">
                    <template x-for="(frame, idx) in voicings" :key="idx">
                        <div class="sbn-fb-frame-item" :class="{ 'is-active': activeFrame === idx }"
                             @click="setActiveFrame(idx)">
                            <span class="sbn-fb-frame-label" x-text="frame.label || ('Frame ' + (idx+1))"></span>
                            <span class="sbn-fb-frame-frets" x-text="frame.frets || 'xxxxxx'"></span>
                            <button type="button" class="sbn-fb-frame-del"
                                    @click.stop="deleteFrame(idx)"
                                    x-show="voicings.length > 1"
                                    title="Remove">×</button>
                        </div>
                    </template>
                </div>
                <button type="button" class="sbn-btn sbn-btn-secondary" @click="addFrame()" style="width:100%;">
                    + Add Frame
                </button>

                {{-- Active frame label --}}
                <div class="sbn-edit-field" style="margin-top:14px;">
                    <label>Frame Label</label>
                    <input type="text"
                           :value="currentFrame.label"
                           @input="currentFrame.label = $event.target.value; syncFrame()"
                           placeholder="e.g. Cmaj7 root pos.">
                </div>
            </div>

            {{-- Windows (positions mode only) --}}
            <div class="sbn-edit-section" x-show="isPositionsMode()" x-cloak>
                <div class="sbn-edit-section-header">
                    <h2>Position Windows</h2>
                    <p>Place all scale notes in one frame, then define the fret windows the camera slides between.</p>
                </div>

                <div class="sbn-edit-field" x-show="windows.length > 0" style="margin-bottom:10px;">
                    <label>Starting position</label>
                    <select x-model.number="meta.start_window">
                        <template x-for="(win, idx) in windows" :key="idx">
                            <option :value="idx" x-text="(win.label || ('Position ' + (idx + 1)))"></option>
                        </template>
                    </select>
                </div>

                <div class="sbn-fb-frame-list" style="margin-bottom:10px;">
                    <template x-for="(win, idx) in windows" :key="idx">
                        <div class="sbn-fb-window-item">
                            <input type="text" class="sbn-fb-win-label"
                                   :value="win.label"
                                   @input="win.label = $event.target.value"
                                   placeholder="Position name">
                            <label class="sbn-fb-win-num">from
                                <input type="number" min="1" max="24"
                                       :value="win.from"
                                       @input="win.from = parseInt($event.target.value) || 1">
                            </label>
                            <label class="sbn-fb-win-num">to
                                <input type="number" min="1" max="24"
                                       :value="win.to"
                                       @input="win.to = parseInt($event.target.value) || 1">
                            </label>
                            <button type="button" class="sbn-fb-frame-del" @click="moveWindow(idx,-1)"
                                    x-show="idx > 0" title="Move up">↑</button>
                            <button type="button" class="sbn-fb-frame-del" @click="moveWindow(idx,1)"
                                    x-show="idx < windows.length - 1" title="Move down">↓</button>
                            <button type="button" class="sbn-fb-frame-del" @click="deleteWindow(idx)" title="Remove">×</button>
                        </div>
                    </template>
                </div>
                <button type="button" class="sbn-btn sbn-btn-secondary" @click="addWindow()" style="width:100%;">
                    + Add Window
                </button>
                <p class="sbn-edit-hint" style="margin-top:8px;">
                    Optional per-dot interval colors: add an <code>iv</code> field (R, 3, b3, 5, b7…) to dots in the JSON to color guide tones. The live grid here shows all notes; the sliding camera is visible in the published <code>&lt;sbn-fretboard&gt;</code>.
                </p>
            </div>

            {{-- Tag hint --}}
            @unless($isNew)
            <div class="sbn-fb-tag-hint">
                <code>&lt;sbn-fretboard slug="{{ $fretboard->slug }}"&gt;</code>
                <button type="button" class="sbn-btn-icon" title="Copy tag"
                        onclick="navigator.clipboard.writeText('<sbn-fretboard slug=&quot;{{ $fretboard->slug }}&quot;>').then(()=>sbnToast('Tag copied','success'))">
                    <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5" width="13" height="13"><rect x="5" y="5" width="9" height="9" rx="1.5"/><path d="M11 5V3.5A1.5 1.5 0 009.5 2H2.5A1.5 1.5 0 001 3.5v7A1.5 1.5 0 002.5 12H4"/></svg>
                </button>
            </div>
            @endunless

            {{-- Save --}}
            <div class="sbn-edit-actions">
                <button type="submit" class="sbn-btn sbn-btn-primary sbn-btn-lg" :disabled="saving">
                    <span x-text="saving ? 'Saving…' : '{{ $isNew ? 'Create' : 'Update' }}'"></span>
                </button>
                <a href="{{ route('admin.fretboards.index') }}" class="sbn-btn sbn-btn-secondary sbn-btn-lg">Cancel</a>
            </div>

        </div>{{-- /sidebar --}}

        {{-- ══════════════════════════════════════════════════════
             RIGHT — interactive fretboard + controls
             ══════════════════════════════════════════════════════ --}}
        <div class="sbn-fbe-wrap">

            <div class="sbn-fbe-label">
                Click strings to place/remove dots &nbsp;·&nbsp;
                Click open column to cycle muted(×) → open(○) → normal &nbsp;·&nbsp;
                Right-click dot to set finger number
            </div>

            {{-- Controls --}}
            <div class="sbn-fbe-controls">
                <button type="button" class="sbn-btn sbn-btn-secondary" @click="clearFrame()">Clear</button>
                <div style="display:flex;gap:6px;align-items:center;">
                    <span style="font-size:12px;color:var(--clr-text-dim);">Active finger:</span>
                    <template x-for="f in [1,2,3,4,'T']" :key="f">
                        <button type="button"
                                class="sbn-finger-pill"
                                :style="'--fc:' + fingerColor(f)"
                                :style="activeFinger == f ? 'outline:2px solid currentColor;outline-offset:2px;' : ''"
                                @click="activeFinger = f"
                                x-text="f"
                                :class="activeFinger == f ? 'is-active' : ''">
                        </button>
                    </template>
                    <button type="button"
                            class="sbn-btn sbn-btn-secondary"
                            style="padding:2px 8px;font-size:12px;"
                            :style="activeFinger === 0 ? 'border-color:var(--clr-accent);' : ''"
                            @click="activeFinger = 0"
                            title="Place dot without finger number">●</button>
                </div>
            </div>

            {{-- The interactive grid --}}
            <div class="sbn-fbe-grid" :class="'theme-' + meta.theme" id="sbnFbeGrid">
                {{-- rendered by JS --}}
            </div>

            {{-- Interval labels row --}}
            <div>
                <div class="sbn-fbe-iv-label">Interval labels (low E → high e, comma-separated or one per cell)</div>
                <div class="sbn-fbe-iv-row" id="sbnFbeIvRow">
                    <template x-for="(iv, idx) in ivLabels" :key="idx">
                        <input class="sbn-fbe-iv-cell"
                               :value="iv"
                               @input="ivLabels[idx] = $event.target.value; syncFrame()"
                               placeholder="—"
                               :title="'String ' + idx + ' interval'">
                    </template>
                </div>
                <div style="font-size:10px;color:var(--clr-text-dim);margin-top:3px;">
                    R &nbsp;3 &nbsp;b3 &nbsp;5 &nbsp;b5 &nbsp;b7 &nbsp;7 &nbsp;maj7 &nbsp;9 &nbsp;b9 &nbsp;#9 &nbsp;11 &nbsp;#11 &nbsp;13
                </div>
            </div>

            {{-- Fret string readout --}}
            <div style="display:flex;align-items:center;gap:10px;font-size:12px;color:var(--clr-text-dim);">
                <span>Fret string:</span>
                <code x-text="currentFrame.frets" style="font-size:12px;background:var(--clr-bg-subtle);padding:2px 8px;border-radius:4px;border:1px solid var(--clr-border);"></code>
                <span>Fingers:</span>
                <code x-text="currentFrame.fingers" style="font-size:12px;background:var(--clr-bg-subtle);padding:2px 8px;border-radius:4px;border:1px solid var(--clr-border);"></code>
            </div>

        </div>{{-- /fbe-wrap --}}

    </div>{{-- /layout --}}
</form>

{{-- Context menu for finger selection --}}
<div class="sbn-ctx-menu" id="sbnFbeCtx" style="display:none;">
    <div class="sbn-ctx-title">Finger</div>
    <div class="sbn-ctx-fingers">
        <button type="button" class="sbn-ctx-f" data-finger="1" style="background:#3b82f6;">1</button>
        <button type="button" class="sbn-ctx-f" data-finger="2" style="background:#10b981;">2</button>
        <button type="button" class="sbn-ctx-f" data-finger="3" style="background:#f39c12;">3</button>
        <button type="button" class="sbn-ctx-f" data-finger="4" style="background:#ef4444;">4</button>
        <button type="button" class="sbn-ctx-f" data-finger="T" style="background:#8b5cf6;">T</button>
    </div>
    <button type="button" class="sbn-ctx-remove">Remove</button>
</div>

@endsection

@push('scripts')
<script src="{{ asset('js/chords.js') }}"></script>
@php
    $defaultVoicings = [['label' => '', 'frets' => 'xxxxxx', 'fingers' => '000000', 'interval_labels' => '']];
    $initialVoicings = old('voicings')
        ? (json_decode(old('voicings'), true) ?: $defaultVoicings)
        : ($fretboard->voicings ?? $defaultVoicings);
    $initialWindows = old('windows')
        ? (json_decode(old('windows'), true) ?: [])
        : ($fretboard->windows ?? []);
@endphp
<script>
const FBE_FRET_MARKERS = [3,5,7,9,12,15,17,19,21,24];
const FBE_FINGER_COLORS = { 1:'#3b82f6', 2:'#10b981', 3:'#f39c12', 4:'#ef4444', T:'#8b5cf6', 0:'#888' };
const FBE_STRING_NAMES  = ['E','A','D','G','B','e']; // index 0=low E

function fretboardEditor() {
    return {
        saving: false,
        activeFinger: 1,   // finger to place on next click
        ctxTarget: null,   // { stringIdx, fretNum } for context menu

        meta: {
            title:            @json(old('title', $fretboard->title ?? '')),
            root_note:        @json(old('root_note', $fretboard->root_note ?? '')),
            slug:             @json(old('slug',  $fretboard->slug  ?? '')),
            display_mode:     @json(old('display_mode', $fretboard->display_mode ?? 'chord')),
            theme:            @json(old('theme',        $fretboard->theme        ?? 'dark')),
            fret_count:       {{ old('fret_count',  $fretboard->fret_count  ?? 12) }},
            start_fret:       {{ old('start_fret',  $fretboard->start_fret  ?? 1)  }},
            show_guide_tones: {{ ($fretboard->show_guide_tones ?? false) ? 'true' : 'false' }},
            show_rh_fingers:  {{ ($fretboard->show_rh_fingers  ?? false) ? 'true' : 'false' }},
            start_window:     {{ old('start_window', $fretboard->start_window ?? 0) }},
        },

        voicings: @json($initialVoicings),
        windows: @json($initialWindows),
        activeFrame: 0,

        // Per-string state for the active frame (parallel arrays, index 0=low E)
        // Derived from voicings[activeFrame]; synced back on every change.
        dotMap: {},    // key: "s_f" → finger (1-4, 'T', 0)
        openStrings: new Set(),  // string indices that are open
        mutedStrings: new Set(), // string indices that are muted
        ivLabels: ['','','','','',''], // interval label per string

        get currentFrame() {
            return this.voicings[this.activeFrame] || this.voicings[0];
        },

        fingerColor(f) {
            return FBE_FINGER_COLORS[f] || '#888';
        },

        // ── lifecycle ──────────────────────────────────────────────

        init() {
            this.loadFrame(this.activeFrame);
            this.render();
            this.setupCtxMenu();
        },

        // ── frame management ───────────────────────────────────────

        setActiveFrame(idx) {
            this.activeFrame = idx;
            this.loadFrame(idx);
            this.render();
        },

        addFrame() {
            this.voicings.push({ label:'', frets:'xxxxxx', fingers:'000000', interval_labels:'' });
            this.setActiveFrame(this.voicings.length - 1);
        },

        deleteFrame(idx) {
            if (this.voicings.length <= 1) return;
            this.voicings.splice(idx, 1);
            this.activeFrame = Math.min(this.activeFrame, this.voicings.length - 1);
            this.loadFrame(this.activeFrame);
            this.render();
        },

        // Load dotMap/openStrings/mutedStrings/ivLabels from the frame's fret string
        // Scale mode uses frame.dots = [{s, f, finger}] to allow multiple dots per string.
        // Chord/sequence mode uses the classic frets string (one dot per string).
        isScaleMode() {
            // Positions mode uses the same multi-dot-per-string grid as scale mode.
            return this.meta.display_mode === 'scale' || this.meta.display_mode === 'positions';
        },

        isPositionsMode() {
            return this.meta.display_mode === 'positions';
        },

        // ── windows (positions mode) ───────────────────────────────
        addWindow() {
            const last = this.windows[this.windows.length - 1];
            const from = last ? Math.min(last.from + 2, 20) : (this.meta.start_fret || 1);
            this.windows.push({ label: 'Position ' + (this.windows.length + 1), from, to: from + 3 });
        },
        deleteWindow(idx) {
            this.windows.splice(idx, 1);
            if (this.meta.start_window >= this.windows.length) {
                this.meta.start_window = Math.max(0, this.windows.length - 1);
            }
        },
        moveWindow(idx, dir) {
            const j = idx + dir;
            if (j < 0 || j >= this.windows.length) return;
            const tmp = this.windows[idx];
            this.windows[idx] = this.windows[j];
            this.windows[j] = tmp;
        },

        loadFrame(idx) {
            const frame   = this.voicings[idx] || { frets:'xxxxxx', fingers:'000000', interval_labels:'' };
            this.dotMap      = {};
            this.openStrings = new Set();
            this.mutedStrings= new Set();

            if (this.isScaleMode() && Array.isArray(frame.dots)) {
                // Load from dots array (scale format)
                frame.dots.forEach(d => {
                    this.dotMap[d.s + '_' + d.f] = d.finger ?? 0;
                });
            } else {
                // Load from fret string (chord/sequence format)
                const frets   = sbnParseFretString(frame.frets || 'xxxxxx', this.meta.start_fret);
                const fingers = (frame.fingers || '000000').split('');
                frets.forEach((fv, si) => {
                    if (fv === 'x' || fv === 'X') {
                        this.mutedStrings.add(si);
                    } else if (fv === 0 || fv === '0') {
                        this.openStrings.add(si);
                    } else {
                        const fn   = fingers[si];
                        const fnum = parseInt(fv);
                        if (!isNaN(fnum)) {
                            this.dotMap[si + '_' + fnum] = (fn === 'T' || fn === 't') ? 'T' : (parseInt(fn) || 0);
                        }
                    }
                });
            }

            // Interval labels — stored as comma-separated string
            const ivRaw = frame.interval_labels || '';
            const ivArr = ivRaw.split(',').map(s => s.trim());
            this.ivLabels = Array.from({length:6}, (_,i) => ivArr[i] || '');
        },

        // Rebuild frame data from dotMap and push back to voicings
        syncFrame() {
            const ivStr = this.ivLabels.join(',');
            let updated;

            if (this.isScaleMode()) {
                // Scale: store as dots array — no fret-string encoding needed
                const dots = Object.entries(this.dotMap).map(([key, finger]) => {
                    const [s, f] = key.split('_').map(Number);
                    return { s, f, finger };
                });
                updated = { ...this.currentFrame, dots, interval_labels: ivStr };
            } else {
                // Chord/sequence: encode into frets string (one dot per string)
                const frets   = new Array(6).fill(null);
                const fingers = new Array(6).fill('0');

                for (const [key, finger] of Object.entries(this.dotMap)) {
                    const [si, fn] = key.split('_').map(Number);
                    frets[si]   = fn;
                    fingers[si] = finger === 'T' ? 'T' : (finger || '0');
                }
                for (let i = 0; i < 6; i++) {
                    if (frets[i] === null) {
                        frets[i]   = this.openStrings.has(i)  ? '0'
                                   : this.mutedStrings.has(i) ? 'x' : 'x';
                        fingers[i] = '0';
                    }
                }
                const fretStr   = frets.map(f => {
                    if (f === 'x' || f === '0') return f;
                    const n = parseInt(f);
                    return n >= 10 ? n.toString(16).toUpperCase() : String(n);
                }).join('');
                const fingerStr = fingers.join('');
                updated = { ...this.currentFrame, frets: fretStr, fingers: fingerStr, interval_labels: ivStr };
            }

            this.voicings = [
                ...this.voicings.slice(0, this.activeFrame),
                updated,
                ...this.voicings.slice(this.activeFrame + 1),
            ];
            this.render();
        },

        // ── dot interactions ───────────────────────────────────────

        toggleCell(stringIdx, fretNum) {
            const key = stringIdx + '_' + fretNum;
            if (this.dotMap[key] !== undefined) {
                // Click existing dot → remove it
                delete this.dotMap[key];
            } else {
                if (!this.isScaleMode()) {
                    // Chord mode: only one dot per string — remove any existing on this string
                    for (const k of Object.keys(this.dotMap)) {
                        if (k.startsWith(stringIdx + '_')) delete this.dotMap[k];
                    }
                    this.openStrings.delete(stringIdx);
                    this.mutedStrings.delete(stringIdx);
                }
                this.dotMap[key] = this.activeFinger;
            }
            this.syncFrame();
        },

        setDotFinger(stringIdx, fretNum, finger) {
            const key = stringIdx + '_' + fretNum;
            if (this.dotMap[key] !== undefined) {
                this.dotMap[key] = finger;
                this.syncFrame();
            }
        },

        removeDot(stringIdx, fretNum) {
            delete this.dotMap[stringIdx + '_' + fretNum];
            this.syncFrame();
        },

        cycleOpen(stringIdx) {
            // normal → muted → open → normal
            const isMuted = this.mutedStrings.has(stringIdx);
            const isOpen  = this.openStrings.has(stringIdx);
            this.mutedStrings.delete(stringIdx);
            this.openStrings.delete(stringIdx);
            // Remove any fretted dot on this string
            for (const k of Object.keys(this.dotMap)) {
                if (k.startsWith(stringIdx + '_')) delete this.dotMap[k];
            }
            if (!isMuted && !isOpen) {
                this.mutedStrings.add(stringIdx);
            } else if (isMuted) {
                this.openStrings.add(stringIdx);
            }
            // else: was open → now normal (nothing added)
            this.syncFrame();
        },

        clearFrame() {
            if (!confirm('Clear all dots on this frame?')) return;
            this.dotMap = {};
            this.openStrings = new Set();
            this.mutedStrings = new Set();
            this.syncFrame();
        },

        // ── render ─────────────────────────────────────────────────

        render() {
            const el = document.getElementById('sbnFbeGrid');
            if (!el) return;

            const sf       = this.meta.start_fret;
            const numFrets = this.meta.fret_count;

            // Update theme class
            el.className = 'sbn-fbe-grid theme-' + this.meta.theme;

            let h = '';

            // String name column (top = high e = index 5, bottom = low E = index 0)
            h += '<div class="sbn-fbe-strings">';
            for (let di = 0; di < 6; di++) {
                const si = 5 - di;
                h += `<div class="sbn-fbe-string-name">${FBE_STRING_NAMES[si]}</div>`;
            }
            h += '</div>';

            // Open/mute column
            h += '<div class="sbn-fbe-open-col">';
            for (let di = 0; di < 6; di++) {
                const si = 5 - di;
                const isMuted = this.mutedStrings.has(si);
                const isOpen  = this.openStrings.has(si);
                let cls = 'sbn-fbe-open-cell';
                if (isMuted) cls += ' is-muted';
                if (isOpen)  cls += ' is-open';
                const label = isMuted ? '×' : (isOpen ? '○' : '');
                h += `<div class="${cls}" data-string="${si}" data-action="open">${label}</div>`;
            }
            h += '</div>';

            // Nut (only show when start_fret = 1)
            if (sf === 1) h += '<div class="sbn-fbe-nut"></div>';

            // Fret columns
            h += '<div class="sbn-fbe-frets">';

            // Start fret label when not at nut
            if (sf > 1) {
                h += `<div style="display:flex;flex-direction:column;justify-content:center;padding:0 6px;color:#666;font-size:10px;align-self:center;font-weight:500;">${sf}fr</div>`;
            }

            for (let f = sf; f < sf + numFrets; f++) {
                const hasMarker = FBE_FRET_MARKERS.includes(f);
                const isDouble  = f === 12 || f === 24;
                let colCls = 'sbn-fbe-fret-col';
                if (hasMarker) colCls += ' has-marker';
                if (isDouble)  colCls += ' double-marker';
                h += `<div class="${colCls}" data-fret="${f}">`;

                for (let di = 0; di < 6; di++) {
                    const si  = 5 - di;
                    const key = si + '_' + f;
                    const hasDot = this.dotMap[key] !== undefined;
                    h += `<div class="sbn-fbe-cell" data-string="${si}" data-fret="${f}" data-action="cell">`;
                    if (hasDot) {
                        const finger = this.dotMap[key];
                        const color  = FBE_FINGER_COLORS[finger] || '#888';
                        const label  = (finger !== 0 && finger !== '0') ? finger : '';
                        h += `<div class="sbn-fbe-dot" style="background:linear-gradient(145deg,${color},${color}cc);">${label}</div>`;
                    }
                    h += '</div>';
                }

                const showNum = [1,3,5,7,9,12,15,17,19,21,24].includes(f);
                if (showNum) h += `<div class="sbn-fbe-fret-num">${f}</div>`;

                h += '</div>';
            }
            h += '</div>'; // .sbn-fbe-frets

            // RH fingers column
            if (this.meta.show_rh_fingers) {
                const rh = this._rhFingers();
                h += '<div class="sbn-fbe-rh-col">';
                for (let di = 0; di < 6; di++) {
                    const si = 5 - di;
                    h += `<div class="sbn-fbe-rh-cell">${rh[si] || ''}</div>`;
                }
                h += '</div>';
            }

            el.innerHTML = h;
            this._bindGrid(el);
        },

        _rhFingers() {
            // Build played list from dotMap + open strings
            const played = [];
            for (const key of Object.keys(this.dotMap)) played.push(parseInt(key.split('_')[0]));
            this.openStrings.forEach(si => played.push(si));
            played.sort((a,b) => a-b);
            const out = {};
            if (!played.length) return out;
            out[played[0]] = 'p';
            const upper = played.slice(1).slice(-3);
            ['i','m','a'].forEach((n,i) => { if (upper[i] !== undefined) out[upper[i]] = n; });
            return out;
        },

        _bindGrid(el) {
            el.querySelectorAll('[data-action="cell"]').forEach(cell => {
                cell.addEventListener('click', () => {
                    const si = parseInt(cell.dataset.string);
                    const fn = parseInt(cell.dataset.fret);
                    this.toggleCell(si, fn);
                });
                cell.addEventListener('contextmenu', e => {
                    const si = parseInt(cell.dataset.string);
                    const fn = parseInt(cell.dataset.fret);
                    if (this.dotMap[si+'_'+fn] !== undefined) {
                        e.preventDefault();
                        this.showCtx(e.pageX, e.pageY, si, fn);
                    }
                });
            });
            el.querySelectorAll('[data-action="open"]').forEach(cell => {
                cell.addEventListener('click', () => {
                    this.cycleOpen(parseInt(cell.dataset.string));
                });
            });
        },

        // ── context menu ───────────────────────────────────────────

        showCtx(x, y, si, fn) {
            this.ctxTarget = { si, fn };
            const menu = document.getElementById('sbnFbeCtx');
            menu.style.display = 'block';
            menu.style.left = Math.min(x, window.innerWidth - 200) + 'px';
            menu.style.top  = Math.min(y, window.innerHeight - 140) + 'px';
        },

        hideCtx() {
            document.getElementById('sbnFbeCtx').style.display = 'none';
            this.ctxTarget = null;
        },

        setupCtxMenu() {
            const menu = document.getElementById('sbnFbeCtx');
            menu.querySelectorAll('.sbn-ctx-f').forEach(btn => {
                btn.addEventListener('click', e => {
                    e.stopPropagation();
                    if (!this.ctxTarget) return;
                    this.setDotFinger(this.ctxTarget.si, this.ctxTarget.fn, btn.dataset.finger);
                    this.hideCtx();
                });
            });
            menu.querySelector('.sbn-ctx-remove').addEventListener('click', e => {
                e.stopPropagation();
                if (!this.ctxTarget) return;
                this.removeDot(this.ctxTarget.si, this.ctxTarget.fn);
                this.hideCtx();
            });
            document.addEventListener('click', e => {
                if (!e.target.closest('#sbnFbeCtx')) this.hideCtx();
            });
        },

        // ── submit ─────────────────────────────────────────────────

        submitForm(formEl) {
            this.saving = true;
            formEl.submit();
        },
    };
}
</script>
@endpush
