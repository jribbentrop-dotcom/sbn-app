<div id="progression-modal" class="sbn-modal" x-data="progressionModal()" x-cloak>
    <div class="sbn-modal-overlay" @click="close"></div>
    <div class="sbn-modal-content" style="max-width: 650px;">
        <div class="sbn-modal-header">
            <h2>Create from Progression / Extract</h2>
            <button class="sbn-modal-close" @click="close">×</button>
        </div>

        <form method="POST" action="{{ route('admin.leadsheets.create-from-sequence') }}">
            @csrf

            <!-- STEP 1: SOURCE -->
            <div class="sbn-modal-body" x-show="step === 1">
                <div class="sbn-form-row">
                    <div class="sbn-form-group" style="flex: 2;">
                        <label for="title">Title <span class="required">*</span></label>
                        <input type="text" id="title" name="title" class="sbn-input" required maxlength="255" x-model="title">
                    </div>
                    <div class="sbn-form-group" style="flex: 1;">
                        <label for="composer">Composer</label>
                        <input type="text" id="composer" name="composer" class="sbn-input" maxlength="255" x-model="composer">
                    </div>
                </div>

                <div class="sbn-form-group">
                    <label>Source Pathway <span class="required">*</span></label>
                    <div class="sbn-source-tabs">
                        <button type="button" class="sbn-tab-btn" :class="{ 'active': sourceType === 'progression' }" @click="setSourceType('progression')">Saved Progression</button>
                        <button type="button" class="sbn-tab-btn" :class="{ 'active': sourceType === 'jazz_standard' }" @click="setSourceType('jazz_standard')">Jazz Standard</button>
                    </div>
                    <input type="hidden" name="source_type" :value="sourceType">
                </div>

                <!-- REMOVED: Free / ChordPro / Bars input -->


                <!-- JAZZ STANDARD SEARCH -->
                <div class="sbn-form-group" x-show="sourceType === 'jazz_standard'">
                    <label for="standard_source_input">Select Jazz Standard <span class="required">*</span></label>
                    <input list="standard-list" id="standard_source_input" class="sbn-input" placeholder="Type to search standards..." x-model="standardSearch" @input="updateStandardId">
                    <datalist id="standard-list">
                        @foreach($jazzStandards as $js)
                            <option value="{{ $js->title }} ({{ $js->composer ?? 'Unknown' }})" data-id="{{ $js->id }}" data-key="{{ $js->song_key }}"></option>
                        @endforeach
                    </datalist>
                    <input type="hidden" name="jazz_standard_id" :value="standardId">
                    <div class="sbn-preview-box" x-show="standardPreview" style="margin-top: 8px;">
                        <strong>Key:</strong> <span x-text="standardPreview"></span>
                    </div>
                </div>

                <!-- SAVED PROGRESSION INPUT -->
                <div class="sbn-form-group" x-show="sourceType === 'progression'">
                    <label for="progression_id">Saved Progression <span class="required">*</span></label>
                    <select id="progression_id" class="sbn-select" x-model="progressionId" @change="updateProgressionPreview">
                        <option value="">— Pick a progression —</option>
                        @foreach($progressions->groupBy('category') as $category => $items)
                            <optgroup label="{{ ucfirst($category) }}">
                                @foreach($items as $p)
                                    <option value="{{ $p->id }}">{{ $p->name }} ({{ $p->numerals }})</option>
                                @endforeach
                            </optgroup>
                        @endforeach
                    </select>
                    <input type="hidden" name="progression_id" :value="progressionId">
                    <div class="sbn-preview-box" x-show="progressionPreview" style="margin-top: 8px;">
                        <strong>Numerals:</strong> <span x-text="progressionPreview"></span>
                    </div>
                </div>


                <!-- PREVIEW FOOTER -->
                <div class="sbn-preview-box" x-show="sourceType === 'progression' && progressionPreview">
                    <div class="sbn-preview-stat"><strong>Type:</strong> Saved Progression</div>
                    <div class="sbn-preview-stat"><strong>Numerals:</strong> <span x-text="progressionPreview"></span></div>
                </div>
                <div class="sbn-preview-box" x-show="sourceType === 'jazz_standard' && standardId">
                    <div class="sbn-preview-stat"><strong>Type:</strong> Jazz Standard</div>
                    <div class="sbn-preview-stat"><strong>Title:</strong> <span x-text="standardSearch"></span></div>
                </div>
            </div>

            <!-- STEP 2: LAYOUT -->
            <div class="sbn-modal-body" x-show="step === 2">
                <div class="sbn-form-row">
                    <div class="sbn-form-group" x-show="sourceType !== 'jazz_standard'">
                        <label for="bars_per_chord">Bars per Chord <span class="required">*</span></label>
                        <input type="number" id="bars_per_chord" name="bars_per_chord" class="sbn-input" min="1" max="16" x-model="barsPerChord">
                    </div>

                    <div class="sbn-form-group">
                        <label for="song_key">Key <span class="required">*</span></label>
                        <select id="song_key" name="song_key" class="sbn-select" required x-model="songKey" @change="fetchNumeralPreview">
                            <option value="C">C</option>
                            <option value="C#">C#</option>
                            <option value="D">D</option>
                            <option value="D#">D#</option>
                            <option value="E">E</option>
                            <option value="F">F</option>
                            <option value="F#">F#</option>
                            <option value="G">G</option>
                            <option value="G#">G#</option>
                            <option value="A">A</option>
                            <option value="A#">A#</option>
                            <option value="B">B</option>
                            <option value="Cm">Cm</option>
                            <option value="C#m">C#m</option>
                            <option value="Dm">Dm</option>
                            <option value="D#m">D#m</option>
                            <option value="Em">Em</option>
                            <option value="Fm">Fm</option>
                            <option value="F#m">F#m</option>
                            <option value="Gm">Gm</option>
                            <option value="G#m">G#m</option>
                            <option value="Am">Am</option>
                            <option value="A#m">A#m</option>
                            <option value="Bm">Bm</option>
                        </select>
                    </div>
                </div>

                <div class="sbn-form-row">
                    <div class="sbn-form-group">
                        <label for="tempo">Tempo (BPM) <span class="required">*</span></label>
                        <input type="number" id="tempo" name="tempo" class="sbn-input" min="20" max="300" x-model="tempo" required>
                    </div>

                    <div class="sbn-form-group">
                        <label for="time_signature">Time Signature <span class="required">*</span></label>
                        <select id="time_signature" name="time_signature" class="sbn-select" required x-model="timeSignature">
                            <option value="4/4">4/4</option>
                            <option value="3/4">3/4</option>
                            <option value="2/4">2/4</option>
                            <option value="6/8">6/8</option>
                            <option value="12/8">12/8</option>
                        </select>
                    </div>
                </div>

                <div class="sbn-form-group">
                    <label for="rhythm">Rhythm Pattern</label>
                    <select id="rhythm" name="rhythm" class="sbn-select" :disabled="!buildVoicings">
                        <option value="">None</option>
                        <template x-for="r in filteredRhythms" :key="r.slug">
                            <option :value="r.slug" x-text="r.name"></option>
                        </template>
                    </select>
                </div>


                <div class="sbn-form-group">
                    <label class="sbn-checkbox">
                        <input type="checkbox" name="build_voicings" value="1" x-model="buildVoicings">
                        <span>Build voicings automatically (Phase L2)</span>
                    </label>
                </div>

                <div class="sbn-form-group" x-show="buildVoicings" style="margin-top: 10px;">

                    <label for="voicing_style">Voicing Style</label>
                    <select id="voicing_style" name="voicing_style" class="sbn-select" x-model="voicingStyle">
                        <option value="popular">Most popular</option>
                        <option value="shell">Shell (3-note)</option>
                        <option value="drop2">Drop-2</option>
                        <option value="archetype">Archetype</option>
                    </select>
                    <div style="font-size: 11px; color: #6b7280; margin-top: 2px;">
                        * "Most popular" picks the highest-popularity voicing regardless of category.
                    </div>
                </div>




                <!-- ASYNC NUMERAL RESOLUTION PREVIEW -->
                <div class="sbn-preview-box" x-show="numeralPreview.length > 0">
                    <strong>Resolved Chords:</strong>
                    <div class="sbn-numeral-preview-list">
                        <template x-for="(ch, idx) in numeralPreview" :key="idx">
                            <span class="sbn-numeral-preview-tag" x-text="ch"></span>
                        </template>
                    </div>
                </div>
            </div>

            <!-- FOOTER NAVIGATION -->
            <div class="sbn-modal-footer">
                <button type="button" class="sbn-btn" @click="close">Cancel</button>
                <button type="button" class="sbn-btn" x-show="step === 2" @click="step = 1">Back</button>
                <button type="button" class="sbn-btn sbn-btn-primary" x-show="step === 1" :disabled="!canProceed" @click="step = 2; fetchNumeralPreview()">Continue</button>
                <button type="submit" class="sbn-btn sbn-btn-primary" x-show="step === 2">Generate Leadsheet</button>
            </div>
        </form>
    </div>
