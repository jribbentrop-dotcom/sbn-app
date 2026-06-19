<div id="lookup-modal" class="sbn-modal" x-data="lookupModal()" x-cloak>
    <div class="sbn-modal-overlay" @click="close"></div>
    <div class="sbn-modal-content" style="max-width: 650px;">
        <div class="sbn-modal-header">
            <h2>Create from Song Lookup (AI)</h2>
            <button class="sbn-modal-close" @click="close" :disabled="loading">×</button>
        </div>

        <form method="POST" action="{{ route('admin.leadsheets.create-from-lookup') }}" enctype="multipart/form-data">
            @csrf

            <div class="sbn-modal-body">
                <div class="sbn-form-group" x-show="mode !== 'audio'">
                    <label for="lookup_title">Song Title <span class="required">*</span></label>
                    <input type="text" id="lookup_title" name="title" class="sbn-input" :required="mode !== 'audio'" maxlength="255" x-model="title" :disabled="loading">
                </div>

                <div class="sbn-form-row">
                    <div class="sbn-form-group" x-show="mode !== 'audio'">
                        <label for="artist_hint">Artist / Version Hint</label>
                        <input type="text" id="artist_hint" name="artist_hint" class="sbn-input" placeholder="e.g. Herb Ellis version, acoustic, etc." x-model="artistHint" :disabled="loading">
                    </div>

                    <div class="sbn-form-group" x-show="mode !== 'audio'">
                        <label for="preferred_key">Preferred Key</label>
                        <select id="preferred_key" name="preferred_key" class="sbn-select" x-model="preferredKey" :disabled="loading">
                            <option value="">Use Canonical</option>
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

                <div class="sbn-form-group" x-show="mode !== 'audio'">
                    <label for="version">Version Preference</label>
                    <select id="version" name="version" class="sbn-select" x-model="version" :disabled="loading">
                        <option value="most_common">Most Common (default)</option>
                        <option value="real_book">Real Book / Standard</option>
                        <option value="original">Original Recording</option>
                    </select>
                </div>
                <div class="sbn-form-group">
                    <label>Lookup Mode</label>
                    <div style="display: flex; gap: 20px; margin-top: 8px;">
                        <label style="display: flex; align-items: center; gap: 6px; cursor: pointer;">
                            <input type="radio" name="mode_display" value="search" x-model="modeDisplay" :disabled="loading" @change="mode = useResearch ? 'assistant' : 'quick'">
                            <div>
                                <div style="font-weight: 600;">AI Song Search</div>
                                <div style="font-size: 11px; color: #6b7280;">Finds changes via LLM.</div>
                            </div>
                        </label>
                        <label style="display: flex; align-items: center; gap: 6px; cursor: pointer;">
                            <input type="radio" name="mode_display" value="audio" x-model="modeDisplay" :disabled="loading" @change="mode = 'audio'; audioSource = 'youtube'">
                            <div>
                                <div style="font-weight: 600;">Audio Transcription</div>
                                <div style="font-size: 11px; color: #6b7280;">High-precision via YouTube or local file.</div>
                            </div>
                        </label>
                    </div>
                    <input type="hidden" name="mode" :value="mode">
                </div>

                <div class="sbn-form-group" x-show="modeDisplay === 'search'" style="margin-left: 24px; margin-top: 4px;">
                    <label class="sbn-checkbox" style="font-size: 12px;">
                        <input type="checkbox" x-model="useResearch" @change="mode = useResearch ? 'assistant' : 'quick'" :disabled="loading">
                        <span>Include Deep Research (videos, historical notes, suggested versions)</span>
                    </label>
                </div>

                <div x-show="mode === 'audio'" style="margin-top: 15px; border-top: 1px solid #e5e7eb; padding-top: 15px;">

                    <!-- Audio source tabs -->
                    <div style="display: flex; gap: 0; margin-bottom: 16px; border: 1px solid #e5e7eb; border-radius: 8px; overflow: hidden;">
                        <button type="button"
                            @click="audioSource = 'youtube'"
                            :style="audioSource === 'youtube' ? 'background:#3b82f6;color:#fff;' : 'background:#f9fafb;color:#374151;'"
                            style="flex:1; padding: 7px 12px; font-size: 12px; font-weight: 600; border: none; cursor: pointer; transition: background 0.15s;"
                            :disabled="loading">YouTube</button>
                        <button type="button"
                            @click="audioSource = 'local'"
                            :style="audioSource === 'local' ? 'background:#3b82f6;color:#fff;' : 'background:#f9fafb;color:#374151;'"
                            style="flex:1; padding: 7px 12px; font-size: 12px; font-weight: 600; border: none; border-left: 1px solid #e5e7eb; cursor: pointer; transition: background 0.15s;"
                            :disabled="loading">Local File</button>
                    </div>

                    <!-- Local file picker -->
                    <div x-show="audioSource === 'local'" class="sbn-form-group">
                        <label>Audio File <span style="font-size:11px; color:#6b7280;">(mp3, wav, m4a, ogg, flac — max 100 MB)</span></label>
                        <input type="file" name="local_audio" accept=".mp3,.wav,.m4a,.ogg,.flac"
                            @change="handleLocalFile($event)" :disabled="loading"
                            style="margin-top: 6px; font-size: 13px;">
                        <div x-show="localFileName" style="margin-top: 6px; font-size: 12px; color: #16a34a;">
                            ✓ <span x-text="localFileName"></span>
                        </div>
                    </div>

                    <div x-show="audioSource === 'youtube'">
                        <div class="sbn-form-group">
                            <label>YouTube Search</label>
                            <div style="display: flex; gap: 10px;">
                                <input type="text" class="sbn-input" placeholder="Search YouTube for a song..." x-model="youtubeQuery" @keydown.enter.prevent="searchYoutube" :disabled="loading">
                                <button type="button" class="sbn-btn sbn-btn-secondary" @click="searchYoutube" :disabled="loading || youtubeSearching">
                                    <span x-show="!youtubeSearching">Search</span>
                                    <span x-show="youtubeSearching">...</span>
                                </button>
                            </div>
                        </div>

                        <div class="sbn-form-group" style="margin-top: 10px;">
                            <label style="font-size: 12px; color: #6b7280;">or paste a YouTube URL directly</label>
                            <input type="text" class="sbn-input" placeholder="https://www.youtube.com/watch?v=..." x-model="youtubeUrlInput" @input="handleYoutubeUrl()" :disabled="loading" style="margin-top: 4px;">
                            <div x-show="youtubeUrlError" style="color: #ef4444; font-size: 12px; margin-top: 4px;" x-text="youtubeUrlError"></div>
                            <div x-show="youtubeUrlInput && selectedVideoId && !youtubeUrlError" style="color: #16a34a; font-size: 12px; margin-top: 4px;">
                                ✓ Video ID <span x-text="selectedVideoId" style="font-family: monospace;"></span> ready to transcribe.
                            </div>
                        </div>

                        <div x-show="youtubeError" style="color: #ef4444; font-size: 12px; margin-top: 8px;" x-text="youtubeError"></div>

                    <!-- YouTube Results -->
                    <div x-show="youtubeResults.length > 0" style="margin-top: 15px; max-height: 250px; overflow-y: auto; border: 1px solid #e5e7eb; border-radius: 8px; background: #f9fafb;">
                        <template x-for="video in youtubeResults" :key="video.videoId">
                            <div 
                                style="display: flex; gap: 12px; padding: 10px; border-bottom: 1px solid #e5e7eb; cursor: pointer; transition: background 0.15s;"
                                :style="selectedVideoId === video.videoId ? 'background-color: #eff6ff; border-left: 3px solid #3b82f6;' : ''"
                                @click="selectVideo(video)"
                            >
                                <img :src="video.thumbnail" style="width: 100px; height: 56px; object-fit: cover; border-radius: 4px;" :alt="video.title">
                                <div style="flex: 1; overflow: hidden;">
                                    <div style="font-weight: 600; font-size: 13px; color: #111827; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;" x-text="video.title"></div>
                                    <div style="font-size: 11px; color: #6b7280; margin-top: 4px;" x-text="video.channelTitle"></div>
                                </div>
                            </div>
                        </template>
                    </div>

                        <input type="hidden" name="youtube_id" :value="selectedVideoId">
                        <input type="hidden" name="youtube_title" :value="selectedVideoTitle">
                    </div><!-- /audioSource youtube -->

                    <div class="sbn-form-group" style="margin-top: 15px;">
                        <label class="sbn-checkbox">
                            <input type="checkbox" name="ai_cleanup" value="1" checked>
                            <div>
                                <div style="font-weight: 600;">AI Rhythmic Cleanup</div>
                                <div style="font-size: 11px; color: #6b7280;">Simplifies micro-timing and corrects harmonic errors using Gemini.</div>
                            </div>
                        </label>
                    </div>

                    <div class="sbn-form-group" style="margin-top: 15px;">
                        <label for="tab_position_style">Tab Position Style</label>
                        <select id="tab_position_style" name="tab_position_style" class="sbn-select" :disabled="loading">
                            <option value="fretted">Fretted positions &mdash; jazz chord-melody (default)</option>
                            <option value="open">Prefer open strings &mdash; classical / fingerstyle</option>
                        </select>
                        <div style="font-size: 11px; color: #6b7280; margin-top: 4px;">
                            How the fretboard optimiser places notes. Fretted keeps a bar in one
                            neck position; open favours open strings even mid-position.
                        </div>
                    </div>

                    <!-- ── Note Detection Tuning (basic-pitch) ──────────────── -->
                    <div class="sbn-form-group" style="margin-top: 15px;">
                        <label for="detection_preset">Note Detection Sensitivity</label>
                        <select id="detection_preset" name="detection_preset" class="sbn-select"
                                x-model="detectionPreset" @change="applyDetectionPreset()" :disabled="loading">
                            <option value="balanced">Balanced &mdash; clearly-picked recordings (default)</option>
                            <option value="sensitive">Sensitive &mdash; soft / legato solo guitar, orchestral mixes</option>
                            <option value="strict">Strict &mdash; reject reverb tails &amp; false notes</option>
                            <option value="custom">Custom &mdash; set the sliders below</option>
                        </select>
                        <div style="font-size: 11px; color: #6b7280; margin-top: 4px;">
                            Controls how aggressively note onsets are detected. If a transcription
                            comes back with far too few notes, switch to <strong>Sensitive</strong>.
                            Too many spurious notes &rarr; <strong>Strict</strong>.
                        </div>
                    </div>

                    <div class="sbn-form-group" style="margin-top: 8px;">
                        <button type="button" class="sbn-link-btn"
                                @click="showDetectionAdvanced = !showDetectionAdvanced"
                                style="background:none; border:none; padding:0; color:#3b82f6; font-size:12px; cursor:pointer;">
                            <span x-text="showDetectionAdvanced ? '▾ Hide advanced detection knobs' : '▸ Advanced detection knobs'"></span>
                        </button>
                    </div>

                    <div x-show="showDetectionAdvanced" style="margin-top: 8px; padding: 12px; background:#f9fafb; border:1px solid #e5e7eb; border-radius:8px;">
                        <!-- Onset threshold -->
                        <div style="margin-bottom: 14px;">
                            <label style="font-size:12px; font-weight:600; display:flex; justify-content:space-between;">
                                <span>Onset Threshold</span>
                                <span x-text="Number(onsetThreshold).toFixed(2)" style="color:#3b82f6;"></span>
                            </label>
                            <input type="range" name="onset_threshold" min="0.05" max="0.95" step="0.05"
                                   x-model.number="onsetThreshold"
                                   @input="detectionPreset = 'custom'" :disabled="loading"
                                   style="width:100%;">
                            <div style="font-size:10px; color:#6b7280;">
                                Lower = catches softer / legato attacks (more notes). Higher = only firm attacks.
                            </div>
                        </div>
                        <!-- Frame threshold -->
                        <div style="margin-bottom: 14px;">
                            <label style="font-size:12px; font-weight:600; display:flex; justify-content:space-between;">
                                <span>Frame (Pitch) Threshold</span>
                                <span x-text="Number(frameThreshold).toFixed(2)" style="color:#3b82f6;"></span>
                            </label>
                            <input type="range" name="frame_threshold" min="0.05" max="0.95" step="0.05"
                                   x-model.number="frameThreshold"
                                   @input="detectionPreset = 'custom'" :disabled="loading"
                                   style="width:100%;">
                            <div style="font-size:10px; color:#6b7280;">
                                Lower = sustains quiet pitches longer. Higher = drops faint / decaying notes.
                            </div>
                        </div>
                        <!-- Minimum note length -->
                        <div style="margin-bottom: 10px;">
                            <label style="font-size:12px; font-weight:600; display:flex; justify-content:space-between;">
                                <span>Minimum Note Length</span>
                                <span style="color:#3b82f6;"><span x-text="Math.round(minNoteLength)"></span> ms</span>
                            </label>
                            <input type="range" name="minimum_note_length" min="10" max="500" step="2"
                                   x-model.number="minNoteLength"
                                   @input="detectionPreset = 'custom'" :disabled="loading"
                                   style="width:100%;">
                            <div style="font-size:10px; color:#6b7280;">
                                Lower = keeps fast runs &amp; grace notes. Higher = filters out transients.
                            </div>
                        </div>
                        <!-- Guitar range clamp -->
                        <label class="sbn-checkbox" style="margin-top:4px;">
                            <input type="checkbox" name="restrict_guitar_range" value="1"
                                   x-model="restrictGuitarRange" :disabled="loading">
                            <div>
                                <div style="font-weight:600; font-size:12px;">Restrict to guitar range</div>
                                <div style="font-size:10px; color:#6b7280;">
                                    Ignores sub-bass rumble and cymbal noise outside ~E2&ndash;E6.
                                </div>
                            </div>
                        </label>
                    </div>
                </div>

                <div class="sbn-form-group">
                    <label class="sbn-checkbox">
                        <input type="checkbox" name="build_voicings" value="1" x-model="buildVoicings" :disabled="loading">
                        <span>Build voicings automatically</span>
                    </label>
                </div>

                <div class="sbn-form-group" x-show="buildVoicings" style="margin-top: 10px;">
                    <label for="lookup_voicing_style">Voicing Style</label>
                    <select id="lookup_voicing_style" name="voicing_style" class="sbn-select" x-model="voicingStyle" :disabled="loading">
                        <option value="popular">Most popular</option>
                        <option value="shell">Shell (3-note)</option>
                        <option value="drop2">Drop-2</option>
                        <option value="archetype">Archetype</option>
                    </select>
                </div>

                <div class="sbn-form-group" x-show="buildVoicings" style="margin-top: 10px;">
                    <label>Extension Mode</label>
                    <label class="sbn-radio" style="display:block; margin-top:4px;">
                        <input type="radio" name="extension_mode" value="basic" x-model="extensionMode" :disabled="loading">
                        <span>Basic &mdash; base quality only (clean chord names)</span>
                    </label>
                    <label class="sbn-radio" style="display:block; margin-top:4px;">
                        <input type="radio" name="extension_mode" value="extended" x-model="extensionMode" :disabled="loading">
                        <span>Extended &mdash; builder adds option tones</span>
                    </label>
                </div>
                
                <div class="sbn-form-group" x-show="buildVoicings" style="margin-top: 10px;">
                    <label for="lookup_rhythm">Rhythm Override (Optional)</label>
                    <select id="lookup_rhythm" name="rhythm_override" class="sbn-select" :disabled="loading">
                        <option value="">Auto-detect from AI</option>
                        @foreach($rhythms as $r)
                            <option value="{{ $r->slug }}">{{ $r->name }}</option>
                        @endforeach
                    </select>
                    <div style="font-size: 11px; color: #6b7280; margin-top: 2px;">
                        * Leave blank to let the AI pick the best matching rhythm.
                    </div>
                </div>

                <div x-show="loading" style="margin-top: 20px; text-align: center; color: #6b7280;">
                    <svg style="animation: spin 1s linear infinite; height: 24px; width: 24px; margin: 0 auto 10px auto; color: #3b82f6;" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                        <circle style="opacity: 0.25;" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                        <path style="opacity: 0.75;" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                    </svg>
                    Searching the web and analyzing... this may take up to 30 seconds.
                </div>
            </div>

            <!-- FOOTER NAVIGATION -->
            <div class="sbn-modal-footer">
                <button type="button" class="sbn-btn" @click="close" :disabled="loading">Cancel</button>
                <button type="submit" class="sbn-btn sbn-btn-primary" :disabled="!canSubmit" @click="startLoading">Look Up & Generate</button>
            </div>
        </form>
    </div>
