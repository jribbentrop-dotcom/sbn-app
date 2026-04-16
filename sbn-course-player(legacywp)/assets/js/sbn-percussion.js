/**
 * SBN Percussion Engine
 *
 * One-shot sample-based percussion for rhythm pattern playback.
 * Shares the Tone.js AudioContext so percussion and guitar synth
 * are on the same clock — zero drift.
 *
 * Sample layout expected in samplesBaseUrl:
 *   shaker-soft.wav        shaker-accent.wav
 *   tamborim-soft.wav      tamborim-accent.wav
 *   kick-soft.wav          kick-accent.wav
 *   hihat-brush-soft.wav   hihat-brush-accent.wav
 *   brush-snare-soft.wav   brush-snare-accent.wav
 *
 * Usage:
 *   SbnPercussion.init('https://example.com/wp-content/uploads/sbn-rhythms/samples/');
 *   SbnPercussion.playHit('shaker', false, audioCtx.currentTime);
 *
 * @package SBN_Course_Player
 */

(function () {
    'use strict';

    if (window.SbnPercussion) return;

    // -------------------------------------------------------------------------
    // Sound definitions
    // Each entry: { files: [soft, accent], offset: seconds }
    // offset compensates for any attack tail before the transient in the sample.
    // Tune these after listening to your exported files.
    // -------------------------------------------------------------------------
    var SOUNDS = {
        'shaker':       { files: ['shaker_soft.wav',       'shaker_accent.wav'],       offset: 0.002 },
        'tamborim':     { files: ['tamborim_soft.wav',     'tamborim_accent.wav'],     offset: 0.001 },
        'kick':         { files: ['kick_soft.wav',         'kick_accent.wav'],         offset: 0.002 },
        'hihat-brush':  { files: ['hihat_brush_soft.wav',  'hihat_brush_accent.wav'],  offset: 0.002 },
        'brush-snare':  { files: ['brush_snare_soft.wav',  'brush_snare_accent.wav'],  offset: 0.003 },
    };

    // Humanization ranges
    var TIMING_JITTER  = 0.004;  // ± seconds (4ms)
    var GAIN_VARIANCE  = 0.15;   // ± fraction of base gain
    var PITCH_VARIANCE = 0.025;  // ± playbackRate fraction

    window.SbnPercussion = {

        ready:       false,
        loading:     false,
        baseUrl:     '',
        buffers:     {},   // { soundName: { soft: AudioBuffer, accent: AudioBuffer } }
        gainNode:    null, // master output gain
        volume:      0.7,  // 0–1, adjustable at runtime
        _audioCtx:   null,

        // ------------------------------------------------------------------
        // INIT — fetch and decode all samples
        // ------------------------------------------------------------------
        init: function (samplesBaseUrl) {
            var self = this;

            if (self.ready || self.loading) return Promise.resolve(self.ready);

            self.baseUrl  = samplesBaseUrl.replace(/\/$/, '') + '/';
            self.loading  = true;

            // Grab Tone.js's AudioContext so we share the same clock as the guitar synth.
            // Falls back to a standalone context if Tone isn't loaded yet.
            self._audioCtx = self._getAudioContext();

            // Create a master gain node for the whole perc bus
            self.gainNode = self._audioCtx.createGain();
            self.gainNode.gain.setValueAtTime(self.volume, self._audioCtx.currentTime);
            self.gainNode.connect(self._audioCtx.destination);

            // Load all sounds in parallel
            var loadPromises = [];

            Object.keys(SOUNDS).forEach(function (soundName) {
                var def = SOUNDS[soundName];
                self.buffers[soundName] = {};

                var variants = [
                    { key: 'soft',   file: def.files[0] },
                    { key: 'accent', file: def.files[1] },
                ];

                variants.forEach(function (v) {
                    var url = self.baseUrl + v.file;
                    var p = fetch(url)
                        .then(function (res) {
                            if (!res.ok) throw new Error('HTTP ' + res.status + ' — ' + url);
                            return res.arrayBuffer();
                        })
                        .then(function (ab) {
                            return self._audioCtx.decodeAudioData(ab);
                        })
                        .then(function (buffer) {
                            self.buffers[soundName][v.key] = buffer;
                        })
                        .catch(function (err) {
                            // Non-fatal: missing sample just stays silent
                            console.warn('[SbnPercussion] Could not load ' + url + ':', err.message);
                        });
                    loadPromises.push(p);
                });
            });

            return Promise.all(loadPromises).then(function () {
                self.loading = false;
                self.ready   = true;
                console.log('[SbnPercussion] Ready — ' + Object.keys(self.buffers).length + ' sounds loaded from ' + self.baseUrl);
                return true;
            });
        },

        // ------------------------------------------------------------------
        // PLAY HIT
        // soundName : 'shaker' | 'tamborim' | 'kick' | 'hihat-brush' | 'brush-snare'
        // isAccent  : bool — use accent sample + slightly louder
        // time      : AudioContext time to schedule the hit (pass audioCtx.currentTime for "now")
        // baseGain  : optional override 0–1 (default: 1.0)
        // ------------------------------------------------------------------
        playHit: function (soundName, isAccent, time, baseGain) {
            if (!this.ready) return;

            var bufSet = this.buffers[soundName];
            if (!bufSet) return;

            var variant = isAccent ? 'accent' : 'soft';
            var buf = bufSet[variant] || bufSet['soft']; // fallback to soft if accent missing
            if (!buf) return;

            var ctx    = this._audioCtx;
            var offset = (SOUNDS[soundName] && SOUNDS[soundName].offset) || 0;

            // Humanize timing
            var jitter  = (Math.random() * 2 - 1) * TIMING_JITTER;
            var hitTime = Math.max(ctx.currentTime, time + jitter - offset);

            // Humanize gain
            var base      = (baseGain !== undefined) ? baseGain : (isAccent ? 1.0 : 0.75);
            var gainVar   = 1 + (Math.random() * 2 - 1) * GAIN_VARIANCE;
            var finalGain = Math.max(0.05, Math.min(1.2, base * gainVar));

            // Humanize pitch
            var pitchVar = 1 + (Math.random() * 2 - 1) * PITCH_VARIANCE;

            // Build node chain: source → gain → master gain bus
            var source = ctx.createBufferSource();
            source.buffer       = buf;
            source.playbackRate.setValueAtTime(pitchVar, hitTime);

            var gainNode = ctx.createGain();
            gainNode.gain.setValueAtTime(finalGain, hitTime);

            source.connect(gainNode);
            gainNode.connect(this.gainNode);

            source.start(hitTime);
            // Auto-stop after buffer duration + small safety margin
            source.stop(hitTime + buf.duration + 0.05);
        },

        // ------------------------------------------------------------------
        // VOLUME CONTROL  0–1
        // ------------------------------------------------------------------
        setVolume: function (vol) {
            this.volume = Math.max(0, Math.min(1, vol));
            if (this.gainNode && this._audioCtx) {
                this.gainNode.gain.setValueAtTime(this.volume, this._audioCtx.currentTime);
            }
        },

        // ------------------------------------------------------------------
        // INTERNAL — resolve AudioContext
        // ------------------------------------------------------------------
        _getAudioContext: function () {
            // Prefer Tone.js context so we share the same clock as SbnAudio
            if (typeof Tone !== 'undefined' && Tone.getContext) {
                try {
                    var raw = Tone.getContext().rawContext;
                    if (raw) return raw;
                } catch (e) { /* fall through */ }
            }
            // Standalone fallback (e.g. rhythm shortcode pages without Tone loaded)
            if (!this._standaloneCtx) {
                this._standaloneCtx = new (window.AudioContext || window.webkitAudioContext)();
            }
            return this._standaloneCtx;
        },

        // ------------------------------------------------------------------
        // RESUME context if suspended (call after a user gesture)
        // ------------------------------------------------------------------
        resume: function () {
            var ctx = this._audioCtx || this._getAudioContext();
            if (ctx && ctx.state === 'suspended') ctx.resume();
        },
    };

})();
