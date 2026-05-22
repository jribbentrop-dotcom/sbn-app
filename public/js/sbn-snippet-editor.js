/**
 * SBN Video Snippet Editor — shared Alpine component.
 *
 * Authoring widget for a component's `video_snippets` library (rhythm /
 * progression admin pages). See docs/Video-Sync-Snippet-Integration-Plan.md
 * §0.5 step 2. Self-contained: owns the snippet list, a YouTube IFrame embed
 * for preview/scrub, and the mark-start / mark-end anchoring.
 *
 * Usage in a Blade page (inside a parent x-data editor):
 *
 *   <div x-data="snippetEditor(initialSnippets, { beatsPerBar: 4 })">
 *
 * where `initialSnippets` is the array from the model's video_snippets column.
 * On every mutation the component dispatches a bubbling `sbn:snippets-changed`
 * CustomEvent (detail = the new array) from its root element. The host editor
 * listens for it and copies the array into its own form payload, e.g.:
 *
 *   <div x-data="rhythmEditor()"
 *        @sbn:snippets-changed.window="form.video_snippets = $event.detail">
 *
 * Snippet shape (see §0.2):
 *   { id, label, videoId, videoType, startSec, endSec, tempoBpm }
 */
(function () {
    'use strict';

    /** Pull an 11-char YouTube id out of a URL or accept a bare id. */
    function extractYouTubeId(input) {
        if (!input) return '';
        const s = String(input).trim();
        // Bare id
        if (/^[A-Za-z0-9_-]{11}$/.test(s)) return s;
        const patterns = [
            /[?&]v=([A-Za-z0-9_-]{11})/,
            /youtu\.be\/([A-Za-z0-9_-]{11})/,
            /youtube\.com\/embed\/([A-Za-z0-9_-]{11})/,
            /youtube\.com\/shorts\/([A-Za-z0-9_-]{11})/,
        ];
        for (const re of patterns) {
            const m = s.match(re);
            if (m) return m[1];
        }
        return '';
    }

    /** RFC-4122 v4 uuid; `vs_` prefix keeps ids readable in lesson tags. */
    function makeSnippetId() {
        const uuid = (crypto && crypto.randomUUID)
            ? crypto.randomUUID()
            : 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, function (c) {
                const r = (Math.random() * 16) | 0;
                const v = c === 'x' ? r : (r & 0x3) | 0x8;
                return v.toString(16);
            });
        return 'vs_' + uuid;
    }

    /** Bars spanned by a snippet, given its tempo and a beats-per-bar count. */
    function snippetBarCount(snippet, beatsPerBar) {
        const dur = (snippet.endSec || 0) - (snippet.startSec || 0);
        if (dur <= 0 || !snippet.tempoBpm) return 0;
        const beats = (dur / 60) * snippet.tempoBpm;
        return beats / (beatsPerBar || 4);
    }

    // Max bars a snippet may span — the legal/architectural cap (plan §2/§7).
    const MAX_SNIPPET_BARS = 16;

    // ── YouTube IFrame API loader (one shared promise) ──────────────
    let _ytApiPromise = null;
    function loadYouTubeApi() {
        if (_ytApiPromise) return _ytApiPromise;
        _ytApiPromise = new Promise(function (resolve) {
            if (window.YT && window.YT.Player) { resolve(window.YT); return; }
            const prev = window.onYouTubeIframeAPIReady;
            window.onYouTubeIframeAPIReady = function () {
                if (typeof prev === 'function') prev();
                resolve(window.YT);
            };
            if (!document.getElementById('sbn-yt-iframe-api')) {
                const tag = document.createElement('script');
                tag.id = 'sbn-yt-iframe-api';
                tag.src = 'https://www.youtube.com/iframe_api';
                document.head.appendChild(tag);
            }
        });
        return _ytApiPromise;
    }

    const KEYS = ['C','Db','D','Eb','E','F','F#','G','Ab','A','Bb','B'];

    /**
     * Alpine component factory.
     * @param {Array|null}   initial   starting snippet list (from the model)
     * @param {Object}       opts      { beatsPerBar, numerals }
     *   numerals — array of numeral label strings, e.g. ['IIm7','V7','Imaj7'].
     *   When provided, the draft editor shows a key selector + one chord-search
     *   input per slot so the author can pin specific voicings.
     */
    function snippetEditor(initial, opts) {
        opts = opts || {};
        return {
            snippets: Array.isArray(initial) ? JSON.parse(JSON.stringify(initial)) : [],
            beatsPerBar: opts.beatsPerBar || 4,
            numerals: Array.isArray(opts.numerals) ? opts.numerals : [],
            keys: KEYS,

            // Draft being authored / edited (null = list view)
            draft: null,
            draftIndex: -1,        // -1 = creating new, >=0 = editing existing
            urlInput: '',

            // YouTube player state
            _player: null,
            _pollTimer: null,
            playerReady: false,
            currentTime: 0,
            maxBars: MAX_SNIPPET_BARS,

            // ── List operations ─────────────────────────────────────
            startNew() {
                this.draft = {
                    id: makeSnippetId(),
                    label: '',
                    videoId: '',
                    videoType: 'youtube',
                    startSec: 0,
                    endSec: 0,
                    tempoBpm: 120,
                    key: 'C',
                    chords: this.numerals.map(() => ''),
                };
                this.draftIndex = -1;
                this.urlInput = '';
                this.playerReady = false;
                this.$nextTick(() => this._renderChordSlots());
            },

            editSnippet(i) {
                this.draft = JSON.parse(JSON.stringify(this.snippets[i]));
                // Ensure chords array matches current numeral count
                if (!Array.isArray(this.draft.chords)) {
                    this.draft.chords = this.numerals.map(() => '');
                } else {
                    while (this.draft.chords.length < this.numerals.length) this.draft.chords.push('');
                }
                this.draftIndex = i;
                this.urlInput = this.draft.videoId;
                this.playerReady = false;
                this.$nextTick(() => { this._renderChordSlots(); this.loadVideo(); });
            },

            removeSnippet(i) {
                if (!confirm('Remove this video example?')) return;
                this.snippets.splice(i, 1);
                this._emit();
            },

            cancelDraft() {
                this._destroyPlayer();
                this.draft = null;
                this.draftIndex = -1;
            },

            saveDraft() {
                const err = this.draftError;
                if (err) { window.sbnToast ? sbnToast(err, 'error') : alert(err); return; }
                const saved = JSON.parse(JSON.stringify(this.draft));
                // Only persist chords when at least one slot is pinned; drop the
                // array entirely when all slots are empty so the builder stays active.
                const hasChords = Array.isArray(saved.chords) && saved.chords.some(Boolean);
                if (!hasChords) { delete saved.chords; delete saved.key; }
                if (this.draftIndex >= 0) {
                    this.snippets[this.draftIndex] = saved;
                } else {
                    this.snippets.push(saved);
                }
                this._emit();
                this.cancelDraft();
            },

            // ── Chord slots — imperative DOM to avoid Alpine x-for
            //    scope-loss inside nested x-if templates ──────────────
            _renderChordSlots() {
                const container = this.$refs.chordSlots;
                if (!container || !this.draft || !this.numerals.length) return;
                // Guarantee draft.chords has exactly one entry per numeral slot.
                // Without this a snippet authored before the progression gained
                // numerals would render fewer slots than it has, and saveDraft
                // would persist a short array — the "lost chord slots" bug.
                if (!Array.isArray(this.draft.chords)) this.draft.chords = [];
                while (this.draft.chords.length < this.numerals.length) this.draft.chords.push('');
                container.innerHTML = '';
                const self = this;
                this.numerals.forEach(function (numeral, si) {
                    const row = self._buildSlotRow(si, numeral);
                    container.appendChild(row);
                });
            },

            _buildSlotRow(si, numeral) {
                const self = this;
                const slug = (this.draft.chords || [])[si] || '';

                const row = document.createElement('div');
                row.style.cssText = 'display:flex;align-items:flex-start;gap:8px;';
                row.dataset.slotIndex = si;

                const label = document.createElement('span');
                label.style.cssText = 'flex:0 0 52px;font-family:var(--font-mono);font-size:13px;font-weight:600;padding-top:8px;';
                label.textContent = numeral;
                row.appendChild(label);

                const wrap = document.createElement('div');
                wrap.style.cssText = 'flex:1;position:relative;';

                const inputRow = document.createElement('div');
                inputRow.style.cssText = 'display:flex;gap:6px;';

                const input = document.createElement('input');
                input.type = 'text';
                input.className = 'sbn-search-input';
                input.style.cssText = 'padding-left:12px;flex:1;';
                input.placeholder = (this.draft.key ? 'e.g. ' + this.draft.key + 'm7' : 'Search chord…');
                input.value = slug ? slug : '';

                // Debounced search
                let _debounce = null;
                input.addEventListener('input', function () {
                    clearTimeout(_debounce);
                    _debounce = setTimeout(function () { self._searchChordSlot(si, input.value, dropdown); }, 300);
                });
                input.addEventListener('keydown', function (e) {
                    if (e.key === 'Escape') dropdown.innerHTML = '';
                });
                inputRow.appendChild(input);

                // Clear button (shown when slug is pinned)
                const clearBtn = document.createElement('button');
                clearBtn.type = 'button';
                clearBtn.className = 'sbn-btn sbn-btn-secondary';
                clearBtn.style.cssText = 'padding:4px 10px;font-size:12px;flex-shrink:0;';
                clearBtn.textContent = '✕';
                clearBtn.style.display = slug ? '' : 'none';
                clearBtn.addEventListener('click', function () {
                    self.draft.chords[si] = '';
                    input.value = '';
                    badge.textContent = '';
                    badge.style.display = 'none';
                    clearBtn.style.display = 'none';
                    dropdown.innerHTML = '';
                });
                inputRow.appendChild(clearBtn);
                wrap.appendChild(inputRow);

                // Slug badge
                const badge = document.createElement('div');
                badge.style.cssText = 'font-size:11px;color:var(--clr-text-muted);margin-top:3px;font-family:var(--font-mono);';
                badge.textContent = slug || '';
                badge.style.display = slug ? '' : 'none';
                wrap.appendChild(badge);

                // Results dropdown
                const dropdown = document.createElement('ul');
                dropdown.style.cssText = 'position:absolute;top:100%;left:0;right:0;z-index:200;' +
                    'background:var(--clr-surface,#fff);border:1px solid var(--clr-border);' +
                    'border-radius:6px;margin:2px 0 0;padding:4px 0;list-style:none;' +
                    'max-height:220px;overflow-y:auto;box-shadow:0 4px 12px rgba(0,0,0,.12);display:none;';

                // Pick result handler (shared by all <li>s)
                dropdown.addEventListener('mousedown', function (e) {
                    const li = e.target.closest('li[data-slug]');
                    if (!li) return;
                    e.preventDefault();
                    const pickedSlug = li.dataset.slug;
                    const pickedName = li.dataset.name;
                    self.draft.chords[si] = pickedSlug;
                    input.value = pickedName;
                    badge.textContent = pickedSlug;
                    badge.style.display = '';
                    clearBtn.style.display = '';
                    dropdown.innerHTML = '';
                    dropdown.style.display = 'none';
                });
                wrap.appendChild(dropdown);

                row.appendChild(wrap);
                return row;
            },

            async _searchChordSlot(si, q, dropdown) {
                q = (q || '').trim();
                if (!q) { dropdown.innerHTML = ''; dropdown.style.display = 'none'; return; }
                try {
                    const resp = await fetch('/library/chords/search?q=' + encodeURIComponent(q), {
                        headers: { Accept: 'application/json' },
                    });
                    const data = await resp.json();
                    const results = (data.results || []).slice(0, 8);
                    dropdown.innerHTML = '';
                    results.forEach(function (r) {
                        const li = document.createElement('li');
                        li.dataset.slug = r.slug;
                        li.dataset.name = r.name;
                        li.style.cssText = 'padding:6px 12px;cursor:pointer;font-size:13px;';
                        li.innerHTML =
                            '<span style="font-weight:600;">' + r.name + '</span>' +
                            '<span style="color:var(--clr-text-muted);font-size:11px;margin-left:6px;">' + (r.category_label || r.voicing_category || '') + '</span>' +
                            '<span style="color:var(--clr-text-dim);font-size:11px;font-family:var(--font-mono);margin-left:4px;">' + r.slug + '</span>';
                        dropdown.appendChild(li);
                    });
                    dropdown.style.display = results.length ? '' : 'none';
                } catch (e) {
                    dropdown.innerHTML = '';
                    dropdown.style.display = 'none';
                }
            },

            // ── Validation (mirrors server-side §0.5 step 3) ────────
            get draftBarCount() {
                return this.draft ? snippetBarCount(this.draft, this.beatsPerBar) : 0;
            },

            get draftError() {
                const d = this.draft;
                if (!d) return '';
                if (!d.label || !d.label.trim()) return 'Give the example a label.';
                if (!d.videoId) return 'Paste a valid YouTube URL or ID.';
                if (!(d.tempoBpm > 0)) return 'Tempo must be a positive number.';
                if (!(d.endSec > d.startSec)) return 'End must be after start.';
                if (this.draftBarCount > MAX_SNIPPET_BARS) {
                    return 'Snippet spans ' + this.draftBarCount.toFixed(1) +
                        ' bars — keep it to ' + MAX_SNIPPET_BARS + ' or fewer.';
                }
                return '';
            },

            // ── Video embed / scrub ─────────────────────────────────
            loadVideo() {
                const id = extractYouTubeId(this.urlInput);
                if (!id) {
                    window.sbnToast && sbnToast('Could not read a YouTube ID from that.', 'error');
                    return;
                }
                this.draft.videoId = id;
                this._destroyPlayer();
                const self = this;
                loadYouTubeApi().then(function (YT) {
                    self._player = new YT.Player(self.$refs.ytMount, {
                        videoId: id,
                        playerVars: { rel: 0, modestbranding: 1 },
                        events: {
                            onReady: function () {
                                self.playerReady = true;
                                self._startPolling();
                            },
                        },
                    });
                });
            },

            _startPolling() {
                const self = this;
                this._stopPolling();
                this._pollTimer = setInterval(function () {
                    if (self._player && self._player.getCurrentTime) {
                        self.currentTime = self._player.getCurrentTime() || 0;
                    }
                }, 100);
            },

            _stopPolling() {
                if (this._pollTimer) { clearInterval(this._pollTimer); this._pollTimer = null; }
            },

            _destroyPlayer() {
                this._stopPolling();
                if (this._player && this._player.destroy) this._player.destroy();
                this._player = null;
                this.playerReady = false;
                this.currentTime = 0;
            },

            markStart() {
                if (!this.playerReady) return;
                this.draft.startSec = Math.round(this.currentTime * 100) / 100;
            },

            markEnd() {
                if (!this.playerReady) return;
                this.draft.endSec = Math.round(this.currentTime * 100) / 100;
            },

            previewStart() {
                if (this._player && this._player.seekTo) {
                    this._player.seekTo(this.draft.startSec, true);
                    this._player.playVideo();
                }
            },

            fmtTime(sec) {
                sec = Math.max(0, Math.floor(sec || 0));
                const m = Math.floor(sec / 60);
                const s = sec % 60;
                return m + ':' + String(s).padStart(2, '0');
            },

            // Push the current list to the host editor. A bubbling CustomEvent
            // is used (rather than a captured closure) so the data flow does
            // not depend on Alpine nested-scope resolution.
            _emit() {
                const list = JSON.parse(JSON.stringify(this.snippets));
                const root = this.$root || this.$el;
                root.dispatchEvent(new CustomEvent('sbn:snippets-changed', {
                    detail: list,
                    bubbles: true,
                }));
            },

            init() {
                const self = this;
                // Watch ONLY the null↔non-null transition, not the draft object
                // deeply. A deep watch fires on every field mutation — including
                // each chord pick (which sets draft.chords[si]) — and would
                // re-run _renderChordSlots() mid-interaction, wiping slot DOM
                // (innerHTML = '') and any in-progress input. startNew/editSnippet
                // already render the slots explicitly via $nextTick; this watcher
                // is only a safety net for draft becoming non-null by other paths.
                this.$watch('draft', function (val, oldVal) {
                    const wasNull = !oldVal, isNull = !val;
                    if (wasNull && !isNull && self.numerals.length) {
                        self.$nextTick(() => self._renderChordSlots());
                    }
                });
            },

            // Alpine lifecycle — clean up the iframe if the page tears down.
            destroy() { this._destroyPlayer(); },
        };
    }

    window.snippetEditor = snippetEditor;
    window.sbnExtractYouTubeId = extractYouTubeId;
})();
