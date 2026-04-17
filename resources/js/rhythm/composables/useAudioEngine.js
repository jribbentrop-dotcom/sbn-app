import { ref, onBeforeUnmount, watch } from 'vue';
import { getAudioEngine } from '../../audio/engine/AudioEngine.js';
import { rhythmPatternToEvents } from '../../audio/adapters/rhythmPatternToEvents.js';

/**
 * Thin Vue wrapper around the AudioEngine singleton for rhythm patterns.
 * Handles lifecycle, reactive play-state, UI highlighting, and sample/synth blend.
 *
 * @param {import('vue').Ref} model — The reactive RhythmPattern model.
 */
export function useAudioEngine(model) {
    const engine = getAudioEngine();
    const isPlaying = ref(false);
    const currentBeat = ref(0);
    const activeSourceId = ref(null);
    const blend = ref(1.0); // 1.0 = full samples, 0.0 = full synth fallback

    let unsubs = [];

    async function init() {
        const bpm = model.value?.bpm || 120;
        const samplesBaseUrl = window.sbnConfig?.samplesBaseUrl ?? '';
        await engine.init({ bpm, samplesBaseUrl });

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
        const events = rhythmPatternToEvents(model.value, { startBeat: 0 });
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

    // Watch blend and update engine
    watch(blend, (val) => {
        engine.setBlend?.(val);
    });

    // Re-load events when the model changes while stopped
    watch(model, () => {
        if (!isPlaying.value && engine.isInited) loadFromModel();
    }, { deep: false });

    onBeforeUnmount(() => {
        unsubs.forEach(fn => fn?.());
        unsubs = [];
        engine.stop();
    });

    return { isPlaying, currentBeat, activeSourceId, blend, play, stop, toggle };
}
