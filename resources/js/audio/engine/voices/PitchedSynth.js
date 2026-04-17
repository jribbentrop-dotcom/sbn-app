import * as Tone from 'tone';

/**
 * Port of assets/js/sbn-audio.js (legacy WP guitar engine).
 * Pure synthesis — no samples. Signal chain: PolySynth → EQ3 → Reverb → Limiter → out.
 * Configuration values preserved verbatim from the WP version to retain tuned feel.
 */
export class PitchedSynth {
    constructor() {
        this.synth = new Tone.PolySynth(Tone.Synth, {
            maxPolyphony: 64,
            oscillator: {
                type: 'custom',
                partials: [1.0, 0.4, 0.15, 0.05],
            },
            envelope: {
                attack:  0.002,
                decay:   0.08,
                sustain: 0.25,
                release: 0.6,
            },
            volume: -16,
        });

        this.eq     = new Tone.EQ3(-2, 0, -4);
        this.reverb = new Tone.Reverb({ decay: 1.6, wet: 0.18 });
        this.limiter = new Tone.Limiter(-3);

        this.synth.chain(this.eq, this.reverb, this.limiter, Tone.getDestination());
    }

    /**
     * @param {number} midi       MIDI note number
     * @param {number} time       Audio-context time in seconds
     * @param {number} durationSec
     * @param {number} velocity   0..1
     */
    trigger(midi, time, durationSec, velocity = 0.8) {
        if (!Number.isFinite(midi)) return;
        const freq = Tone.Frequency(midi, 'midi').toFrequency();
        this.synth.triggerAttackRelease(freq, durationSec, time, velocity);
    }

    releaseAll() {
        this.synth.releaseAll();
    }

    /**
     * Expose the synth's underlying gain node so Mixer can adjust per-voice volume.
     * @returns {Tone.Volume}
     */
    get gainNode() {
        return this.synth.volume;
    }

    /**
     * Set the synth volume in dB.
     * @param {number} db — decibels (0 = unity, -16 = default, -∞ = silent)
     */
    setVolume(db) {
        this.synth.volume.value = db;
    }

    dispose() {
        this.synth.dispose();
        this.eq.dispose();
        this.reverb.dispose();
        this.limiter.dispose();
    }
}
