import * as Tone from 'tone';

/**
 * @typedef {import('../types.js').EngineEvent} EngineEvent
 * @typedef {import('../types.js').Beats} Beats
 */

/**
 * Lookahead scheduler.
 *
 * Owns its own 25ms tick loop per the contract (docs/audio-engine-contract.md §6).
 * Reads the current beat from the clock; walks a sorted event list and schedules
 * any events whose time falls inside [now, now + lookaheadBeats].
 *
 * Emits sourceActive(id) at the *exact* audio-context time each event fires,
 * so UI highlighting lines up with audible onsets rather than scheduling time.
 */
export class Scheduler {
    constructor({ clock, voices, onSourceActive, onEnded }) {
        this.clock = clock;
        this.voices = voices; // { pitched: PitchedSynth, ... }
        this.onSourceActive = onSourceActive;
        this.onEnded = onEnded;

        /** @type {EngineEvent[]} */
        this._events = [];
        this._nextIdx = 0;
        this._loop = null;
        this._intervalId = null;
        this._running = false;
        this._lookaheadSec = 0.1; // 100ms — matches WP RhythmPlayer
        this._tickMs = 25;
    }

    /** @param {EngineEvent[]} events */
    load(events, opts = {}) {
        this._events = [...events].sort((a, b) => a.time - b.time);
        this._nextIdx = 0;
        this._loop = opts.loop || null;
    }

    clear() {
        this._events = [];
        this._nextIdx = 0;
    }

    start() {
        if (this._running) return;
        this._running = true;
        this._nextIdx = 0;
        this._intervalId = setInterval(() => this._tick(), this._tickMs);
    }

    stop() {
        this._running = false;
        if (this._intervalId) clearInterval(this._intervalId);
        this._intervalId = null;
        Object.values(this.voices).forEach(v => v.releaseAll?.());
    }

    _tick() {
        if (!this._running) return;

        const currentBeat = this.clock.currentBeat();
        const bpm = Tone.getTransport().bpm.value;
        const secPerBeat = 60 / bpm;
        const lookaheadBeats = this._lookaheadSec / secPerBeat;
        const horizonBeat = currentBeat + lookaheadBeats;

        while (this._nextIdx < this._events.length) {
            const ev = this._events[this._nextIdx];
            if (ev.time > horizonBeat) break;

            const deltaBeats = ev.time - currentBeat;
            const when = this.clock.now() + deltaBeats * secPerBeat;
            const durSec = ev.duration * secPerBeat;

            this._dispatch(ev, when, durSec);

            if (ev.sourceId && this.onSourceActive) {
                const fireInMs = Math.max(0, (when - this.clock.now()) * 1000);
                setTimeout(() => this.onSourceActive(ev.sourceId), fireInMs);
            }

            this._nextIdx++;
        }

        if (this._nextIdx >= this._events.length) {
            // Events exhausted. Let the tail ring out, then signal ended.
            if (this._events.length > 0) {
                const last = this._events[this._events.length - 1];
                const endBeat = last.time + last.duration;
                if (currentBeat >= endBeat) {
                    this._running = false;
                    if (this._intervalId) clearInterval(this._intervalId);
                    this._intervalId = null;
                    this.onEnded?.();
                }
            }
        }
    }

    _dispatch(ev, when, durSec) {
        const voice = this.voices[ev.voice];
        if (!voice) return;
        if (ev.voice === 'pitched' && ev.pitch != null) {
            voice.trigger(ev.pitch, when, durSec, ev.velocity ?? 0.8);
        }
        // Other voices land in later phases.
    }
}
