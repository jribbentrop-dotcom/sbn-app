<div id="lookup-modal" class="sbn-modal" x-data="lookupModal()" x-cloak>
    <div class="sbn-modal-overlay" @click="close"></div>
    <div class="sbn-modal-content" style="max-width: 650px;">
        <div class="sbn-modal-header">
            <h2>Create from Song Lookup (AI)</h2>
            <button class="sbn-modal-close" @click="close" :disabled="loading">×</button>
        </div>

        <form method="POST" action="{{ route('admin.leadsheets.create-from-lookup') }}">
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
                            <input type="radio" name="mode" value="quick" x-model="mode" :disabled="loading">
                            <div>
                                <div style="font-weight: 600;">Quick Draft</div>
                                <div style="font-size: 11px; color: #6b7280;">Fast, basic grid. (Free tier)</div>
                            </div>
                        </label>
                        <label style="display: flex; align-items: center; gap: 6px; cursor: pointer;">
                            <input type="radio" name="mode" value="assistant" x-model="mode" :disabled="loading">
                            <div>
                                <div style="font-weight: 600;">Transcribing Assistant</div>
                                <div style="font-size: 11px; color: #6b7280;">Deep research, videos, hints.</div>
                            </div>
                        </label>
                        <label style="display: flex; align-items: center; gap: 6px; cursor: pointer;">
                            <input type="radio" name="mode" value="audio" x-model="mode" :disabled="loading">
                            <div>
                                <div style="font-weight: 600;">Audio Transcription</div>
                                <div style="font-size: 11px; color: #6b7280;">High-precision via YouTube.</div>
                            </div>
                        </label>
                    </div>
                </div>

                <div x-show="mode === 'audio'" style="margin-top: 15px; border-top: 1px solid #e5e7eb; padding-top: 15px;">
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

                    <div class="sbn-form-group" style="margin-top: 15px;">
                        <label class="sbn-checkbox">
                            <input type="checkbox" name="ai_cleanup" value="1" checked>
                            <div>
                                <div style="font-weight: 600;">AI Rhythmic Cleanup</div>
                                <div style="font-size: 11px; color: #6b7280;">Simplifies micro-timing and corrects harmonic errors using Gemini.</div>
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
            version: 'most_common',
            mode: 'quick',
            buildVoicings: true,
            voicingStyle: 'popular',
            loading: false,

            // YouTube Search State
            youtubeQuery: '',
            youtubeSearching: false,
            youtubeResults: [],
            selectedVideoId: '',
            selectedVideoTitle: '',
            youtubeError: '',

            init() {
                if (window.location.hash === '#lookup') {
                    this.open();
                }
            },

            open() {
                this.title = '';
                this.artistHint = '';
                this.preferredKey = '';
                this.version = 'most_common';
                this.mode = 'quick';
                this.buildVoicings = true;
                this.voicingStyle = 'popular';
                this.loading = false;

                this.youtubeQuery = '';
                this.youtubeResults = [];
                this.selectedVideoId = '';
                this.selectedVideoTitle = '';
                this.youtubeError = '';
                
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
                    return this.selectedVideoId !== '';
                }
                return this.title.trim().length > 0;
            },

            startLoading() {
                if (!this.canSubmit) return;
                
                // If audio mode, auto-fill title from YouTube if empty
                if (this.mode === 'audio' && this.title.trim() === '') {
                    this.title = this.selectedVideoTitle;
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