</div>

@push('scripts')
<script>
    window.lookupModal = function() {
        return {
            title: '',
            artistHint: '',
            preferredKey: '',
            mode: 'quick',
            modeDisplay: 'search',
            useResearch: false,
            buildVoicings: true,
            voicingStyle: 'popular',
            extensionMode: 'basic',
            loading: false,

            // Audio-transcription detection tuning (basic-pitch knobs)
            detectionPreset: 'balanced',
            showDetectionAdvanced: false,
            onsetThreshold: 0.5,
            frameThreshold: 0.3,
            minNoteLength: 128,
            restrictGuitarRange: false,
            detectionPresets: {
                balanced:  { onset: 0.5, frame: 0.3,  minLen: 128 },
                sensitive: { onset: 0.3, frame: 0.18, minLen: 58 },
                strict:    { onset: 0.7, frame: 0.45, minLen: 160 },
            },
            applyDetectionPreset() {
                const p = this.detectionPresets[this.detectionPreset];
                if (p) {
                    this.onsetThreshold = p.onset;
                    this.frameThreshold = p.frame;
                    this.minNoteLength = p.minLen;
                }
            },

            // Audio source: 'youtube' | 'local'
            audioSource: 'youtube',
            localFileName: '',

            // YouTube Search State
            youtubeQuery: '',
            youtubeSearching: false,
            youtubeResults: [],
            selectedVideoId: '',
            selectedVideoTitle: '',
            youtubeError: '',
            youtubeUrlInput: '',
            youtubeUrlError: '',

            init() {
                if (window.location.hash === '#lookup') {
                    this.open();
                }
            },

            open() {
                this.title = '';
                this.artistHint = '';
                this.preferredKey = '';
                this.mode = 'quick';
                this.modeDisplay = 'search';
                this.useResearch = false;
                this.buildVoicings = true;
                this.voicingStyle = 'popular';
                this.loading = false;

                this.detectionPreset = 'balanced';
                this.showDetectionAdvanced = false;
                this.restrictGuitarRange = false;
                this.applyDetectionPreset();

                this.audioSource = 'youtube';
                this.localFileName = '';
                this.youtubeQuery = '';
                this.youtubeResults = [];
                this.selectedVideoId = '';
                this.selectedVideoTitle = '';
                this.youtubeError = '';
                this.youtubeUrlInput = '';
                this.youtubeUrlError = '';
                
                document.getElementById('lookup-modal').classList.add('sbn-modal-open');
                setTimeout(() => {
                    const input = document.getElementById('lookup_title');
                    if (input) input.focus();
                }, 100);
            },

            close() {
                if (this.loading) return;
                document.getElementById('lookup-modal').classList.remove('sbn-modal-open');
            },

            get canSubmit() {
                if (this.loading) return false;
                if (this.mode === 'audio') {
                    if (this.audioSource === 'local') return this.localFileName !== '';
                    return this.selectedVideoId !== '';
                }
                return this.title.trim().length > 0;
            },

            handleLocalFile(event) {
                const file = event.target.files[0];
                if (file) {
                    this.localFileName = file.name;
                } else {
                    this.localFileName = '';
                }
            },

            startLoading() {
                if (!this.canSubmit) return;

                // If audio mode, auto-fill title from YouTube or filename if empty
                if (this.mode === 'audio' && this.title.trim() === '') {
                    if (this.audioSource === 'local' && this.localFileName) {
                        this.title = this.localFileName.replace(/\.[^.]+$/, '');
                    } else {
                        this.title = this.selectedVideoTitle;
                    }
                }
                
                setTimeout(() => {
                    this.loading = true;
                }, 10);
            },

            async searchYoutube() {
                if (!this.youtubeQuery.trim()) return;
                
                this.youtubeSearching = true;
                this.youtubeError = '';
                
                try {
                    const response = await fetch(`/api/admin/youtube/search?q=${encodeURIComponent(this.youtubeQuery)}`, {
                        headers: {
                            'Accept': 'application/json',
                            'X-Requested-With': 'XMLHttpRequest'
                        }
                    });
                    const data = await response.json();
                    
                    if (data.success) {
                        this.youtubeResults = data.items;
                        if (data.items.length === 0) {
                            this.youtubeError = 'No videos found.';
                        }
                    } else {
                        this.youtubeError = data.error || 'Search failed.';
                    }
                } catch (e) {
                    this.youtubeError = 'Failed to connect to search service.';
                } finally {
                    this.youtubeSearching = false;
                }
            },

            selectVideo(video) {
                this.selectedVideoId = video.videoId;
                this.selectedVideoTitle = video.title;
            },

            extractYoutubeId(url) {
                const m = url.match(/(?:youtu\.be\/|[?&]v=|\/embed\/|\/v\/)([A-Za-z0-9_-]{11})/);
                return m ? m[1] : null;
            },

            handleYoutubeUrl() {
                const id = this.extractYoutubeId(this.youtubeUrlInput.trim());
                if (!this.youtubeUrlInput.trim()) {
                    this.youtubeUrlError = '';
                    return;
                }
                if (id) {
                    this.selectedVideoId = id;
                    this.selectedVideoTitle = this.youtubeUrlInput.trim();
                    this.youtubeResults = [];
                    this.youtubeUrlError = '';
                } else {
                    this.youtubeUrlError = 'Could not extract a video ID — paste a full YouTube URL.';
                    if (this.selectedVideoId && !this.youtubeQuery) {
                        this.selectedVideoId = '';
                        this.selectedVideoTitle = '';
                    }
                }
            }
        };
    };
</script>
<style>
@keyframes spin {
    from { transform: rotate(0deg); }
    to { transform: rotate(360deg); }
}
</style>
@endpush
