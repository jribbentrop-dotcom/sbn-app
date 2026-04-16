import * as Tone from 'tone';
import { ToneClock } from './ToneClock.js';
import { Scheduler } from './Scheduler.js';
import { PitchedSynth } from './voices/PitchedSynth.js';

/**
 * @typedef {import('../types.js').EngineEvent} EngineEvent
 * @typedef {import('../types.js').Beats} Beats
 * @typedef {import('../types.js').Voice} Voice
 */

/**
 * AudioEngine — the public facade.
 * See docs/audio-engine-contract.md §4 for the contract.
 *
 * Phase 7C scope: PitchedSynth only. Percussion sampler, fallback synths,
 * and blend slider land in later phases per the contract's §11.
 */
export class AudioEngine {
    constructor() {
        this._inited = false;
        this._clock = null;
        this._scheduler = null;
        this._voices = null;
        this._listeners = {
            tick: new Set(),
            sourceActive: new Set(),
            ended: new Set(),
            unlock: new Set(),
        };
    }

    async init({ samplesBaseUrl = '', clock = null, bpm = 120 } = {}) {
        if (this._inited) return;

        this._clock = clock || new ToneClock(bpm);
        this._voices = {
            pitched: new PitchedSynth(),
        };
        this._scheduler = new Scheduler({
            clock: this._clock,
            voices: this._voices,
            onSourceActive: (id) => this._emit('sourceActive', id),
            onEnded: () => this._emit('ended'),
        });

        this._clock.onTick((beat) => this._emit('tick', beat));

        this._samplesBaseUrl = samplesBaseUrl; // reserved for PercussionSampler (later phase)
        this._inited = true;
    }

    dispose() {
        if (!this._inited) return;
        this._scheduler?.stop();
        this._clock?.stop();
        Object.values(this._voices || {}).forEach(v => v.dispose?.());
        Object.keys(this._listeners).forEach(k => this._listeners[k].clear());
        this._inited = false;
    }

    /** @param {EngineEvent[]} events */
    load(events, opts = {}) {
        this._ensureInited();
        this._scheduler.load(events, opts);
    }

    clear() {
        this._scheduler?.clear();
    }

    async play() {
        this._ensureInited();
        await this._clock.start();
        this._emit('unlock');
        this._scheduler.start();
    }

    pause() {
        this._scheduler?.stop();
        this._clock?.pause();
    }

    stop() {
        this._scheduler?.stop();
        this._clock?.stop();
        this._clock?.seek(0);
    }

    seek(beat) {
        this._clock?.seek(beat);
    }

    setTempo(bpm) {
        this._clock?.setTempo(bpm);
    }

    setMasterVolume(db) {
        Tone.getDestination().volume.value = db;
    }

    /**
     * @param {'tick'|'sourceActive'|'ended'|'unlock'} event
     * @param {Function} cb
     */
    on(event, cb) {
        const set = this._listeners[event];
        if (!set) return () => {};
        set.add(cb);
        return () => set.delete(cb);
    }

    _emit(event, payload) {
        this._listeners[event]?.forEach(cb => cb(payload));
    }

    _ensureInited() {
        if (!this._inited) throw new Error('AudioEngine: call init() first.');
    }
}

let _singleton = null;
export function getAudioEngine() {
    if (!_singleton) _singleton = new AudioEngine();
    return _singleton;
}
