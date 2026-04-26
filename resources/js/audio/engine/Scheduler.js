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
        this._loop = false;
        this._loopBeats = 0;
        this._loopCycle = 0; // how many full loops we've elapsed since start
        this._intervalId = null;
        this._running = false;
        this._lookaheadSec = 0.1; // 100ms — matches WP RhythmPlayer
        this._tickMs = 25;
    }

    /**
     * @param {EngineEvent[]} events
     * @param {Object} [opts]
     * @param {boolean} [opts.loop=false]
     * @param {number}  [opts.loopBeats=0] — length of one loop iteration in beats
     */
    load(events, opts = {}) {
        this._events = [...events].sort((a, b) => a.time - b.time);
        this._nextIdx = 0;
        this._loop = !!opts.loop;
        this._loopBeats = opts.loopBeats || 0;
        this._loopCycle = 0;
    }

    clear() {
        this._events = [];
        this._nextIdx = 0;
    }

    /** Move the playback head to a given beat without stopping. */
    seekTo(beat) {
        this._nextIdx = this._events.findIndex(ev => ev.time >= beat);
        if (this._nextIdx === -1) this._nextIdx = this._events.length;
    }

    /** True when the given clock beat is at or past the end of all loaded events. */
    isAtOrPastEnd(beat) {
        if (this._loop || this._events.length === 0) return false;
        const last = this._events[this._events.length - 1];
        return beat >= last.time + last.duration;
    }

    start() {
        // Always reset — clears stale _nextIdx when events were replaced via load()
        // while a previous playback was still running.
        if (this._intervalId) clearInterval(this._intervalId);
        this._running = true;
        this._nextIdx = 0;
        this._loopCycle = 0;
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

        // Walk the event list. If looping, wrap around and shift event times
        // forward by (_loopCycle * _loopBeats) so each iteration's absolute
        // time keeps advancing with the clock.
        while (true) {
            if (this._nextIdx >= this._events.length) {
                if (this._loop && this._loopBeats > 0) {
                    this._loopCycle++;
                    this._nextIdx = 0;
                } else {
                    break;
                }
            }

            const ev = this._events[this._nextIdx];
            const evTime = ev.time + this._loopCycle * this._loopBeats;
            if (evTime > horizonBeat) break;

            const deltaBeats = evTime - currentBeat;
            const when = this.clock.now() + deltaBeats * secPerBeat;
            const durSec = ev.duration * secPerBeat;

            this._dispatch(ev, when, durSec);

            if (ev.sourceId && this.onSourceActive) {
                const fireInMs = Math.max(0, (when - this.clock.now()) * 1000);
                setTimeout(() => this.onSourceActive(ev.sourceId), fireInMs);
            }

            this._nextIdx++;
        }

        // Non-loop end-of-events handling: let the tail ring out, then signal ended.
        if (!this._loop && this._nextIdx >= this._events.length && this._events.length > 0) {
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

    _dispatch(ev, when, durSec) {
        if (ev.voice === 'pitched') {
            const voice = this.voices.pitched;
            if (voice && Number.isFinite(ev.pitch)) {
                voice.trigger(ev.pitch, when, durSec, ev.velocity ?? 0.8);
            }
            return;
        }

        if (ev.voice === 'percussion') {
            const instrument = ev.sample;
            const variant    = ev.variant ?? 'soft';
            const velocity   = ev.velocity ?? 0.85;

            // Try sample-based playback first; fall back to synth if buffer is missing.
            const sampler  = this.voices.percussion;
            const fallback = this.voices.percFallback;

            if (sampler?.ready) {
                // PercussionSampler.trigger returns silently if buffer is null —
                // detect that case by checking the buffer map directly.
                const key = `${instrument}_${variant}`;
                const hasBuffer = sampler.buffers?.get(key) || sampler.buffers?.get(`${instrument}_soft`);
                if (hasBuffer) {
                    sampler.trigger(instrument, variant, when, velocity);
                    return;
                }
            }

            // Synthesised fallback
            if (fallback) {
                this._dispatchFallback(fallback, instrument, when);
            }
            return;
        }
    }

    /**
     * Route a percussion event to the FallbackSynths by instrument name.
     */
    _dispatchFallback(fb, instrument, when) {
        const register = (instrument === 'kick') ? 'low' : 'high';
        switch (instrument) {
            case 'kick':
                fb.playMuted(when, 'low');
                break;
            case 'shaker':
            case 'tamborim':
                fb.playClave(when, register);
                break;
            case 'hihat_brush':
                fb.playHiHat(when);
                break;
            case 'brush_snare':
                fb.playMuted(when, 'high');
                break;
            default:
                fb.playClave(when, 'high');
        }
    }
}