</div>

@push('scripts')
<script>
window.progressionModal = function() {
    return {
        step: 1,
        sourceType: 'progression',
        title: '',
        composer: '',
        sequenceText: '',
        standardSearch: '',
        standardId: '',
        standardPreview: '',
        barsPerChord: 1,
        songKey: 'C',
        tempo: 120,
        timeSignature: '4/4',
        allRhythms: @json($rhythms),
        numeralPreview: [],
        buildVoicings: true,
        voicingStyle: 'popular',
        progressionId: '',
        progressionPreview: '',
        allProgressions: @json($progressions),
        preview: { count: 0, measures: 0, invalid_count: 0 },

        updateProgressionPreview() {
            const p = this.allProgressions.find(x => String(x.id) === String(this.progressionId));
            this.progressionPreview = p ? p.numerals : '';
            this.calculatePreview();
        },



        get filteredRhythms() {
            return this.allRhythms.filter(r => r.time_signature === this.timeSignature);
        },



        init() {
            if (window.location.hash === '#progression') {
                this.open();
            }
        },

        open() {
            document.getElementById('progression-modal').classList.add('sbn-modal-open');
        },

        close() {
            document.getElementById('progression-modal').classList.remove('sbn-modal-open');
            window.location.hash = '';
        },

        setSourceType(type) {
            this.sourceType = type;
            this.calculatePreview();
        },

        get textareaLabel() {
            return {
                'free': 'Chord Sequence (Whitespace separated)',
                'chordpro': 'ChordPro Lyrics (Inline [chords])',
                'bars': 'Pipe Bars (e.g. | Am7 | Dm7 G7 |)'
            }[this.sourceType] || 'Input';
        },

        get textareaPlaceholder() {
            return {
                'free': 'Am7 Dm7 G7 Cmaj7',
                'chordpro': '[Am7]Some lyric [Dm7]more [G7]words.',
                'bars': '| Am7 | Dm7 G7 | Cmaj7 |'
            }[this.sourceType] || '';
        },

        get canProceed() {
            if (!this.title.trim()) return false;
            if (this.sourceType === 'jazz_standard') return !!this.standardId;
            if (this.sourceType === 'progression') return !!this.progressionId;
            return false;
        },

        updateStandardId() {
            let options = document.querySelectorAll('#standard-list option');
            this.standardId = '';
            this.standardPreview = '';
            options.forEach(opt => {
                if (opt.value === this.standardSearch) {
                    this.standardId = opt.getAttribute('data-id');
                    this.standardPreview = opt.getAttribute('data-key') || 'Unknown';
                    
                    // Auto-fill title/composer if empty
                    if (!this.title.trim()) {
                        this.title = this.standardSearch.split(' (')[0];
                    }
                    if (!this.composer.trim()) {
                        let comp = this.standardSearch.match(/\(([^)]+)\)/);
                        if (comp) this.composer = comp[1];
                    }
                    
                    // Set key if standard has one
                    if (this.standardPreview && this.standardPreview !== 'Unknown') {
                        // iReal Pro keys like "Dmin" need to map to our select "Dm"
                        let k = this.standardPreview;
                        if (k.endsWith('min')) k = k.replace('min', 'm');
                        this.songKey = k;
                    }
                }
            });
        },


        updateCloneId() {
            let options = document.querySelectorAll('#clone-leadsheets option');
            this.cloneSourceId = '';
            options.forEach(opt => {
                if (opt.value === this.cloneSearch) {
                    this.cloneSourceId = opt.getAttribute('data-id');
                }
            });
        },

        calculatePreview() {
            let input = this.sequenceText.trim();
            if (!input) {
                this.preview = { count: 0, measures: 0, invalid_count: 0 };
                return;
            }

            let mode = 'sequence';
            let items = [];
            
            if (this.sourceType === 'bars' || input.includes('|')) {
                mode = 'bars';
                let bars = input.replace(/^\||\|$/g, '').split('|');
                bars.forEach(bar => {
                    bar = bar.trim();
                    if (!bar) return;
                    let barChords = bar.split(/\s+/).filter(Boolean);
                    if (barChords.length > 0) {
                        items.push(barChords);
                    }
                });
            } else if (this.sourceType === 'chordpro' || (input.includes('[') && input.includes(']'))) {
                mode = 'sequence';
                let matches = [...input.matchAll(/\[([^\]]+)\]/g)];
                matches.forEach(m => {
                    let chord = m[1].trim();
                    if (chord) items.push(chord);
                });
            } else {
                mode = 'sequence';
                items = input.split(/\s+/).filter(Boolean);
            }

            let count = 0;
            let measures = 0;
            let invalidCount = 0;

            const validChordRegex = /^(b|#)?(III|iii|VII|vii|II|ii|IV|iv|VI|vi|I|i|V|v)?([A-G][#b]?)?(maj|min|m|dim|aug|sus2|sus4|add9|maj7|m7|7|dom7|m7b5|o7|maj6|m6|mMaj7|aug7|7sus4)?(\d+)?(.*)$/;

            if (mode === 'bars') {
                measures = items.length;
                items.forEach(bar => {
                    count += bar.length;
                    bar.forEach(chord => {
                        if (chord === '?') invalidCount++;
                    });
                });
            } else {
                count = items.length;
                measures = count; 
                items.forEach(chord => {
                    if (chord === '?') invalidCount++;
                });
            }

            this.preview = { count, measures, invalid_count: invalidCount };
        },

        fetchNumeralPreview() {
            this.numeralPreview = [];
            if (this.sourceType === 'clone' || !this.sequenceText.trim()) return;

            // Simple check if it contains numerals
            const hasNumerals = /(III|iii|VII|vii|II|ii|IV|iv|VI|vi|I|i|V|v)/i.test(this.sequenceText);
            if (!hasNumerals) return;

            fetch('/api/admin/progressions/resolve-numerals', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content,
                },
                body: JSON.stringify({
                    key: this.songKey,
                    sequence: this.sequenceText
                })
            })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    this.numeralPreview = data.chords;
                }
            })
            .catch(() => {});
        }
    };
};
</script>
@endpush

