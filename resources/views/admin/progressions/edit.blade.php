@extends('layouts.admin')

@section('title', $progression ? 'Edit Progression' : 'New Progression')

@section('actions')
    @if($progression && $progression->slug)
        <a href="{{ route('library.progressions.show', $progression->slug) }}" target="_blank" class="sbn-btn sbn-btn-ghost">Preview ↗</a>
    @endif
@endsection

@push('styles')
    <link rel="stylesheet" href="{{ asset('css/progressions.css') }}">
    <link rel="stylesheet" href="{{ asset('css/chord-symbols.css') }}">
    <style>
    .sbn-prog-occ-list { display: flex; flex-direction: column; gap: 10px; }
    .sbn-prog-occ-song {
        background: var(--clr-bg);
        border: 1px solid var(--clr-border);
        border-radius: 8px;
        padding: 10px 14px;
    }
    .sbn-prog-occ-song-header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 12px;
    }
    .sbn-prog-occ-song-info {
        display: flex;
        align-items: center;
        gap: 8px;
        flex-wrap: wrap;
        font-size: 13px;
    }
    .sbn-occ-song-actions { display: flex; gap: 6px; flex-shrink: 0; }

    /* Analysis panel — inline reuse */
    .sbn-analysis-inline { border-top: 1px solid var(--clr-border); padding-top: 16px; margin-top: 10px; }
    .sbn-analysis-panel { padding: 0; }
    .sbn-analysis-loading { text-align: center; padding: 24px; color: var(--clr-text-dim); font-size: 13px; }
    .sbn-analysis-key { font-size: 12px; color: var(--clr-text-dim); margin-bottom: 16px; }
    .sbn-analysis-key strong { color: var(--clr-text); }
    .sbn-analysis-section { margin-bottom: 20px; }
    .sbn-analysis-section:last-child { margin-bottom: 0; }
    .sbn-analysis-section-header {
        display: flex; align-items: center; gap: 8px; margin-bottom: 8px;
        padding: 4px 0; border-bottom: 1px solid var(--clr-border); font-size: 12px;
    }
    .sbn-analysis-section-id {
        display: inline-flex; align-items: center; justify-content: center;
        width: 22px; height: 22px; background: var(--clr-accent);
        color: white; border-radius: 4px; font-weight: 700; font-size: 11px; flex-shrink: 0;
    }
    .sbn-analysis-section-name { font-weight: 600; color: var(--clr-text); }
    .sbn-analysis-section-key { color: var(--clr-text-muted); font-size: 11px; }
    .sbn-analysis-grid {
        display: flex; flex-wrap: wrap; gap: 0;
        border: 1px solid var(--clr-border); border-radius: 6px; overflow: hidden;
    }
    .sbn-analysis-measure {
        flex: 0 0 25%; min-width: 80px; padding: 6px 8px;
        border-right: 1px solid var(--clr-border); border-bottom: 1px solid var(--clr-border);
        background: var(--clr-surface); transition: background 0.15s;
    }
    .sbn-analysis-measure:nth-child(4n) { border-right: none; }
    .sbn-analysis-measure-num { font-size: 9px; color: var(--clr-text-muted); margin-bottom: 2px; font-family: var(--font-mono); }
    .sbn-analysis-chord-row { display: flex; gap: 6px; align-items: flex-start; min-height: 32px; }
    .sbn-analysis-chord-slot { text-align: center; flex: 1; }
    .sbn-analysis-chord-name { font-family: var(--font-mono); font-size: 12px; font-weight: 600; color: var(--clr-text); }
    .sbn-analysis-numeral { font-size: 11px; color: var(--clr-accent); font-weight: 600; font-family: var(--font-mono); }
    .sbn-analysis-numeral.is-unknown { color: var(--clr-text-muted); font-weight: 400; font-style: italic; }
    .sbn-analysis-matches { display: flex; flex-wrap: wrap; gap: 6px; margin-top: 8px; }
    .sbn-analysis-match {
        display: inline-flex; align-items: center; gap: 6px;
        padding: 4px 10px; background: var(--clr-surface); border: 1px solid var(--clr-border);
        border-radius: 6px; font-size: 12px; cursor: default; transition: border-color 0.15s;
    }
    .sbn-analysis-match:hover { border-color: var(--clr-accent); }
    .sbn-analysis-match-cat {
        display: inline-block; padding: 1px 6px; border-radius: 3px;
        font-size: 10px; font-weight: 600; color: white; text-transform: uppercase;
    }
    .sbn-analysis-match-cat.cat-jazz { background: #8b5cf6; }
    .sbn-analysis-match-cat.cat-blues { background: #3b82f6; }
    .sbn-analysis-match-cat.cat-pop { background: #ec4899; }
    .sbn-analysis-match-cat.cat-modal { background: #10b981; }
    .sbn-analysis-match-cat.cat-classical { background: #f59e0b; }
    .sbn-analysis-match-cat.cat-latin { background: #ef4444; }
    .sbn-analysis-match-cat.cat-other { background: #6b7280; }
    .sbn-analysis-match-name { color: var(--clr-text); }
    .sbn-analysis-match-measures { color: var(--clr-text-muted); font-weight: 400; }
    .sbn-analysis-match-confidence { font-size: 10px; color: var(--clr-text-dim); }
    .sbn-analysis-match-root { font-size: 10px; color: var(--clr-text-muted); font-style: italic; }
    .sbn-analysis-measure.is-highlighted { background: var(--clr-accent-bg); }

    /* Alt Numerals */
    .sbn-alt-numerals { margin-top: 24px; border-top: 1px solid var(--clr-border); padding-top: 20px; }
    .sbn-alt-variants { display: flex; flex-direction: column; gap: 12px; margin-top: 12px; }
    .sbn-alt-variant {
        background: var(--clr-surface);
        border: 1px solid var(--clr-border);
        border-radius: 8px;
        padding: 16px;
        position: relative;
    }
    .sbn-alt-variant-header { display: flex; gap: 12px; margin-bottom: 12px; }
    .sbn-alt-variant-num { width: 100%; }
    .sbn-alt-variant-actions { margin-top: 8px; display: flex; justify-content: flex-end; }
    .sbn-section-subtitle { font-size: 14px; font-weight: 700; color: var(--clr-text); margin-bottom: 4px; text-transform: uppercase; letter-spacing: 0.03em; }
</style>
@endpush

@section('content')
<div class="sbn-prog-edit" x-data="progressionForm()"
     @sbn:snippets-changed="$refs.videoSnippetsInput.value = JSON.stringify($event.detail)">

    {{-- ── Back Link ─────────────────────────────────────────── --}}
    <a href="{{ route('admin.progressions.index') }}" class="sbn-back-link">
        <svg width="16" height="16" viewBox="0 0 20 20" fill="currentColor">
            <path fill-rule="evenodd" d="M9.707 16.707a1 1 0 01-1.414 0l-6-6a1 1 0 010-1.414l6-6a1 1 0 011.414 1.414L5.414 9H17a1 1 0 110 2H5.414l4.293 4.293a1 1 0 010 1.414z" clip-rule="evenodd"/>
        </svg>
        Back to Library
    </a>

    {{-- ── Form Card ─────────────────────────────────────────── --}}
    <div class="sbn-form-card">
        <h2 class="sbn-form-title">{{ $progression ? 'Edit Progression' : 'Add New Progression' }}</h2>

        <form method="POST"
              action="{{ $progression ? route('admin.progressions.update', $progression) : route('admin.progressions.store') }}">
            @csrf
            @if($progression) @method('PUT') @endif

            {{-- ── Name ──────────────────────────────────────────── --}}
            <div class="sbn-field">
                <label class="sbn-label" for="prog_name">Name</label>
                <input type="text" id="prog_name" name="name" class="sbn-input"
                       value="{{ old('name', $progression->name ?? '') }}"
                       placeholder="e.g. ii–V–I (major)" required>
                @error('name') <span class="sbn-field-error">{{ $message }}</span> @enderror
            </div>

            {{-- ── Row: Category + Tonality + Match Mode ─────────── --}}
            <div class="sbn-field-row">
                <div class="sbn-field">
                    <label class="sbn-label" for="prog_category">Category</label>
                    <select id="prog_category" name="category" class="sbn-select sbn-select-full">
                        @foreach(\App\Models\ChordProgression::CATEGORIES as $cat)
                            <option value="{{ $cat }}" {{ old('category', $progression->category ?? 'jazz') === $cat ? 'selected' : '' }}>
                                {{ \App\Models\ChordProgression::CATEGORY_LABELS[$cat] ?? ucwords(str_replace('-', ' ', $cat)) }}
                            </option>
                        @endforeach
                    </select>
                </div>
                <div class="sbn-field">
                    <label class="sbn-label" for="prog_tonality">Tonality</label>
                    <select id="prog_tonality" name="tonality" class="sbn-select sbn-select-full">
                        <option value="both" {{ old('tonality', $progression->tonality ?? 'both') === 'both' ? 'selected' : '' }}>Both (major & minor)</option>
                        <option value="major" {{ old('tonality', $progression->tonality ?? '') === 'major' ? 'selected' : '' }}>Major only</option>
                        <option value="minor" {{ old('tonality', $progression->tonality ?? '') === 'minor' ? 'selected' : '' }}>Minor only</option>
                    </select>
                </div>
                <div class="sbn-field">
                    <label class="sbn-label" for="prog_match_mode">Match Mode</label>
                    <select id="prog_match_mode" name="match_mode" class="sbn-select sbn-select-full">
                        <option value="strict" {{ old('match_mode', $progression->match_mode ?? 'strict') === 'strict' ? 'selected' : '' }}>Strict (quality must match)</option>
                        <option value="degree" {{ old('match_mode', $progression->match_mode ?? '') === 'degree' ? 'selected' : '' }}>Degree only (any quality)</option>
                    </select>
                </div>
            </div>

            {{-- ── Numeral Sequence ──────────────────────────────── --}}
            <div class="sbn-field">
                <label class="sbn-label" for="prog_numerals">Numeral Sequence</label>
                <input type="text" id="prog_numerals" name="numerals" class="sbn-input sbn-input-mono"
                       value="{{ old('numerals', $progression->numerals ?? '') }}"
                       placeholder="e.g. IIm7,V7,Imaj7"
                       x-model="numerals"
                       @input="renderPreview()"
                       required>
                <p class="sbn-field-hint">
                    Comma-separated Roman numeral tokens. Use uppercase for major, lowercase prefix for minor
                    (e.g. <code>IIm7,V7,Imaj7</code>). Chromatic alterations: <code>bVII7</code>, <code>bII</code>.
                </p>
                <div class="sbn-numeral-preview" x-html="previewHtml"></div>
                @error('numerals') <span class="sbn-field-error">{{ $message }}</span> @enderror
            </div>

            {{-- ── Alternative Sequences ──────────────────────────── --}}
            <div class="sbn-alt-numerals">
                <h3 class="sbn-section-subtitle">Alternative Sequences</h3>
                <p class="sbn-field-hint">Add variations of this progression (e.g. simplified versions or different resolutions) that should also be detected.</p>

                <div class="sbn-alt-variants">
                    <template x-for="(variant, index) in alt_numerals" :key="index">
                        <div class="sbn-alt-variant">
                            <div class="sbn-alt-variant-header">
                                <div style="flex: 0 0 180px;">
                                    <label class="sbn-label" :for="'alt_label_' + index">Label</label>
                                    <input type="text" :id="'alt_label_' + index" :name="'alt_numerals[' + index + '][label]'"
                                           class="sbn-input" placeholder="e.g. Simplified" x-model="variant.label">
                                </div>
                                <div class="sbn-alt-variant-num">
                                    <label class="sbn-label" :for="'alt_num_' + index">Numeral Sequence</label>
                                    <input type="text" :id="'alt_num_' + index" :name="'alt_numerals[' + index + '][numerals]'"
                                           class="sbn-input sbn-input-mono" placeholder="e.g. V7,Imaj7"
                                           x-model="variant.numerals" @input="renderAltPreview(index)">
                                </div>
                            </div>
                            <div class="sbn-numeral-preview" x-html="variant.previewHtml"></div>
                            <div class="sbn-alt-variant-actions">
                                <button type="button" class="sbn-btn-remove-alt" @click="removeVariant(index)">
                                    Remove Variant
                                </button>
                            </div>
                        </div>
                    </template>
                </div>

                <button type="button" class="sbn-btn sbn-btn-secondary" style="margin-top: 12px; font-size: 12px;" @click="addVariant()">
                    + Add Alternative Sequence
                </button>
            </div>

            {{-- ── Description ───────────────────────────────────── --}}
            <div class="sbn-field"
                 x-data="{ descHtml: {{ Js::from(old('description', $progression->description ?? '')) }} }"
                 x-init="document.addEventListener('desc-editor:save:prog', (e) => { descHtml = e.detail; })">
                <label class="sbn-label">Description</label>
                <input type="hidden" name="description" :value="descHtml">
                <div class="sbn-desc-preview" x-html="descHtml || '<span style=\'color:var(--clr-text-muted);font-style:italic\'>No description yet…</span>'"></div>
                <button type="button" class="sbn-btn sbn-btn-secondary" style="margin-top:8px;font-size:12px;"
                        data-prog-meta='{!! htmlspecialchars(json_encode([
                            'name'     => $progression->name     ?? '',
                            'numerals' => $progression->numerals ?? '',
                            'category' => $progression->category ?? '',
                            'tonality' => $progression->tonality ?? '',
                        ]), ENT_QUOTES) !!}'
                        @click="window.__descEditor.open({ initial: descHtml, eventName: 'desc-editor:save:prog', placeholder: 'Educational explanation: what this progression sounds like…', entityType: 'progression', entityMeta: JSON.parse($el.dataset.progMeta) })">
                    Edit Description
                </button>
            </div>


            {{-- ── Hashtags ──────────────────────────────────────── --}}
            <div class="sbn-field">
                <label class="sbn-label">Hashtags</label>
                <input type="hidden" name="tags" :value="tags.join(',')">

                {{-- Active tags as removable chips --}}
                <div class="sbn-tags-active">
                    <template x-if="tags.length === 0">
                        <span class="sbn-tags-none">No hashtags yet — click below to add</span>
                    </template>
                    <template x-for="tag in tags" :key="tag">
                        <span class="sbn-tag-chip">
                            <span x-text="'#' + tag"></span>
                            <button type="button" class="sbn-tag-remove" @click="removeTag(tag)">×</button>
                        </span>
                    </template>
                </div>

                {{-- Preset palette --}}
                <p class="sbn-field-hint" style="margin: 8px 0 4px;">Click to add:</p>
                <div class="sbn-tags-palette">
                    @foreach(\App\Models\ChordProgression::PRESET_TAGS as $preset)
                        <button type="button"
                                class="sbn-tag-preset"
                                :class="tags.includes('{{ $preset }}') && 'is-active'"
                                @click="toggleTag('{{ $preset }}')">
                            #{{ $preset }}
                        </button>
                    @endforeach
                </div>

                {{-- Custom tag input --}}
                <div class="sbn-tags-custom">
                    <input type="text" class="sbn-input" placeholder="Custom hashtag…" style="max-width: 200px;"
                           x-ref="customTag"
                           @keydown.enter.prevent="addCustomTag()">
                    <button type="button" class="sbn-btn sbn-btn-secondary" style="padding: 7px 14px;" @click="addCustomTag()">Add</button>
                </div>
                <p class="sbn-field-hint">Hashtags are cross-site — clicking one shows all songs, progressions, rhythms and courses tagged with it.</p>
            </div>

            {{-- ── Row: Sort Order + Featured ────────────────────── --}}
            <div class="sbn-field-row">
                <div class="sbn-field" style="max-width: 120px;">
                    <label class="sbn-label" for="prog_sort">Sort Order</label>
                    <input type="number" id="prog_sort" name="sort_order" class="sbn-input"
                           value="{{ old('sort_order', $progression->sort_order ?? 0) }}">
                </div>
                <div class="sbn-field" style="padding-top: 28px;">
                    <label class="sbn-checkbox-label">
                        <input type="hidden" name="featured" value="0">
                        <input type="checkbox" name="featured" value="1"
                               {{ old('featured', $progression->featured ?? false) ? 'checked' : '' }}>
                        <span>Featured</span>
                    </label>
                </div>
            </div>

            {{-- ── Video Examples ────────────────────────────────── --}}
            <div class="sbn-field">
                {{-- Hidden field submitted with the classic form POST.
                     The snippet widget writes its JSON here via the
                     sbn:snippets-changed event (mirrors the tags pattern). --}}
                <input type="hidden" name="video_snippets" x-ref="videoSnippetsInput"
                       value="{{ old('video_snippets', json_encode($progression->video_snippets ?? [])) }}">
@php
    $numeralTokens = $progression
        ? array_values(array_filter(array_map('trim', explode(',', $progression->numerals ?? ''))))
        : [];
@endphp
                @include('admin._partials.video-snippets', [
                    'snippets'    => $progression->video_snippets ?? [],
                    'beatsPerBar' => 4,
                    'numerals'    => $numeralTokens,
                ])
                @error('video_snippets') <span class="sbn-field-error">{{ $message }}</span> @enderror
            </div>

            {{-- ── Actions ───────────────────────────────────────── --}}
            <div class="sbn-form-actions">
                <button type="submit" class="sbn-btn sbn-btn-primary">
                    {{ $progression ? 'Save Changes' : 'Add Progression' }}
                </button>
                <a href="{{ route('admin.progressions.index') }}" class="sbn-btn sbn-btn-secondary">Cancel</a>
            </div>

            @if($errors->any())
                <div class="sbn-form-errors">
                    @foreach($errors->all() as $error)
                        <p>{{ $error }}</p>
                    @endforeach
                </div>
            @endif
        </form>
    </div>

    {{-- ── Occurrences in Songs ────────────────────────────────── --}}
    @if($progression && isset($occurrences) && $occurrences->isNotEmpty())
    <div class="sbn-form-card" style="margin-top: 24px;">
        <h2 class="sbn-form-title">
            Found in Songs
            <span style="font-weight:400; font-size:13px; color:var(--clr-text-dim);">
                {{ $occurrences->count() }} occurrence{{ $occurrences->count() !== 1 ? 's' : '' }}
            </span>
        </h2>

        @php
            $occByLeadsheet = $occurrences->groupBy('leadsheet_id');
        @endphp

        <div class="sbn-prog-occ-list">
            @foreach($occByLeadsheet as $lsId => $lsOccs)
            <div class="sbn-prog-occ-song" x-data="{ showAnalysis: false, analysisData: null, analysisLoading: false, highlightMatch: null }">
                <div class="sbn-prog-occ-song-header">
                    <div class="sbn-prog-occ-song-info">
                        <strong>{{ $lsOccs->first()->leadsheet_title }}</strong>
                        @if($lsOccs->first()->song_key)
                            <span class="sbn-occ-key">{{ $lsOccs->first()->song_key }}</span>
                        @endif
                        <span style="font-size:11px; color:var(--clr-text-muted);">
                            {{ $lsOccs->count() }}× ·
                            @foreach($lsOccs as $i => $occ)
                                {{ $occ->section_id }} m{{ $occ->start_measure + 1 }}{{ $i < $lsOccs->count() - 1 ? ',' : '' }}
                            @endforeach
                        </span>
                    </div>
                    <div class="sbn-occ-song-actions">
                        <button class="sbn-btn-analysis"
                                @click="showAnalysis = !showAnalysis; if (showAnalysis && !analysisData) loadAnalysis($el, {{ $lsId }})"
                                :class="showAnalysis && 'is-active'">
                            <span x-text="showAnalysis ? 'Hide Analysis' : 'Analysis'"></span>
                        </button>
                        <a href="{{ route('admin.leadsheets.edit', $lsId) }}" class="sbn-btn-sm">Edit</a>
                    </div>
                </div>

                {{-- Inline Analysis --}}
                <template x-if="showAnalysis">
                    <div class="sbn-analysis-panel sbn-analysis-inline">
                        <div class="sbn-analysis-loading" x-show="analysisLoading">
                            🔍 Analysing harmonic structure…
                        </div>
                        <template x-if="analysisData && !analysisLoading">
                            <div>
                                <div class="sbn-analysis-key">
                                    Song key: <strong x-text="analysisData.song_key"></strong>
                                </div>
                                <template x-for="(section, asi) in analysisData.sections" :key="asi">
                                    <div class="sbn-analysis-section">
                                        <div class="sbn-analysis-section-header">
                                            <div class="sbn-analysis-section-id" x-text="section.section_id"></div>
                                            <span class="sbn-analysis-section-name" x-text="section.section_name"></span>
                                            <span class="sbn-analysis-section-key" x-text="'Key: ' + section.key"></span>
                                        </div>
                                        <div class="sbn-analysis-grid">
                                            <template x-for="(mChords, mi) in section.measure_numerals" :key="mi">
                                                <div class="sbn-analysis-measure"
                                                     :class="{ 'is-highlighted': sbnIsMeasureHighlighted(highlightMatch, asi, parseInt(mi)) }">
                                                    <div class="sbn-analysis-measure-num" x-text="parseInt(mi) + 1"></div>
                                                    <div class="sbn-analysis-chord-row">
                                                        <template x-for="(slot, ci) in mChords" :key="ci">
                                                            <div class="sbn-analysis-chord-slot">
                                                                <div class="sbn-analysis-chord-name" x-html="sbnStyledChord(slot.chord)"></div>
                                                                <div class="sbn-analysis-numeral"
                                                                     :class="{ 'is-unknown': slot.numeral === '?' || slot.numeral.startsWith('chr') }"
                                                                     x-text="sbnFormatNumeral(slot.numeral)"></div>
                                                            </div>
                                                        </template>
                                                    </div>
                                                </div>
                                            </template>
                                        </div>
                                        <div class="sbn-analysis-matches" x-show="section.matches && section.matches.length">
                                            <template x-for="(match, mti) in section.matches" :key="mti">
                                                <div class="sbn-analysis-match"
                                                     @mouseenter="highlightMatch = sbnBuildHighlight(analysisData, asi, match)"
                                                     @mouseleave="highlightMatch = null">
                                                    <span class="sbn-analysis-match-cat" :class="'cat-' + match.category" x-text="match.category"></span>
                                                    <span class="sbn-analysis-match-name" x-text="match.name"></span>
                                                    <span class="sbn-analysis-match-measures"
                                                          x-text="'m' + (match.start_measure + 1) + (match.end_measure > match.start_measure ? '–' + (match.end_measure + 1) : '')"></span>
                                                    <span class="sbn-analysis-match-root" x-text="'root: ' + match.detected_root"></span>
                                                    <span class="sbn-analysis-match-confidence" x-text="Math.round(match.confidence * 100) + '%'"></span>
                                                </div>
                                            </template>
                                        </div>
                                    </div>
                                </template>
                            </div>
                        </template>
                    </div>
                </template>
            </div>
            @endforeach
        </div>
    </div>
    @endif
</div>
@endsection

@push('scripts')
<div id="desc-editor-root"></div>
@vite('resources/js/admin/description-editor.ts')
<script src="{{ asset('js/sbn-chord-name.js') }}"></script>
<script src="{{ asset('js/sbn-snippet-editor.js') }}"></script>
<script>
function sbnStyledChord(name) {
    if (typeof sbnFormatChord === 'function') return sbnFormatChord(name);
    const d = document.createElement('span');
    d.textContent = name;
    return d.innerHTML;
}
/* ── Analysis Helpers ────────────────────────────────────── */
async function loadAnalysis(el, leadsheetId) {
    const scope = Alpine.$data(el.closest('[x-data]'));
    if (scope.analysisData) return;
    scope.analysisLoading = true;
    try {
        const resp = await fetch('/api/admin/leadsheets/' + leadsheetId + '/analyse-progressions');
        const data = await resp.json();
        if (data.success) { scope.analysisData = data.data; }
    } catch (e) {
        console.error('Analysis failed:', e);
    }
    scope.analysisLoading = false;
}
function sbnFormatNumeral(n) {
    if (!n) return '?';
    return n.replace('maj7','\u25B37').replace('m7b5','\u00F87').replace(/o7$/,'\u00B07');
}
function sbnIsMeasureHighlighted(hl, si, mi) {
    if (!hl) return false;
    for (const r of hl.ranges) { if (r.section === si && mi >= r.start && mi <= r.end) return true; }
    return false;
}
function sbnBuildHighlight(data, si, match) {
    const ranges = [{ section: si, start: match.start_measure, end: match.end_measure }];
    if (data) {
        const sec = data.sections[si];
        if (sec && sec.resolutions) {
            for (const res of sec.resolutions) {
                if (res.from_progression === match.name) {
                    const ti = data.sections.findIndex(s => s.section_id === res.target_section_id);
                    if (ti >= 0) ranges.push({ section: ti, start: res.start_measure, end: res.end_measure });
                }
            }
        }
    }
    return { ranges };
}
</script>
<script>
function progressionForm() {
    const savedTags = '{{ old('tags', $progression->tags ?? '') }}';

    return {
        numerals: '{{ old('numerals', $progression->numerals ?? '') }}',
        previewHtml: '',
        alt_numerals: @json(old('alt_numerals', $progression->alt_numerals ?? [])),
        tags: savedTags ? savedTags.split(',').map(t => t.trim()).filter(Boolean) : [],

        init() {
            this.renderPreview();
            this.alt_numerals.forEach((v, i) => this.renderAltPreview(i));
        },

        renderPreview() {
            const raw = this.numerals;
            const tokens = raw.split(',').map(t => t.trim()).filter(Boolean);
            if (tokens.length === 0) {
                this.previewHtml = '';
                return;
            }

            // Check for possibly concatenated tokens
            const concatWarning = tokens.some(t => {
                const stripped = t.replace(/^[b#]/, '');
                const roots = stripped.match(/(?<![IVXivx])[IVX][IVXivx]*/g) || [];
                return roots.length > 1;
            });

            let html = tokens.map(t =>
                '<span class="sbn-numeral-chip">' + this.escHtml(t) + '</span>'
            ).join('');

            if (concatWarning) {
                html += '<div class="sbn-numeral-warning">⚠ Looks like tokens are missing commas — separate each numeral with a comma, e.g. <code>IIm7,V7,Imaj7</code></div>';
            }
            this.previewHtml = html;
        },

        renderAltPreview(index) {
            const v = this.alt_numerals[index];
            if (!v) return;

            const tokens = (v.numerals || '').split(',').map(t => t.trim()).filter(Boolean);
            if (tokens.length === 0) {
                v.previewHtml = '';
                return;
            }

            v.previewHtml = tokens.map(t =>
                '<span class="sbn-numeral-chip">' + this.escHtml(t) + '</span>'
            ).join('');
        },

        addVariant() {
            this.alt_numerals.push({ label: '', numerals: '', previewHtml: '' });
        },

        removeVariant(index) {
            this.alt_numerals.splice(index, 1);
        },

        toggleTag(tag) {
            const idx = this.tags.indexOf(tag);
            if (idx === -1) {
                this.tags.push(tag);
            } else {
                this.tags.splice(idx, 1);
            }
        },

        removeTag(tag) {
            this.tags = this.tags.filter(t => t !== tag);
        },

        addCustomTag() {
            const val = this.$refs.customTag.value.trim().toLowerCase();
            if (val && !this.tags.includes(val)) {
                this.tags.push(val);
            }
            this.$refs.customTag.value = '';
            this.$refs.customTag.focus();
        },

        escHtml(s) {
            return s.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
        },
    };
}
</script>
@endpush
