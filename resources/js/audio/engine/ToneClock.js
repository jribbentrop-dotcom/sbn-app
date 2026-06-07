import * as Tone from 'tone';

/**
 * @typedef {import('../types.js').Beats} Beats
 * @typedef {import('../types.js').Unsubscribe} Unsubscribe
 */

/**
 * Default clock: Tone.Transport owns the timeline.
 * Phase 7C uses this everywhere. A MediaElementClock will slot in later
 * without touching the scheduler or adapters.
 */
export class ToneClock {
    constructor(bpm = 120) {
        Tone.getTransport().bpm.value = bpm;
        this._bpm = bpm;
        this._tickSubs = new Set();
        this._tickEventId = null;
        this.isExternal = false;
    }

    now() {
        return Tone.now();
    }

    currentBeat() {
        // Tone.Transport.position is "bars:beats:sixteenths". Ticks are easier.
        const ppq = Tone.getTransport().PPQ;
        return Tone.getTransport().ticks / ppq;
    }

    async start() {
        await Tone.start();
        Tone.getTransport().start();
        // Schedule a per-sixteenth tick for UI subscribers.
        if (this._tickEventId == null) {
            this._tickEventId = Tone.getTransport().scheduleRepeat((time) => {
                const beat = this.currentBeat();
                this._tickSubs.forEach(cb => cb(beat, time));
            }, '16n');
        }
    }

    pause() {
        Tone.getTransport().pause();
    }

    stop() {
        Tone.getTransport().stop();
        if (this._tickEventId != null) {
            Tone.getTransport().clear(this._tickEventId);
            this._tickEventId = null;
        }
    }

    seek(beat) {
        const ppq = Tone.getTransport().PPQ;
        Tone.getTransport().ticks = Math.round(beat * ppq);
    }

    setTempo(bpm) {
        this._bpm = bpm;
        Tone.getTransport().bpm.value = bpm;
    }

    /** @param {(beat:Beats)=>void} cb @returns {Unsubscribe} */
    onTick(cb) {
        this._tickSubs.add(cb);
        return () => this._tickSubs.delete(cb);
    }
}
