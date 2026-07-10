/**
 * Audio lifecycle for quiz surfaces.
 *
 * Quizzes are the first edu components that make sound — the 21 widgets under
 * resources/js/edu/widgets/ are silent. Rather than have each question type
 * re-learn the engine's rules, this composable owns them:
 *
 *   - The AudioEngine is a shared singleton and only ONE consumer plays at a
 *     time. When another surface starts, it fires `playStarted`, and every
 *     other component must drop its local `isPlaying` or it will show a stuck
 *     pause button forever.
 *   - `init()` must be awaited before anything sounds, and is idempotent.
 *   - Listeners must be torn down on unmount or a re-mounted quiz double-fires.
 *
 * Three play primitives cover every prompt kind (see QuizPrompt.vue):
 *   playChord()   — strums a chord diagram, like ChordCard does
 *   playRhythm()  — loops a rhythm pattern on the transport, like RhythmStrip
 *   playNotes()   — melodic or harmonic note sequence, for intervals/ear tests
 */

import { onBeforeUnmount, ref, type Ref } from 'vue';
import { getAudioEngine } from '../../audio/engine/AudioEngine.js';
import { getSharedNylon } from '../../audio/engine/voices/sharedNylon';
import { chordDiagramToEvents } from '../../audio/adapters/chordDiagramToEvents.js';
import { rhythmPatternToEvents } from '../../audio/adapters/rhythmPatternToEvents.js';

/** Percussion samples live here; the nylon sampler loads separately. */
const PERC_SAMPLES_URL = '/audio/rhythm-samples/';
const NYLON_SAMPLES_URL = '/audio/nylon/';

/** Tempo the off-transport helpers (chord strum, note sequences) reckon in. */
const PREVIEW_BPM = 120;
const BEAT_SEC = 60 / PREVIEW_BPM;

/** Per-string delay of a strum, in beats. Matches ChordCard's feel. */
const STRUM_STAGGER_BEATS = 0.28;

export interface QuizAudio {
  /** True while THIS surface believes it is sounding. */
  isPlaying: Ref<boolean>;
  /** Strum a chord diagram (the `/api/sbn/chords/{slug}` payload). */
  playChord: (chord: { id: number; diagram_data: unknown }) => Promise<void>;
  /** Loop a rhythm pattern on the shared transport. Call stop() to end it. */
  playRhythm: (pattern: Record<string, unknown>, bpm?: number, loop?: boolean) => Promise<void>;
  /** Play MIDI notes melodically (one after another) or harmonically (together). */
  playNotes: (midi: number[], mode?: 'melodic' | 'harmonic', gapSec?: number) => Promise<void>;
  /** Silence whatever this surface started. */
  stop: () => void;
  /** Raw engine handle, for surfaces that need `on('tick')` (RhythmTap). */
  engine: ReturnType<typeof getAudioEngine>;
}

export function useQuizAudio(): QuizAudio {
  const engine = getAudioEngine();
  const nylon = getSharedNylon();
  const isPlaying = ref(false);

  /**
   * Timers from off-transport playback (strums, note sequences). The engine
   * doesn't know about these, so a stop() must cancel them by hand or a
   * component can unmount and still make noise.
   */
  let timers: ReturnType<typeof setTimeout>[] = [];

  function clearTimers(): void {
    timers.forEach(clearTimeout);
    timers = [];
  }

  // Another surface took the engine, or the transport reached the end.
  const unsubEnded = engine.on('ended', () => { isPlaying.value = false; });
  const unsubPlayStarted = engine.on('playStarted', () => { isPlaying.value = false; });

  onBeforeUnmount(() => {
    clearTimers();
    unsubEnded();
    unsubPlayStarted();
  });

  async function playChord(chord: { id: number; diagram_data: unknown }): Promise<void> {
    await engine.init({ samplesBaseUrl: PERC_SAMPLES_URL });
    await nylon.init(NYLON_SAMPLES_URL);
    nylon.releaseAll();

    const events = chordDiagramToEvents(chord as any, {
      startBeat: 0,
      durationBeats: 4,
      staggerBeats: STRUM_STAGGER_BEATS,
    });
    if (!events.length) return;

    isPlaying.value = true;
    clearTimers();

    // Scheduled straight on the sampler rather than the transport, so the
    // strum stagger stays fine-grained (ChordCard does the same).
    const { now } = await import('tone');
    const t0 = now();
    events.forEach((ev: any) => {
      nylon.trigger(ev.pitch, t0 + ev.time * BEAT_SEC, ev.duration * BEAT_SEC, 0.72);
    });

    const tailMs = (events.length - 1) * STRUM_STAGGER_BEATS * BEAT_SEC * 1000 + 2000;
    timers.push(setTimeout(() => { isPlaying.value = false; }, tailMs));
  }

  async function playRhythm(
    pattern: Record<string, any>,
    bpm?: number,
    loop = true,
  ): Promise<void> {
    const tempo = bpm ?? pattern.bpm ?? PREVIEW_BPM;

    await engine.init({ samplesBaseUrl: PERC_SAMPLES_URL, bpm: tempo });

    const events = rhythmPatternToEvents(pattern, { startBeat: 0 });
    if (!events.length) return;

    const stepBeats = ({ eighth: 0.5, sixteenth: 0.25, triplet: 1 / 3 } as const)[
      pattern.gridType as 'eighth' | 'sixteenth' | 'triplet'
    ] ?? 0.25;

    engine.load(events, { loop, loopBeats: pattern.beats * stepBeats });
    engine.setTempo(tempo);

    isPlaying.value = true;
    await engine.play();
  }

  async function playNotes(
    midi: number[],
    mode: 'melodic' | 'harmonic' = 'melodic',
    gapSec = 0.6,
  ): Promise<void> {
    if (!midi.length) return;

    await engine.init({ samplesBaseUrl: PERC_SAMPLES_URL });
    await nylon.init(NYLON_SAMPLES_URL);

    isPlaying.value = true;
    clearTimers();

    if (mode === 'harmonic') {
      // previewNote is immediate and off-transport, so "together" is just
      // "all in the same tick".
      midi.forEach((m) => engine.previewNote(m, Math.max(gapSec, 1.2)));
      timers.push(setTimeout(() => { isPlaying.value = false; }, Math.max(gapSec, 1.2) * 1000));
      return;
    }

    midi.forEach((m, i) => {
      timers.push(setTimeout(() => engine.previewNote(m, gapSec * 1.4), i * gapSec * 1000));
    });
    timers.push(setTimeout(() => { isPlaying.value = false; }, midi.length * gapSec * 1000 + 400));
  }

  function stop(): void {
    clearTimers();
    engine.stop();
    isPlaying.value = false;
  }

  return { isPlaying, playChord, playRhythm, playNotes, stop, engine };
}
