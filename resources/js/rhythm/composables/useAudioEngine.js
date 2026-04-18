import { ref, onBeforeUnmount, watch } from 'vue';
import { getAudioEngine } from '../../audio/engine/AudioEngine.js';

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

    async function play() {
        if (!engine.isInited) await init();
        // Events are loaded by the parent component via loadAllEvents()
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

    onBeforeUnmount(() => {
        unsubs.forEach(fn => fn?.());
        unsubs = [];
        engine.stop();
    });

    return { isPlaying, currentBeat, activeSourceId, blend, play, stop, toggle };
}
