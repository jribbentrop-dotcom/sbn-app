import * as Tone from 'tone';

/**
 * Nylon guitar sampler using MusyngKite samples (E2 A2 D3 G3 B3 E4).
 * Tone.Sampler pitch-shifts between the sparse sample set.
 * Interface mirrors PitchedSynth so callers are interchangeable.
 */
export class NylonSampler {
    constructor() {
        this._ready = false;
        this._readyPromise = null;
        this.sampler = null;
    }

    /** True once the samples have loaded and trigger() will actually sound. */
    get ready() {
        return this._ready;
    }

    /**
     * @param {string} basePath — e.g. '/audio/nylon/'
     * @returns {Promise<void>}
     */
    init(basePath = '/audio/nylon/') {
        if (this._readyPromise) return this._readyPromise;
        this._readyPromise = new Promise((resolve) => {
            this.filter  = new Tone.Filter(1800, 'lowpass');
            this.eq      = new Tone.EQ3(0, -4, 1);
            this.reverb  = new Tone.Reverb({ decay: 3.2, wet: 0.5 });
            this.sampler = new Tone.Sampler(
                {
                    E2: `${basePath}E2.mp3`,
                    A2: `${basePath}A2.mp3`,
                    D3: `${basePath}D3.mp3`,
                    G3: `${basePath}G3.mp3`,
                    B3: `${basePath}B3.mp3`,
                    E4: `${basePath}E4.mp3`,
                },
                {
                    onload: () => {
                        this._ready = true;
                        resolve();
                    },
                    onerror: (err) => {
                        console.warn('[NylonSampler] load error', err);
                        resolve();
                    },
                    release: 0.25,
                    volume: 0,
                }
            );
            this.sampler.chain(this.filter, this.eq, this.reverb, Tone.getDestination());
        });
        return this._readyPromise;
    }

    /**
     * @param {number} midi       MIDI note number
     * @param {number} time       Audio-context time in seconds
     * @param {number} durationSec
     * @param {number} velocity   0..1
     */
    /**
     * @param {number} midi
     * @param {number} time       — Tone scheduled time (from scheduleRepeat callback)
     * @param {number} durationSec
     * @param {number} velocity   0..1
     * @param {number} [offsetSec=0] — additional stagger offset in seconds
     */
    trigger(midi, time, durationSec, velocity = 0.8, offsetSec = 0) {
        if (!this._ready || !this.sampler) return;
        if (!Number.isFinite(midi)) return;
        const note = Tone.Frequency(midi, 'midi').toNote();
        const when = offsetSec > 0 ? time + offsetSec : time;
        this.sampler.triggerAttackRelease(note, durationSec, when, velocity);
    }

    releaseAll() {
        this.sampler?.releaseAll();
    }

    get gainNode() {
        return this.sampler?.volume;
    }

    setVolume(db) {
        if (this.sampler) this.sampler.volume.value = db;
    }

    dispose() {
        this.sampler?.dispose();
        this.filter?.dispose();
        this.eq?.dispose();
        this.reverb?.dispose();
        this.sampler = null;
        this.filter  = null;
        this.eq      = null;
        this.reverb  = null;
        this._ready = false;
        this._readyPromise = null;
    }
}
