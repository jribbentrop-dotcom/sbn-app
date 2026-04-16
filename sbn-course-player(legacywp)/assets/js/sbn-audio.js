/**
 * SBN Audio Engine
 *
 * Shared plucked-string synthesizer used by the leadsheet player and the
 * chord library. Exposes a single global `SbnAudio` object.
 *
 * Requires Tone.js to be loaded first.
 *
 * Signal chain: PolySynth → EQ3 → Reverb → Limiter → Destination
 *
 * @package SBN_Course_Player
 */

(function () {
    'use strict';

    // Already loaded (e.g. leadsheet.js loaded first on same page)
    if (window.SbnAudio) return;

    var SbnAudio = {

        initialized: false,
        synth: null,
        openStrings: ['E2', 'A2', 'D3', 'G3', 'B3', 'E4'],

        // ------------------------------------------------------------------
        // PITCH HELPERS
        // ------------------------------------------------------------------

        fretToNote: function (stringIndex, fret) {
            if (fret === 'x' || fret === 'X') return null;
            var fretNum = parseInt(fret);
            if (isNaN(fretNum)) return null;
            var noteNames = ['C', 'C#', 'D', 'D#', 'E', 'F', 'F#', 'G', 'G#', 'A', 'A#', 'B'];
            var openNote = this.openStrings[stringIndex];
            var openName = openNote.slice(0, -1);
            var openOctave = parseInt(openNote.slice(-1));
            var openIdx = noteNames.indexOf(openName);
            var newIdx = (openIdx + fretNum) % 12;
            var octaveIncrease = Math.floor((openIdx + fretNum) / 12);
            return noteNames[newIdx] + (openOctave + octaveIncrease);
        },

        getNotesFromFrets: function (frets) {
            if (!frets) return [];
            var self = this;
            var fretsArr = frets.replace(/,/g, '').split('');
            var notes = [];
            fretsArr.forEach(function (fret, stringIndex) {
                var note = self.fretToNote(stringIndex, fret);
                if (note) notes.push({ note: note, stringIndex: stringIndex });
            });
            notes.sort(function (a, b) { return a.stringIndex - b.stringIndex; });
            return notes.map(function (n) { return n.note; });
        },

        getBassNote: function (frets) {
            var notes = this.getNotesFromFrets(frets);
            return notes.length > 0 ? notes[0] : null;
        },

        getUpperNotes: function (frets) {
            var notes = this.getNotesFromFrets(frets);
            return notes.length > 1 ? notes.slice(1) : notes;
        },

        // ------------------------------------------------------------------
        // INIT
        // ------------------------------------------------------------------

        /**
         * Initialise the audio engine. Safe to call multiple times.
         * Returns a Promise<boolean>.
         */
        init: function () {
            var self = this;
            if (self.initialized) return Promise.resolve(true);

            if (typeof Tone === 'undefined') {
                console.warn('[SbnAudio] Tone.js not loaded');
                return Promise.resolve(false);
            }

            return Tone.start().then(function () {

                // Master limiter — hard ceiling before the DAC
                var limiter = new Tone.Limiter(-2).toDestination();

                // Short room reverb — adds acoustic body
                var reverb = new Tone.Reverb({ decay: 1.0, wet: 0.15 });
                reverb.connect(limiter);

                // High-shelf cut — removes synthetic "zing" above ~3.5kHz
                var eq = new Tone.EQ3({
                    low: 0,
                    mid: 0,
                    high: -8,
                    lowFrequency: 250,
                    highFrequency: 3500
                });
                eq.connect(reverb);

                // Plucked string PolySynth
                // Custom partial series: warm fundamental + natural overtones.
                // Guitar envelope: near-instant attack, fast decay to low sustain.
                self.synth = new Tone.PolySynth(Tone.Synth, {
                    maxPolyphony: 64,
                    oscillator: {
                        type: 'custom',
                        partials: [1.0, 0.4, 0.15, 0.05]
                    },
                    envelope: {
                        attack:  0.002,
                        decay:   0.08,
                        sustain: 0.25,
                        release: 0.6
                    },
                    volume: -16
                });
                self.synth.connect(eq);

                // Reverb needs a moment to generate its IR
                return reverb.ready;

            }).then(function () {
                self.initialized = true;
                console.log('[SbnAudio] Initialized');
                return true;
            }).catch(function (err) {
                console.error('[SbnAudio] Init failed:', err);
                return false;
            });
        },

        // ------------------------------------------------------------------
        // PLAYBACK
        // ------------------------------------------------------------------

        releaseAll: function () {
            try {
                if (this.synth) this.synth.releaseAll();
            } catch (e) {}
        },

        /**
         * Play the bass (lowest) string of a voicing.
         * @param {string} frets  e.g. "x32010"
         */
        playBass: function (frets) {
            if (!this.initialized || !frets) return;
            var note = this.getBassNote(frets);
            if (note) this.synth.triggerAttackRelease(note, '4n');
        },

        /**
         * Play upper strings of a voicing (strum upward with slight spread).
         * @param {string} frets
         */
        playFingers: function (frets) {
            if (!this.initialized || !frets) return;
            var notes = this.getUpperNotes(frets);
            if (!notes.length) return;
            var now = Tone.now();
            notes.forEach(function (note, i) {
                this.synth.triggerAttackRelease(note, '8n', now + i * 0.018);
            }, this);
        },

        /**
         * Arpeggiate all strings of a voicing from low to high.
         * Used for the chord library play button.
         * @param {string} frets
         */
        playArpeggio: function (frets, interval) {
            if (!this.initialized || !frets) return [];
            interval = interval || 0.12; // seconds between notes (default 120ms)
            var notes = this.getNotesFromFrets(frets);
            if (!notes.length) return [];
            var now = Tone.now();
            notes.forEach(function (note, i) {
                this.synth.triggerAttackRelease(note, '2n', now + i * interval);
            }, this);
            // Return timing info so callers can sync visuals
            return notes.map(function (note, i) {
                return { note: note, delay: i * interval * 1000 }; // delay in ms
            });
        },

        /**
         * Play an arbitrary set of note names at a given time.
         * Used by tab playback.
         * @param {string[]} noteNames  e.g. ["D3", "F#3", "A3"]
         * @param {string}   duration   Tone.js duration string e.g. "8n"
         * @param {number}   [time]     Tone.js audio time (default: now)
         */
        playNotes: function (noteNames, duration, time) {
            if (!this.initialized || !noteNames || !noteNames.length) return;
            this.synth.triggerAttackRelease(noteNames, duration, time || Tone.now());
        }
    };

    window.SbnAudio = SbnAudio;

})();
