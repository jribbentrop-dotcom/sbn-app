/**
 * Stub. Future: wraps an HTMLMediaElement so <video>.currentTime drives the timeline.
 * Forces the interface to stay honest during Phase 7C — if this can't be written
 * without changing AudioEngine or Scheduler, the interface is wrong.
 * @see docs/audio-engine-contract.md §5
 */
export class MediaElementClock {
    constructor(/* mediaElement, bpm, tempoMap */) {
        throw new Error('MediaElementClock not implemented until video phase.');
    }
}
