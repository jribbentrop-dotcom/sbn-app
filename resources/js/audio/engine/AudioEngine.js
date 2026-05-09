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

        // Build the audio graph:
        //   samplesBus ─┐
        //               ├──> destination
        //   demoBus    ─┘
        // setBlend(v) cross-fades between them with an equal-power curve.
        const rawCtx = Tone.getContext().rawContext;
        this._rawCtx = rawCtx;
        this._samplesBus = rawCtx.createGain();
        this._demoBus = rawCtx.createGain();
        this._samplesBus.connect(rawCtx.destination);
        this._demoBus.connect(rawCtx.destination);
        // Route the percussion sampler through the samples bus.
        percSampler.setOutput(this._samplesBus);
        // Default blend: pure samples.
        this._blend = 0;
        this._applyBlend();

        // Demo playback state.
        /** @type {AudioBuffer|null} */
        this._demoBuffer = null;
        /** @type {string|null} */
        this._demoUrl = null;
        /** @type {AudioBufferSourceNode|null} */
        this._demoSource = null;
        this._demoOffsetBeats = 0;
        this._demoLoop = false;

        this._voices = {
            pitched:    new PitchedSynth(),
            percussion: percSampler,
            percFallback,
        };

        this._scheduler = new Scheduler({
            clock: this._clock,
            voices: this._voices,
            onSourceActive: (id) => this._emit('sourceActive', id),
            onEnded: () => {
                // Reset the clock so a subsequent play() starts from beat 0.
                this._clock?.stop();
                this._clock?.seek(0);
                this._stopDemo();
                this._emit('ended');
            },
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

    /**
     * @param {EngineEvent[]} events
     * @param {Object} [opts]
     * @param {boolean} [opts.loop=false]
     * @param {number}  [opts.loopBeats=0]
     * @param {string|null} [opts.demoUrl=null] — optional MP3 URL to blend with the sample pattern
     * @param {number}  [opts.demoOffsetBeats=0] — beats delay before demo source starts (relative to play())
     */
    load(events, opts = {}) {
        this._ensureInited();
        this._scheduler.load(events, opts);

        this._demoOffsetBeats = opts.demoOffsetBeats || 0;
        this._demoLoop = !!opts.loop;

        // If demo URL changed, kick off the MP3 fetch. We keep the promise so
        // play() can await it — otherwise the first play() fires before the
        // buffer is decoded and the demo stays silent.
        const newUrl = opts.demoUrl ?? null;
        if (newUrl !== this._demoUrl) {
            this._demoUrl = newUrl;
            this._demoBuffer = null;
            this._demoLoadPromise = null;
            if (newUrl) {
                this._demoLoadPromise = this._loadDemo(newUrl).catch(err => {
                    console.warn('[AudioEngine] demo load failed', newUrl, err);
                });
            }
        }
    }

    clear() {
        this._scheduler?.clear();
    }

    async play(sourceTag = null) {
        this._ensureInited();
        this._scheduler.stop();
        this._stopDemo();
        // If the clock was left running past the end of the previous pattern
        // (onEnded path), rewind it before starting.
        if (this._scheduler.isAtOrPastEnd(this._clock.currentBeat())) {
            this._clock.stop();
            this._clock.seek(0);
        }
        this._emit('playStarted', sourceTag); // lets sibling composables clear their isPlaying flag
        await this._clock.start();
        this._emit('unlock');

        // Wait for the demo MP3 decode to finish so the first play() isn't silent
        // on the demo bus. If no demo, this is a no-op.
        if (this._demoLoadPromise) {
            await this._demoLoadPromise;
        }

        this._scheduler.start();
        this._scheduler.seekTo(this._clock.currentBeat());

        // Start the demo MP3 (if loaded) aligned with the first beat of playback.
        this._startDemo();
    }

    pause() {
        this._scheduler?.stop();
        this._clock?.pause();
        this._stopDemo();
    }

    stop() {
        this._scheduler?.stop();
        this._clock?.stop();
        this._clock?.seek(0);
        this._stopDemo();
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
     * Cross-fade between the samples bus and the demo bus.
     * @param {number} value01 — 0 = pure samples, 1 = pure demo
     */
    setBlend(value01) {
        this._blend = Math.max(0, Math.min(1, value01));
        this._applyBlend();
    }

    getBlend() {
        return this._blend ?? 0;
    }

    /** True when a demo audio track has been loaded and is ready to play. */
    isDemoReady() {
        return !!this._demoBuffer;
    }

    _applyBlend() {
        if (!this._samplesBus || !this._demoBus) return;
        // Biased crossfade — samples are louder overall and the demo ramps in
        // later than a pure equal-power curve would give. Tuned to the current
        // sample loudness; revisit if the sample WAVs are re-normalized.
        const v = this._blend;
        const now = this._rawCtx.currentTime;
        const SAMPLES_BASE = 1.3;
        const DEMO_BASE    = 0.85;
        const samplesGain = SAMPLES_BASE * Math.pow(1 - v, 1.3);
        const demoGain    = DEMO_BASE    * Math.pow(v, 1.7);
        this._samplesBus.gain.setValueAtTime(samplesGain, now);
        this._demoBus.gain.setValueAtTime(demoGain, now);
    }

    async _loadDemo(url) {
        const resp = await fetch(url);
        if (!resp.ok) throw new Error('HTTP ' + resp.status);
        const ab = await resp.arrayBuffer();
        const buf = await this._rawCtx.decodeAudioData(ab);
        // Guard: if the URL changed while we were fetching, discard.
        if (url !== this._demoUrl) return;
        this._demoBuffer = buf;
    }

    _startDemo() {
        if (!this._demoBuffer) {
            if (this._demoUrl) console.warn('[AudioEngine] demo buffer not ready for', this._demoUrl);
            return;
        }
        if (!this._rawCtx || !this._demoBus) return;
        const src = this._rawCtx.createBufferSource();
        src.buffer = this._demoBuffer;
        src.loop = this._demoLoop;
        src.connect(this._demoBus);
        const bpm = Tone.getTransport().bpm.value;
        const secPerBeat = 60 / bpm;
        const delay = Math.max(0, this._demoOffsetBeats * secPerBeat);
        src.start(this._rawCtx.currentTime + delay);
        this._demoSource = src;
    }

    _stopDemo() {
        if (!this._demoSource) return;
        try { this._demoSource.stop(); } catch (_) { /* already stopped */ }
        try { this._demoSource.disconnect(); } catch (_) {}
        this._demoSource = null;
    }

    /**
     * @param {'tick'|'sourceActive'|'ended'|'unlock'|'playStarted'} event
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
