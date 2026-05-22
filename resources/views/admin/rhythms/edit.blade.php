@extends('layouts.admin')

@section('title', $isNew ? 'Create Pattern' : 'Edit: ' . $pattern->name)

@section('actions')
    <a href="{{ route('admin.rhythms.index') }}" class="sbn-btn sbn-btn-secondary"><- Back to List</a>
@endsection

@push('styles')
    <link rel="stylesheet" href="{{ asset('css/rhythms.css') }}">
@endpush

@section('content')

<div class="editor-layout"
     x-data="rhythmEditor()"
     x-init="init()"
     @sbn:snippets-changed="form.video_snippets = $event.detail">

    {{-- -- Left: Main Form -- --}}
    <div class="editor-main">
        <div class="sbn-editor-card">
            <div class="sbn-editor-card-header">
                <h2>{{ $isNew ? 'New Pattern' : 'Pattern Details' }}</h2>
            </div>
            <div class="sbn-editor-card-body">

                {{-- Name --}}
                <div class="sbn-form-row">
                    <div class="sbn-form-group">
                        <label for="name">Name</label>
                        <input type="text" id="name" class="sbn-search-input" style="padding-left: 14px;"
                               x-model="form.name"
                               placeholder="e.g. Bossa Nova">
                    </div>
                </div>

                {{-- Category + Time Sig + BPM --}}
                <div class="sbn-form-row sbn-form-row-3">
                    <div class="sbn-form-group">
                        <label for="category">Category</label>
                        <input type="text" id="category" class="sbn-search-input" style="padding-left: 14px;"
                               x-model="form.category"
                               list="cat-list" placeholder="e.g. brazilian">
                        <datalist id="cat-list">
                            <option value="brazilian">
                            <option value="jazz">
                            <option value="latin">
                            <option value="general">
                        </datalist>
                    </div>
                    <div class="sbn-form-group">
                        <label for="time_signature">Time Signature</label>
                        <select id="time_signature" class="sbn-select" style="width: 100%;"
                                x-model="form.time_signature"
                                @change="onGridSettingsChange()">
                            <option value="2/4">2/4</option>
                            <option value="3/4">3/4</option>
                            <option value="4/4">4/4</option>
                            <option value="6/8">6/8</option>
                        </select>
                    </div>
                    <div class="sbn-form-group">
                        <label for="default_bpm">Default BPM</label>
                        <input type="number" id="default_bpm" class="sbn-search-input" style="padding-left: 14px;"
                               x-model.number="form.default_bpm"
                               min="40" max="240">
                    </div>
                </div>

                {{-- Description --}}
                <div class="sbn-form-group">
                    <label for="description">Description</label>
                    <textarea id="description" class="sbn-search-input" style="padding-left: 14px; resize: vertical;" rows="2"
                              x-model="form.description"
                              placeholder="Brief description of this pattern"></textarea>
                </div>

                <hr style="border: none; border-top: 1px solid var(--clr-border); margin: 20px 0;">

                <h3 style="margin: 0 0 16px; font-size: 15px; font-weight: 600; color: var(--clr-text);">
                    Pattern Editor
                </h3>

                {{-- Bars + Subdivision --}}
                <div class="sbn-form-row sbn-form-row-2">
                    <div class="sbn-form-group">
                        <label for="bars">Bars</label>
                        <select id="bars" class="sbn-select" style="width: 100%;"
                                x-model="bars"
                                @change="onGridSettingsChange()">
                            <option value="1">1 bar</option>
                            <option value="2">2 bars</option>
                        </select>
                    </div>
                    <div class="sbn-form-group">
                        <label for="grid_type">Subdivision</label>
                        <select id="grid_type" class="sbn-select" style="width: 100%;"
                                x-model="form.grid_type"
                                @change="onGridSettingsChange()">
                            <option value="eighth">8th notes</option>
                            <option value="sixteenth">16th notes</option>
                            <option value="triplet">Triplets</option>
                        </select>
                    </div>
                </div>

                {{-- Percussion sample selectors --}}
                <div class="sbn-form-row sbn-form-row-2">
                    <div class="sbn-form-group">
                        <label for="perc_top">Sample -- Fingers row</label>
                        <select id="perc_top" class="sbn-select" style="width: 100%;" x-model="form.perc_top">
                            <option value="none">-- None --</option>
                            <optgroup label="Brazilian">
                                <option value="shaker">Shaker</option>
                                <option value="tamborim">Tamborim</option>
                            </optgroup>
                            <optgroup label="Jazz">
                                <option value="hihat-brush">Hi-Hat (Brush)</option>
                                <option value="brush-snare">Brush Snare</option>
                            </optgroup>
                        </select>
                    </div>
                    <div class="sbn-form-group">
                        <label for="perc_bass">Sample -- Thumb/Bass row</label>
                        <select id="perc_bass" class="sbn-select" style="width: 100%;" x-model="form.perc_bass">
                            <option value="none">-- None --</option>
                            <option value="kick">Kick / Bass Drum</option>
                        </select>
                    </div>
                </div>

                {{-- Interactive Grid --}}
                <div class="grid-editor" id="gridEditor">
                    <template x-if="true">
                        <div>
                            <div class="grid-editor-row">
                                <span class="grid-editor-label"></span>
                                <template x-for="(label, i) in gridLabels" :key="'lbl-'+i">
                                    <div class="grid-editor-cell is-beat" x-text="label"></div>
                                </template>
                            </div>

                            <div class="grid-editor-row">
                                <span class="grid-editor-label">Rhythm</span>
                                <template x-for="(c, i) in rhythmArr" :key="'r-'+i">
                                    <div class="grid-editor-cell"
                                         :class="{
                                             'is-hit': c === 'x' || c === 'X',
                                             'is-accent': c === 'X',
                                             'is-current': currentBeat === i && isPlaying,
                                         }"
                                         @click="cycleRhythm(i)"
                                         x-text="c.toLowerCase() === 'x' ? '●' : ''">
                                    </div>
                                </template>
                            </div>

                            <div class="grid-editor-row">
                                <span class="grid-editor-label">Thumb</span>
                                <template x-for="(c, i) in thumbArr" :key="'t-'+i">
                                    <div class="grid-editor-cell is-thumb"
                                         :class="{
                                             'is-hit': c.toLowerCase() === 'x',
                                             'is-current': currentBeat === i && isPlaying,
                                         }"
                                         @click="toggleThumb(i)"
                                         x-text="c.toLowerCase() === 'x' ? '●' : ''">
                                    </div>
                                </template>
                            </div>
                        </div>
                    </template>
                </div>

                {{-- Raw pattern toggle --}}
                <div style="margin-top: 12px;">
                    <button type="button" style="font-size: 12px; color: var(--clr-text-muted); background: none; border: none; cursor: pointer; font-family: var(--font-body);"
                            @click="showRaw = !showRaw"
                            x-text="showRaw ? '▲ hide raw pattern' : '▼ edit raw pattern'">
                    </button>
                    <div x-show="showRaw" x-cloak style="margin-top: 8px;">
                        <div class="sbn-form-row sbn-form-row-2">
                            <div class="sbn-form-group">
                                <label>Fingers pattern</label>
                                <input type="text" class="sbn-search-input" style="padding-left: 14px; font-family: var(--font-mono); letter-spacing: 0.12em;"
                                       x-model="form.rhythm_pattern"
                                       @input="syncFromRaw()"
                                       maxlength="32" placeholder="x.x.x.x.">
                                <p class="sbn-form-hint">x = hit, X = accent, . = rest</p>
                            </div>
                            <div class="sbn-form-group">
                                <label>Thumb/Bass pattern</label>
                                <input type="text" class="sbn-search-input" style="padding-left: 14px; font-family: var(--font-mono); letter-spacing: 0.12em;"
                                       x-model="form.thumb_pattern"
                                       @input="syncFromRaw()"
                                       maxlength="32" placeholder="x...x...">
                            </div>
                        </div>
                    </div>
                </div>

                {{-- MP3 file --}}
                <div class="sbn-form-group" style="margin-top: 16px;">
                    <label for="mp3_file">MP3 File (optional)</label>
                    <input type="text" id="mp3_file" class="sbn-search-input" style="padding-left: 14px;"
                           x-model="form.mp3_file" placeholder="filename.mp3">
                    <p class="sbn-form-hint">Place files in the rhythms upload directory.</p>
                </div>
            </div>
        </div>
    </div>

    {{-- -- Right: Sidebar -- --}}
    <div class="editor-sidebar">

        {{-- Preview --}}
        <div class="sbn-editor-card">
            <div class="sbn-editor-card-header"><h3>Preview</h3></div>
            <div class="sbn-editor-card-body">
                <div class="live-preview">
                    <div class="live-preview-meta">
                        <strong x-text="form.name || 'Untitled'" style="font-size: 13px;"></strong>
                        <span class="sbn-badge sbn-badge-muted" x-text="form.time_signature"></span>
                        <span class="sbn-badge sbn-badge-muted" x-text="form.default_bpm + ' BPM'"></span>
                    </div>
                    <div x-html="previewHtml"></div>
                </div>

                <button class="play-btn" style="margin-top: 12px;"
                        :class="{ 'is-playing': isPlaying }"
                        @click="togglePlay()"
                        x-text="isPlaying ? '■ Stop' : '▶ Play'">
                </button>
            </div>
        </div>


        {{-- Video Examples --}}
        @include('admin._partials.video-snippets', [
            'snippets'    => $pattern->video_snippets ?? [],
            'beatsPerBar' => (int) explode('/', $pattern->time_signature ?? '4/4')[0],
        ])

        {{-- Actions --}}
        <div style="display: flex; flex-direction: column; gap: 12px;">
            <button class="sbn-btn sbn-btn-primary" style="padding: 14px 28px; font-size: 14px; justify-content: center; width: 100%;"
                    :disabled="saving"
                    @click="save()"
                    x-text="saving ? 'Saving…' : '{{ $isNew ? 'Create Pattern' : 'Update Pattern' }}'">
            </button>
            <a href="{{ route('admin.rhythms.index') }}" class="sbn-btn sbn-btn-secondary" style="justify-content: center; width: 100%;">
                Cancel
            </a>
        </div>
    </div>
