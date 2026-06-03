/**
 * useClock — shared clock for SyncedHero.
 *
 * Provides a plain-setInterval implementation behind a swappable interface.
 * To replace with Tone.js Transport later: implement the same interface
 * (start/stop/onStep/onBar) and swap the factory — no view changes needed.
 */

export type StepType = 'accent' | 'ghost' | 'rest';

export interface ClockOptions {
    bpm: number;
    pattern: StepType[];  // 16-step array
    onStep: (step: number, type: StepType) => void;
    onBar: (barIndex: number) => void;
}

export interface ClockHandle {
    start(): void;
    stop(): void;
}

export function useClock(opts: ClockOptions): ClockHandle {
    const stepMs = (60_000 / opts.bpm) / 4; // 16th notes
    let step = 0;
    let barIndex = 0;
    let timerId: ReturnType<typeof setInterval> | null = null;

    function tick() {
        opts.onStep(step, opts.pattern[step]);
        step = (step + 1) % opts.pattern.length;
        if (step === 0) {
            barIndex++;
            opts.onBar(barIndex);
        }
    }

    return {
        start() {
            if (timerId !== null) return;
            tick(); // fire immediately for first step
            timerId = setInterval(tick, stepMs);
        },
        stop() {
            if (timerId !== null) {
                clearInterval(timerId);
                timerId = null;
            }
        },
    };
}