@push('styles')
<style>
.sbn-source-tabs {
    display: flex;
    gap: 4px;
    border-bottom: 2px solid #e5e7eb;
    margin-bottom: 16px;
}

.sbn-tab-btn {
    padding: 8px 12px;
    border: none;
    background: none;
    font-size: 14px;
    font-weight: 500;
    color: #6b7280;
    cursor: pointer;
    border-bottom: 2px solid transparent;
    margin-bottom: -2px;
    transition: all 0.2s ease;
}

.sbn-tab-btn:hover {
    color: #374151;
}

.sbn-tab-btn.active {
    color: #3b82f6;
    border-bottom-color: #3b82f6;
}

.sbn-preview-box {
    background: #f3f4f6;
    border: 1px solid #e5e7eb;
    border-radius: 6px;
    padding: 12px;
    margin-top: 16px;
    display: flex;
    flex-wrap: wrap;
    gap: 16px;
    font-size: 13px;
}

.sbn-preview-stat {
    display: flex;
    gap: 4px;
}

.sbn-numeral-preview-list {
    display: flex;
    gap: 6px;
    flex-wrap: wrap;
    margin-top: 8px;
    width: 100%;
}

.sbn-numeral-preview-tag {
    background: #dbeafe;
    color: #1e40af;
    padding: 2px 8px;
    border-radius: 4px;
    font-weight: 600;
    font-size: 12px;
}
</style>
@endpush