</div>

@endsection

@push('scripts')
<script src="{{ asset('js/sbn-snippet-editor.js') }}"></script>
<script>
    // -- Simple Web Audio preview (no Tone.js needed) --
    let _audioCtx = null;
    function getAudioCtx() {
        if (!_audioCtx) _audioCtx = new (window.AudioContext || window.webkitAudioContext)();
        if (_audioCtx.state === 'suspended') _audioCtx.resume();
        return _audioCtx;
    }

    function playClick(isThumb, isAccent) {
        const ctx = getAudioCtx();
        const now = ctx.currentTime;

        const osc = ctx.createOscillator();
        const gain = ctx.createGain();
        osc.connect(gain);
        gain.connect(ctx.destination);

        if (isThumb) {
            // Bass thump
            osc.type = 'triangle';
            osc.frequency.setValueAtTime(82, now); // E2
            gain.gain.setValueAtTime(0.5, now);
            gain.gain.exponentialRampToValueAtTime(0.001, now + 0.2);
            osc.start(now);
            osc.stop(now + 0.2);
        } else {
            // Chord click
            osc.type = 'triangle';
            osc.frequency.setValueAtTime(isAccent ? 660 : 330, now); // E5 or E4
            const vol = isAccent ? 0.4 : 0.25;
            gain.gain.setValueAtTime(vol, now);
            gain.gain.exponentialRampToValueAtTime(0.001, now + 0.1);
            osc.start(now);
            osc.stop(now + 0.1);
        }
    }
    function sbnToast(message, type) {
        const el = document.createElement('div');
        el.className = `sbn-toast sbn-toast-${type || 'info'}`;
        el.textContent = message;
        document.body.appendChild(el);
        setTimeout(() => { el.style.opacity = '0'; setTimeout(() => el.remove(), 300); }, 3000);
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
            else if (gridType === 'triplet') labels.push(s === 1 ? 'trip' : 'let');
            else if (gridType === 'eighth') labels.push('+');
            else labels.push(['e', '+', 'a'][s - 1] || '');
        }
        return labels;
    }

    function toSlug(name) {
        return name.toLowerCase().replace(/[^a-z0-9\s-]/g, '').replace(/\s+/g, '-').replace(/-+/g, '-').trim();
    }

    function rhythmEditor() {
        return {
            form: {
                name:           @json($pattern->name ?? ''),
                slug:           @json($pattern->slug ?? ''),
                description:    @json($pattern->description ?? ''),
                category:       @json($pattern->category ?? 'general'),
                time_signature: @json($pattern->time_signature ?? '4/4'),
                default_bpm:    {{ $pattern->default_bpm ?? 120 }},
                grid_type:      @json($pattern->grid_type ?? 'sixteenth'),
                rhythm_pattern: @json($pattern->rhythm_pattern ?? '........'),
                thumb_pattern:  @json($pattern->thumb_pattern ?? ''),
                perc_top:       @json($pattern->perc_top ?? 'none'),
                perc_bass:      @json($pattern->perc_bass ?? 'none'),
                mp3_file:       @json($pattern->mp3_file ?? ''),
                video_snippets: @json($pattern->video_snippets ?? []),
            },

            bars: {{ $isNew ? 1 : 'null' }},
            showRaw: false,
            saving: false,
            rhythmArr: [],
            thumbArr: [],
            gridLabels: [],
            isPlaying: false,
            currentBeat: 0,
            _timer: null,

            init() {
                @if(!$isNew)
                    const bpb = parseInt(this.form.time_signature.split('/')[0]) || 4;
                    const sub = this.form.grid_type === 'eighth' ? 2 : this.form.grid_type === 'triplet' ? 3 : 4;
                    const cpb = bpb * sub;
                    this.bars = Math.max(1, Math.round(({{ $pattern->beats ?? 8 }}) / cpb));
                @endif
                this.rebuildGrid();
            },

            get totalBeats() {
                const bpb = parseInt((this.form.time_signature || '4/4').split('/')[0]) || 4;
                const sub = this.form.grid_type === 'eighth' ? 2 : this.form.grid_type === 'triplet' ? 3 : 4;
                return bpb * sub * (parseInt(this.bars) || 1);
            },

            rebuildGrid() {
                const beats = this.totalBeats;
                this.form.rhythm_pattern = (this.form.rhythm_pattern || '').padEnd(beats, '.').slice(0, beats);
                this.form.thumb_pattern  = (this.form.thumb_pattern  || '').padEnd(beats, '.').slice(0, beats);
                this.rhythmArr  = this.form.rhythm_pattern.split('');
                this.thumbArr   = this.form.thumb_pattern.split('');
                this.gridLabels = sbnBeatLabels(beats, this.form.time_signature, this.form.grid_type);
                this.updatePreview();
            },

            onGridSettingsChange() { this.rebuildGrid(); },

            cycleRhythm(i) {
                const c = this.rhythmArr[i];
                if (c === 'X')      this.rhythmArr[i] = '.';
                else if (c === 'x') this.rhythmArr[i] = 'X';
                else                this.rhythmArr[i] = 'x';
                this.rhythmArr = [...this.rhythmArr];
                this.syncToForm();
            },

            toggleThumb(i) {
                this.thumbArr[i] = this.thumbArr[i].toLowerCase() === 'x' ? '.' : 'x';
                this.thumbArr = [...this.thumbArr];
                this.syncToForm();
            },

            syncToForm() {
                this.form.rhythm_pattern = this.rhythmArr.join('');
                this.form.thumb_pattern  = this.thumbArr.join('');
                this.updatePreview();
            },

            syncFromRaw() {
                const beats = this.totalBeats;
                this.form.rhythm_pattern = (this.form.rhythm_pattern || '').padEnd(beats, '.').slice(0, beats);
                this.form.thumb_pattern  = (this.form.thumb_pattern  || '').padEnd(beats, '.').slice(0, beats);
                this.rhythmArr = this.form.rhythm_pattern.split('');
                this.thumbArr  = this.form.thumb_pattern.split('');
                this.updatePreview();
            },

            previewHtml: '',

            updatePreview() {
                const beats = this.totalBeats;
                const r = this.rhythmArr;
                const t = this.thumbArr;
                const hasThumb = t.some(c => c.toLowerCase() === 'x');
                const labels = this.gridLabels;

                let h = '<div style="display:flex;flex-direction:column;gap:3px;">';
                h += '<div class="mini-grid-row"><span class="mini-grid-label"></span>';
                for (let i = 0; i < beats; i++) h += `<div class="mini-grid-cell is-beat">${labels[i]}</div>`;
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
                h += '</div>';
                this.previewHtml = h;
            },

            async togglePlay() {
                if (this.isPlaying) { this.stopPlay(); return; }

                // Init audio context on first user click
                getAudioCtx();

                const bpm = this.form.default_bpm || 120;
                const sub = this.form.grid_type === 'eighth' ? 2 : this.form.grid_type === 'triplet' ? 3 : 4;
                const intervalMs = (60000 / bpm) / sub;
                const beats = this.totalBeats;

                this.isPlaying = true;
                this.currentBeat = 0;

                const self = this;
                const tick = () => {
                    const r = self.rhythmArr[self.currentBeat] || '.';
                    const t = self.thumbArr[self.currentBeat]  || '.';
                    if (t.toLowerCase() === 'x') playClick(true, false);
                    if (r.toLowerCase() === 'x') playClick(false, r === 'X');
                    self.currentBeat = (self.currentBeat + 1) % beats;
                };

                tick();
                this._timer = setInterval(tick, intervalMs);
            },

            stopPlay() {
                if (this._timer) { clearInterval(this._timer); this._timer = null; }
                this.isPlaying = false;
                this.currentBeat = 0;
            },

            async save() {
                if (!this.form.name) {
                    sbnToast('Please enter a name.', 'error');
                    return;
                }

                this.saving = true;
                const isNew = {{ $isNew ? 'true' : 'false' }};
                const url = isNew
                    ? '{{ route("admin.rhythms.store") }}'
                    : '{{ $isNew ? "#" : route("admin.rhythms.update", $pattern) }}';

                try {
                    const res = await fetch(url, {
                        method: isNew ? 'POST' : 'PUT',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content,
                            'Accept': 'application/json',
                        },
                        body: JSON.stringify({ ...this.form, beats: this.totalBeats }),
                    });

                    const data = await res.json();

                    if (data.success) {
                        sbnToast(data.message || 'Saved!', 'success');
                        if (isNew && data.id) window.location.href = `/admin/rhythms/${data.id}/edit`;
                    } else if (data.errors) {
                        sbnToast(Object.values(data.errors)[0][0], 'error');
                    } else {
                        sbnToast(data.message || 'Error saving.', 'error');
                    }
                } catch (err) {
                    sbnToast('Network error.', 'error');
                }
                this.saving = false;
            },
        };
    }
</script>
@endpush
