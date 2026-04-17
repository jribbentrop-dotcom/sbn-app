import { ref, onBeforeUnmount, watch } from 'vue';
import { getAudioEngine } from '../../audio/engine/AudioEngine.js';
import { chordProgressionToEvents } from '../../audio/adapters/chordProgressionToEvents.js';

/**
 * Thin Vue wrapper around the AudioEngine singleton for leadsheet chord playback.
 * Handles lifecycle, reactive play-state, and UI highlighting.
 *
 * @param {import('vue').Ref} model — The reactive ChordProgression model.
 */
export function useAudioEngine(model) {
    const engine = getAudioEngine();
    const isPlaying = ref(false);
    const currentBeat = ref(0);
    const activeSourceId = ref(null);

    let unsubs = [];

    async function init() {
        const bpm = model.value?.bpm || 120;
        // Leadsheet does not use percussion samples
        await engine.init({ bpm });

        unsubs.push(
            engine.on('tick', (beat) => { currentBeat.value = beat; }),
            engine.on('sourceActive', (id) => { activeSourceId.value = id; }),
            engine.on('ended', () => {
                isPlaying.value = false;
                activeSourceId.value = null;
            }),
        );
    }

    function loadFromModel() {
        if (!model.value) return;
        const events = chordProgressionToEvents(model.value, { startBeat: 0 });
        engine.load(events);
        const bpm = model.value.bpm || 120;
        engine.setTempo(bpm);
    }

    async function play() {
        if (!engine.isInited) await init();
        loadFromModel();
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

    // Re-load events when the model changes while stopped
    watch(model, () => {
        if (!isPlaying.value && engine.isInited) loadFromModel();
    }, { deep: false });

    onBeforeUnmount(() => {
        unsubs.forEach(fn => fn?.());
        unsubs = [];
        engine.stop();
    });

    return { isPlaying, currentBeat, activeSourceId, play, stop, toggle };
}
