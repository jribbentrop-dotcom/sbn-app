import * as Tone from 'tone';
import { ToneClock } from './ToneClock.js';
import { Scheduler } from './Scheduler.js';
import { PitchedSynth } from './voices/PitchedSynth.js';
import { PercussionSampler } from './voices/PercussionSampler.js';
import { FallbackSynths } from './voices/FallbackSynths.js';

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
            tick:        new Set(),
            sourceActive: new Set(),
            ended:       new Set(),
            unlock:      new Set(),
            playStarted: new Set(), // fired before every play — lets other composables clear isPlaying
        };
    }

    async init({ samplesBaseUrl = '', clock = null, bpm = 120 } = {}) {
        if (this._inited) return;

        this._clock = clock || new ToneClock(bpm);

        const percSampler  = new PercussionSampler();
        const percFallback = new FallbackSynths();

        // Load samples if a base URL is provided; failures are caught inside init().
        if (samplesBaseUrl) {
            await percSampler.init(samplesBaseUrl);
        }

        this._voices = {
            pitched:    new PitchedSynth(),
            percussion: percSampler,
            percFallback,
        };

        this._scheduler = new Scheduler({
            clock: this._clock,
            voices: this._voices,
            onSourceActive: (id) => this._emit('sourceActive', id),
            onEnded: () => this._emit('ended'),
        });

        this._clock.onTick((beat) => this._emit('tick', beat));

        this._samplesBaseUrl = samplesBaseUrl;
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
        this._scheduler.stop();
        this._emit('playStarted'); // lets sibling composables clear their isPlaying flag
        await this._clock.start();
        this._emit('unlock');
        // start() resets _nextIdx = 0 (prevents polyphony burst on fresh load),
        // then seekTo() immediately corrects the index to the current clock position
        // before the first 25ms tick fires.
        this._scheduler.start();
        this._scheduler.seekTo(this._clock.currentBeat());
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
        this._scheduler?.seekTo(beat);
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

    get isInited() {
        return this._inited;
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
