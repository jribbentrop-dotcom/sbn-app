import * as Tone from 'tone';

/**
 * FallbackSynths — synthesized percussion sounds for when WAV samples are unavailable.
 * Ports course-player.js lines 1037-1162 using raw WebAudio API.
 */
export class FallbackSynths {
    constructor() {
        /** @type {AudioContext|null} */
        this._audioContext = null;
    }

    /**
     * Get AudioContext via Tone.js (architectural constraint — NEVER use new AudioContext()).
     * @returns {AudioContext}
     */
    get audioContext() {
        if (!this._audioContext) {
            this._audioContext = Tone.getContext().rawContext;
        }
        return this._audioContext;
    }

    /**
     * Clave sound — triangle wave with bandpass filter.
     * @param {number} time — AudioContext time
     * @param {'high'|'low'} register
     */
    playClave(time, register) {
        const ctx = this.audioContext;
        const osc = ctx.createOscillator();
        const gain = ctx.createGain();
        const filter = ctx.createBiquadFilter();

        osc.type = 'triangle';

        if (register === 'high') {
            osc.frequency.setValueAtTime(2500, time);
            osc.frequency.exponentialRampToValueAtTime(1800, time + 0.01);
            filter.frequency.setValueAtTime(3000, time);
            gain.gain.setValueAtTime(0.25, time);
        } else {
            osc.frequency.setValueAtTime(1800, time);
            osc.frequency.exponentialRampToValueAtTime(1200, time + 0.01);
            filter.frequency.setValueAtTime(2000, time);
            gain.gain.setValueAtTime(0.3, time);
        }

        gain.gain.exponentialRampToValueAtTime(0.01, time + 0.06);
        filter.type = 'bandpass';
        filter.Q.setValueAtTime(8, time);

        osc.connect(filter);
        filter.connect(gain);
        gain.connect(ctx.destination);

        osc.start(time);
        osc.stop(time + 0.08);
    }

    /**
     * Muted string sound — square wave with lowpass filter.
     * @param {number} time — AudioContext time
     * @param {'high'|'low'} register
     */
    playMuted(time, register) {
        const ctx = this.audioContext;
        const osc = ctx.createOscillator();
        const gain = ctx.createGain();
        const filter = ctx.createBiquadFilter();

        osc.type = 'square';

        if (register === 'low') {
            osc.frequency.setValueAtTime(110, time);
            filter.frequency.setValueAtTime(300, time);
        } else {
            osc.frequency.setValueAtTime(330, time);
            filter.frequency.setValueAtTime(800, time);
        }

        gain.gain.setValueAtTime(0.2, time);
        gain.gain.exponentialRampToValueAtTime(0.01, time + 0.08);
        filter.type = 'lowpass';

        osc.connect(filter);
        filter.connect(gain);
        gain.connect(ctx.destination);

        osc.start(time);
        osc.stop(time + 0.1);
    }

    /**
     * Hi-hat sound — white noise with bandpass + highpass filters.
     * @param {number} time — AudioContext time
     */
    playHiHat(time) {
        const ctx = this.audioContext;
        const bufferSize = ctx.sampleRate * 0.05;
        const buffer = ctx.createBuffer(1, bufferSize, ctx.sampleRate);
        const output = buffer.getChannelData(0);

        for (let i = 0; i < bufferSize; i++) {
            output[i] = Math.random() * 2 - 1;
        }

        const noise = ctx.createBufferSource();
        noise.buffer = buffer;

        const bp = ctx.createBiquadFilter();
        bp.type = 'bandpass';
        bp.frequency.setValueAtTime(8000, time);

        const hp = ctx.createBiquadFilter();
        hp.type = 'highpass';
        hp.frequency.setValueAtTime(7000, time);

        const gain = ctx.createGain();
        gain.gain.setValueAtTime(0.06, time);
        gain.gain.exponentialRampToValueAtTime(0.01, time + 0.04);

        noise.connect(bp);
        bp.connect(hp);
        hp.connect(gain);
        gain.connect(ctx.destination);

        noise.start(time);
        noise.stop(time + 0.05);
    }

    /**
     * Pitched note fallback — sine wave.
     * Used for frequencies 493.88 (B4) and 220 (A3) per WP source.
     * @param {number} time — AudioContext time
     * @param {number} freq — frequency in Hz
     * @param {number} durationSec
     * @param {number} volume — 0..1
     */
    playNote(time, freq, durationSec, volume) {
        const ctx = this.audioContext;
        const osc = ctx.createOscillator();
        const gain = ctx.createGain();

        osc.type = 'sine';
        osc.frequency.setValueAtTime(freq, time);

        gain.gain.setValueAtTime(0, time);
        gain.gain.linearRampToValueAtTime(volume, time + 0.01);
        gain.gain.exponentialRampToValueAtTime(0.01, time + durationSec);

        osc.connect(gain);
        gain.connect(ctx.destination);

        osc.start(time);
        osc.stop(time + durationSec);
    }

    /**
     * Release all voices. No-op for one-shot samples (required by Scheduler interface).
     */
    releaseAll() {
        // One-shot sounds release automatically
    }

    /**
     * Dispose and release resources.
     */
    dispose() {
        this._audioContext = null;
    }
}
