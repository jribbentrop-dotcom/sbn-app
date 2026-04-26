import * as Tone from 'tone';

/**
 * PercussionSampler — ports sbn-percussion.js sample loading + playback.
 * Loads WAV samples from a base URL and plays them on demand.
 * Falls back gracefully when files are missing.
 */
export class PercussionSampler {
    constructor() {
        this.ready = false;
        /** @type {Map<string, AudioBuffer|null>} */
        this.buffers = new Map();
        /** @type {AudioContext|null} */
        this._audioContext = null;
        /** @type {AudioNode|null} */
        this._output = null;
    }

    /**
     * Set the output node all triggered samples connect to. Defaults to
     * the context's destination if never called.
     */
    setOutput(node) {
        this._output = node;
    }

    /**
     * Initialize and load all percussion samples.
     * @param {string} samplesBaseUrl — base URL where WAV files reside
     * @returns {Promise<void>}
     */
    async init(samplesBaseUrl) {
        if (this.ready) return;

        // CRITICAL: Always obtain AudioContext via Tone.getContext().rawContext
        this._audioContext = Tone.getContext().rawContext;

        const base = samplesBaseUrl.replace(/\/$/, '') + '/';

        const files = [
            { key: 'shaker_soft', filename: 'shaker_soft.wav' },
            { key: 'shaker_accent', filename: 'shaker_accent.wav' },
            { key: 'tamborim_soft', filename: 'tamborim_soft.wav' },
            { key: 'tamborim_accent', filename: 'tamborim_accent.wav' },
            { key: 'kick_soft', filename: 'kick_soft.wav' },
            { key: 'kick_accent', filename: 'kick_accent.wav' },
            { key: 'hihat_brush_soft', filename: 'hihat_brush_soft.wav' },
            { key: 'hihat_brush_accent', filename: 'hihat_brush_accent.wav' },
            { key: 'brush_snare_soft', filename: 'brush_snare_soft.wav' },
            { key: 'brush_snare_accent', filename: 'brush_snare_accent.wav' },
        ];

        const promises = files.map(({ key, filename }) =>
            fetch(base + filename)
                .then(res => {
                    if (!res.ok) throw new Error('HTTP ' + res.status);
                    return res.arrayBuffer();
                })
                .then(ab => this._audioContext.decodeAudioData(ab))
                .then(buffer => {
                    this.buffers.set(key, buffer);
                })
                .catch(() => {
                    console.warn('[SBN] PercussionSampler: failed to load ' + filename);
                    this.buffers.set(key, null);
                })
        );

        // Use allSettled so one missing file doesn't abort the rest
        await Promise.allSettled(promises);

        this.ready = true;
    }

    /**
     * Trigger a percussion sample.
     * @param {string} instrument — e.g. 'shaker', 'tamborim', 'kick', 'hihat_brush', 'brush_snare'
     * @param {'soft'|'accent'} variant
     * @param {number} when — AudioContext time to schedule playback
     * @param {number} velocity — 0..1, controls gain
     */
    trigger(instrument, variant, when, velocity) {
        if (!this.ready || !this._audioContext) return;

        const key = `${instrument}_${variant}`;
        let buffer = this.buffers.get(key);

        // Fallback to soft variant if accent missing
        if (!buffer) {
            buffer = this.buffers.get(`${instrument}_soft`);
        }

        // If both are null, return silently (Mixer will route to FallbackSynths)
        if (!buffer) return;

        const source = this._audioContext.createBufferSource();
        source.buffer = buffer;

        const gainNode = this._audioContext.createGain();
        gainNode.gain.setValueAtTime(velocity, when);

        source.connect(gainNode);
        gainNode.connect(this._output ?? this._audioContext.destination);

        source.start(when);
    }

    /**
     * Release all voices. No-op for one-shot samples (required by Scheduler interface).
     */
    releaseAll() {
        // One-shot samples release automatically after playback
    }

    /**
     * Dispose and release resources.
     */
    dispose() {
        this.buffers.clear();
        this._audioContext = null;
        this.ready = false;
    }
}
