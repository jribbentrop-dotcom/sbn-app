{{--
    Shared video-snippet authoring widget.
    See docs/Video-Sync-Snippet-Integration-Plan.md §0.5 step 2.

    Include inside an Alpine editor page:

        @include('admin._partials.video-snippets', [
            'snippets'    => $pattern->video_snippets ?? [],
            'beatsPerBar' => 4,
        ])

    The host editor must:
      - load /js/sbn-snippet-editor.js
      - keep `form.video_snippets` in its x-data and let this widget write to it
        via the onChange callback wired below.
--}}
@php
    $snippets    = $snippets ?? [];
    $beatsPerBar = $beatsPerBar ?? 4;
    // numerals: array of Roman numeral label strings e.g. ['IIm7','V7','Imaj7'].
    // Only passed from the progression edit page; rhythm pages leave it absent.
    $numerals    = $numerals ?? [];
@endphp

<div class="sbn-editor-card"
     x-data="snippetEditor(
        {{ \Illuminate\Support\Js::from($snippets) }},
        { beatsPerBar: {{ (int) $beatsPerBar }}, numerals: {{ \Illuminate\Support\Js::from($numerals) }} }
     )">
    <div class="sbn-editor-card-header">
        <h3>Video Examples</h3>
    </div>
    <div class="sbn-editor-card-body">

        {{-- ── List view ─────────────────────────────────────── --}}
        <template x-if="!draft">
            <div>
                <template x-if="snippets.length === 0">
                    <p class="sbn-form-hint" style="margin: 0 0 12px;">
                        No video examples yet. Add a real recording that
                        demonstrates this component.
                    </p>
                </template>

                <ul style="list-style:none;margin:0 0 12px;padding:0;display:flex;flex-direction:column;gap:8px;">
                    <template x-for="(s, i) in snippets" :key="s.id">
                        <li style="display:flex;align-items:center;gap:8px;padding:8px 10px;border:1px solid var(--clr-border);border-radius:8px;">
                            <div style="flex:1;min-width:0;">
                                <strong x-text="s.label || 'Untitled'" style="font-size:13px;display:block;"></strong>
                                <span class="sbn-form-hint" style="margin:0;"
                                      x-text="s.videoId + ' · ' + fmtTime(s.startSec) + '–' + fmtTime(s.endSec) + ' · ' + s.tempoBpm + ' BPM'"></span>
                            </div>
                            <button type="button" class="sbn-btn sbn-btn-secondary" style="padding:4px 10px;font-size:12px;"
                                    @click="editSnippet(i)">Edit</button>
                            <button type="button" class="sbn-btn sbn-btn-secondary" style="padding:4px 10px;font-size:12px;"
                                    @click="removeSnippet(i)">Remove</button>
                        </li>
                    </template>
                </ul>

                <button type="button" class="sbn-btn sbn-btn-secondary" style="width:100%;justify-content:center;"
                        @click="startNew()">+ Add Video Example</button>
            </div>
        </template>

        {{-- ── Draft editor ──────────────────────────────────── --}}
        <template x-if="draft">
            <div>
                {{-- Label --}}
                <div class="sbn-form-group">
                    <label>Label</label>
                    <input type="text" class="sbn-search-input" style="padding-left:14px;"
                           x-model="draft.label"
                           placeholder="e.g. Jobim — live 1965">
                    <p class="sbn-form-hint">Shown in the course editor's video picker.</p>
                </div>

                {{-- URL / ID + load --}}
                <div class="sbn-form-group">
                    <label>YouTube URL or ID</label>
                    <div style="display:flex;gap:8px;">
                        <input type="text" class="sbn-search-input" style="padding-left:14px;flex:1;"
                               x-model="urlInput"
                               @keydown.enter.prevent="loadVideo()"
                               placeholder="https://youtu.be/…">
                        <button type="button" class="sbn-btn sbn-btn-secondary"
                                @click="loadVideo()">Load</button>
                    </div>
                </div>

                {{-- Embed preview --}}
                <div x-show="draft.videoId" x-cloak style="margin:12px 0;">
                    <div style="position:relative;aspect-ratio:16/9;background:#000;border-radius:8px;overflow:hidden;">
                        <div x-ref="ytMount" style="position:absolute;inset:0;width:100%;height:100%;"></div>
                    </div>
                    <p class="sbn-form-hint" style="margin:6px 0 0;">
                        Playhead:
                        <span x-text="fmtTime(currentTime)" style="font-family:var(--font-mono);"></span>
                    </p>
                </div>

                {{-- Anchor: mark start / end --}}
                <div class="sbn-form-row sbn-form-row-2">
                    <div class="sbn-form-group">
                        <label>Start</label>
                        <div style="display:flex;gap:8px;align-items:center;">
                            <button type="button" class="sbn-btn sbn-btn-secondary" style="font-size:12px;"
                                    :disabled="!playerReady" @click="markStart()">Mark start</button>
                            <span x-text="fmtTime(draft.startSec)" style="font-family:var(--font-mono);font-size:13px;"></span>
                        </div>
                    </div>
                    <div class="sbn-form-group">
                        <label>End</label>
                        <div style="display:flex;gap:8px;align-items:center;">
                            <button type="button" class="sbn-btn sbn-btn-secondary" style="font-size:12px;"
                                    :disabled="!playerReady" @click="markEnd()">Mark end</button>
                            <span x-text="fmtTime(draft.endSec)" style="font-family:var(--font-mono);font-size:13px;"></span>
                        </div>
                    </div>
                </div>

                {{-- Key + pinned voicings (progression pages only).
                     Uses x-show (not x-if) to avoid Alpine scope-loss when
                     x-for is nested inside multiple <template x-if> levels. --}}
                <div x-show="numerals.length > 0" x-cloak>
                    <div class="sbn-form-group">
                        <label>Key</label>
                        {{-- Options are static (not x-for) so they exist before
                             x-model binds. An x-for-generated option list races
                             the x-model: the select settles to its first option
                             ("C") and writes that back into draft.key. --}}
                        <select class="sbn-search-input" style="padding-left:14px;max-width:120px;" x-model="draft.key">
                            @foreach(['C','Db','D','Eb','E','F','F#','G','Ab','A','Bb','B'] as $k)
                                <option value="{{ $k }}">{{ $k }}</option>
                            @endforeach
                        </select>
                        <p class="sbn-form-hint">Key the musician plays in on this recording.</p>
                    </div>

                    <div class="sbn-form-group">
                        <label>Chord voicings</label>
                        <p class="sbn-form-hint" style="margin-bottom:8px;">
                            Search the chord library for each slot. Leave blank to use the builder's default voicing.
                        </p>
                        {{-- Chord slots rendered per numeral. Each slot is its own
                             x-data island to keep the scope flat and avoid the
                             x-for-inside-x-if Alpine 3 scope-loss bug. --}}
                        <div id="sbn-chord-slots" style="display:flex;flex-direction:column;gap:10px;"
                             x-ref="chordSlots"></div>
                    </div>
                </div>

                {{-- Tempo --}}
                <div class="sbn-form-group">
                    <label>Tempo (BPM)</label>
                    <input type="number" class="sbn-search-input" style="padding-left:14px;"
                           x-model.number="draft.tempoBpm" min="20" max="300">
                    <p class="sbn-form-hint">
                        Drives the seconds → beats projection.
                        <span x-show="draftBarCount > 0"
                              x-text="'Spans ≈ ' + draftBarCount.toFixed(1) + ' bars (max ' + maxBars + ').'"
                              :style="draftBarCount > maxBars ? 'color:var(--clr-danger,#dc2626);font-weight:600;' : ''"></span>
                    </p>
                </div>

                {{-- Draft actions --}}
                <div style="display:flex;gap:8px;margin-top:8px;">
                    <button type="button" class="sbn-btn sbn-btn-primary" style="flex:1;justify-content:center;"
                            @click="saveDraft()">Save Example</button>
                    <button type="button" class="sbn-btn sbn-btn-secondary"
                            x-show="playerReady" @click="previewStart()">Preview ▶</button>
                    <button type="button" class="sbn-btn sbn-btn-secondary"
                            @click="cancelDraft()">Cancel</button>
                </div>
                <p x-show="draftError" x-cloak class="sbn-form-hint"
                   style="color:var(--clr-danger,#dc2626);margin-top:6px;" x-text="draftError"></p>
            </div>
        </template>

    </div>
</div>
