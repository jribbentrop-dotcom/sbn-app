import { ref, onBeforeUnmount } from 'vue';
import { getAudioEngine } from '../../audio/engine/AudioEngine.js';
import { chordProgressionToEvents } from '../../audio/adapters/chordProgressionToEvents.js';
import { rhythmPatternToEvents } from '../../audio/adapters/rhythmPatternToEvents.js';

/**
 * Thin Vue wrapper around the AudioEngine singleton for leadsheet chord + rhythm playback.
 * Handles lifecycle, reactive play-state, and UI highlighting.
 *
 * @param {import('vue').Ref} model      — The reactive ChordProgression model.
 * @param {import('vue').Ref} [rhythmSlug] — Optional reactive slug; resolved against
 *   window.__sbnRhythmPatterns to obtain a RhythmPattern player-data object.
 */
export function useAudioEngine(model, rhythmSlug = null) {
    const engine = getAudioEngine();
    const isPlaying = ref(false);
    const currentBeat = ref(0);
    const activeSourceId = ref(null);

    // Per-voice volume levels (0–1 linear).
    const volumeChord     = ref(1.0);
    const volumeRhythm    = ref(1.0);

    let unsubs = [];
    let _inited = false;

    async function init() {
        const bpm = model.value?.bpm || 120;
        const samplesBaseUrl = window.__sbnSamplesUrl ?? '';
        await engine.init({ bpm, samplesBaseUrl });
        if (_inited) return;
        _inited = true;

        unsubs.push(
            engine.on('tick',         (beat) => { currentBeat.value = beat; }),
            engine.on('sourceActive', (id)   => { activeSourceId.value = id; }),
            engine.on('ended',        ()     => {
                isPlaying.value = false;
                activeSourceId.value = null;
            }),
        );
    }

    /**
     * Build the merged event list from chord progression + rhythm pattern (if any),
     * looped to match the total chord duration.
     * @returns {import('../../audio/types.js').EngineEvent[]}
     */
    function buildEvents() {
        if (!model.value) return [];

        const chordEvents = chordProgressionToEvents(model.value);

        const totalBeats = chordEvents.length
            ? (chordEvents.at(-1).time + chordEvents.at(-1).duration)
            : 0;

        // Apply chord volume scaling
        const cv = volumeChord.value;
        const scaledChord = cv === 1.0
            ? chordEvents
            : chordEvents.map(e => ({ ...e, velocity: (e.velocity ?? 0.8) * cv }));

        if (!rhythmSlug?.value || !totalBeats) return scaledChord;

        const patternData = window.__sbnRhythmPatterns?.[rhythmSlug.value];
        if (!patternData) return scaledChord;

        // Determine pattern duration in beats from gridType + step count
        const STEP_BEATS = { eighth: 0.5, sixteenth: 0.25, triplet: 1 / 3 };
        const stepBeats  = STEP_BEATS[patternData.gridType] ?? 0.25;
        const patLen     = Math.max(
            (patternData.thumb?.length   ?? 0),
            (patternData.fingers?.length ?? 0)
        ) * stepBeats;

        if (!patLen) return scaledChord;

        // Loop the rhythm pattern to cover the full chord duration
        const rv = volumeRhythm.value;
        const rhythmEvents = [];
        let offset = 0;
        while (offset < totalBeats) {
            const slice = rhythmPatternToEvents(patternData, { startBeat: offset });
            for (const ev of slice) {
                if (ev.time >= totalBeats) break;
                rhythmEvents.push(rv === 1.0 ? ev : { ...ev, velocity: (ev.velocity ?? 0.85) * rv });
            }
            offset += patLen;
        }

        return [...scaledChord, ...rhythmEvents].sort((a, b) => a.time - b.time);
    }

    async function play() {
        if (!engine.isInited) await init();
        engine.load(buildEvents());
        await engine.play();
        isPlaying.value = true;
    }

    function stop() {
        engine.stop();
        isPlaying.value = false;
        activeSourceId.value = null;
        currentBeat.value = 0;
    }

    function toggle() {
        if (isPlaying.value) stop();
        else play();
    }

    /** Reload events without stopping (e.g. after volume change mid-playback). */
    function reloadEvents() {
        engine.load(buildEvents());
    }

    onBeforeUnmount(() => {
        unsubs.forEach(fn => fn?.());
        unsubs = [];
        _inited = false;
        engine.stop();
    });

    return {
        isPlaying, currentBeat, activeSourceId,
        volumeChord, volumeRhythm,
        play, stop, toggle, reloadEvents,
    };
}
