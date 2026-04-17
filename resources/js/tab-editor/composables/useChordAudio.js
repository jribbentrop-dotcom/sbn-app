import { ref, onBeforeUnmount, watch } from 'vue';
import { getAudioEngine } from '../../audio/engine/AudioEngine.js';
import { chordVoicingsToEvents } from '../../audio/adapters/chordVoicingsToEvents.js';

/**
 * Audio composable for chord-view playback.
 * Plays one strum per measure using the model's assigned voicings.
 *
 * Uses a local _inited flag (not engine.isInited) so that listener registration
 * always happens on this composable's first play(), regardless of whether the
 * tab composable already initialised the shared engine.
 *
 * Pause/resume semantics:
 *   pause()  — stops audio but keeps the clock position; next play() resumes from there
 *   reset()  — full stop + seek(0); next play() starts from beat 0
 *
 * @param {import('vue').Ref} model  The reactive tab model from useTabModel.
 */
export function useChordAudio(model) {
    const engine         = getAudioEngine();
    const isPlaying      = ref(false);
    const currentBeat    = ref(0);
    const activeSourceId = ref(null);

    let unsubs  = [];
    let _inited = false; // composable-local flag, independent of engine.isInited

    async function init() {
        await engine.init({ bpm: model.value?.tempo || 120 });
        if (_inited) return;
        _inited = true;

        unsubs.push(
            engine.on('tick',        (beat) => { currentBeat.value = beat; }),
            // Only update activeSourceId when *this* composable is playing —
            // prevents tab sourceIds contaminating the chord card highlight.
            engine.on('sourceActive',(id)   => { if (isPlaying.value) activeSourceId.value = id; }),
            engine.on('ended',       ()     => { isPlaying.value = false; activeSourceId.value = null; }),
            // Another composable called engine.play() — clear our state.
            engine.on('playStarted', ()     => { isPlaying.value = false; activeSourceId.value = null; }),
        );
    }

    function loadFromModel() {
        if (!model.value) return;
        const events = chordVoicingsToEvents(model.value, { startBeat: 0 });
        engine.load(events);
        engine.setTempo(model.value.tempo || 120);
    }

    async function play() {
        await init(); // always init this composable; engine.init() is idempotent
        loadFromModel();
        // If we have a stored position (from prior pause or seek), sync the engine
        // clock to it before play() reads clock.currentBeat().
        if (currentBeat.value > 0) {
            engine.seek(currentBeat.value);
        }
        await engine.play();
        isPlaying.value = true;
    }

    /** Pause — stops audio but keeps the current clock position. */
    function pause() {
        engine.pause();
        isPlaying.value      = false;
        activeSourceId.value = null;
        // currentBeat.value intentionally NOT reset — retains paused position
    }

    /** Full stop — seek to beat 0 and clear all state. */
    function reset() {
        engine.stop();   // scheduler.stop() + clock.stop() + clock.seek(0)
        isPlaying.value      = false;
        activeSourceId.value = null;
        currentBeat.value    = 0;
    }

    /** Legacy alias — kept for any callers that still reference stop(). */
    function stop() { pause(); }

    function toggle() {
        if (isPlaying.value) pause();
        else play();
    }

    /** Seek to beat and update the displayed position immediately. */
    function seek(beat) {
        engine.seek(beat);
        currentBeat.value = beat;
    }

    watch(model, () => {
        if (!isPlaying.value && _inited) loadFromModel();
    }, { deep: false });

    onBeforeUnmount(() => {
        unsubs.forEach(fn => fn?.());
        unsubs  = [];
        _inited = false;
        engine.pause(); // keep position; don't reset on unmount
    });

    return { isPlaying, currentBeat, activeSourceId, play, pause, reset, stop, toggle, seek };
}
