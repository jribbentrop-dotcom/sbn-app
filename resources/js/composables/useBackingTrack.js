import { ref } from 'vue';
import * as Tone from 'tone';

/**
 * Cinema's with/without-guitar backing-track toggle.
 *
 * Two pre-recorded audio files of identical length to the Cinema video —
 * `backingUrl` (no guitar) and `guitarUrl` (with guitar) — are decoded into
 * one AudioContext and played through two gain buses. Both buffers are
 * started together and re-seeked together on every video seek, so they stay
 * sample-locked to each other and to the video's own timeline. The toggle
 * just ramps gain between the two buses — no re-seeking, no buffering gap.
 *
 * The video itself stays the sync master (muted) exactly as before; this
 * composable only mirrors its play/pause/seek calls.
 */
export function useBackingTrack() {
  const ready = ref(false);
  const loading = ref(false);
  const guitarOn = ref(true);
  const error = ref('');
  const playing = ref(false); // true once buffers have actually been start()-ed

  let ctx = null;
  let backingBuffer = null;
  let guitarBuffer = null;
  let backingBus = null;
  let guitarBus = null;
  let backingSource = null;
  let guitarSource = null;
  let startedAtCtxTime = 0; // ctx.currentTime when playback last started
  let startedAtOffset = 0;  // video-seconds offset corresponding to that start
  let playToken = 0;        // guards against overlapping play() calls (see play())
  // ctx.currentTime at which the *most recent* play() was invoked — set
  // synchronously, before play()'s await. resyncTo uses this (not
  // startedAtCtxTime, which only updates once play() finishes its await) to
  // decide whether a re-seek is already in flight, so the ~60fps rAF poll
  // can't fire a fresh re-seek on every frame during that async gap. Without
  // it, a single drift event spawns dozens of stacked play() calls before the
  // first one completes — the "audio race we never get out of".
  let lastPlayInvokedAtCtxTime = -Infinity;

  const RAMP_SEC = 0.08;
  // Video timeupdate fires ~every 250ms (YT) and drift accumulates from
  // buffering stalls / re-cueing; re-seek once it exceeds this threshold
  // rather than on every tick (which would re-trigger constantly on jitter).
  const RESYNC_THRESHOLD_SEC = 0.15;
  // Grace period after a fresh play()/re-seek before resyncTo starts checking
  // drift again. Without this, the very first timeupdate tick after start()
  // sees a false "drift" (the video's timeupdate isn't synced to the instant
  // our buffers actually started) and immediately re-seeks — which resets the
  // audio clock to 0 again, so the *next* tick sees the same false drift and
  // re-seeks again: a self-sustaining restart loop that sounds like stacked/
  // stuttering audio even though only one buffer pair is ever playing.
  const RESYNC_GRACE_SEC = 0.5;

  /** Elapsed shared-timeline time since the buffers were last (re)started. */
  function currentPlaybackTime() {
    if (!ctx || !backingSource) return startedAtOffset;
    return startedAtOffset + (ctx.currentTime - startedAtCtxTime);
  }

  async function load(backingUrl, guitarUrl) {
    ready.value = false;
    error.value = '';
    if (!backingUrl || !guitarUrl) return;
    // Defensive: a second load() without an intervening destroy() would
    // otherwise leave the previous buses connected to ctx.destination,
    // doubling up playback once the new buffers start.
    if (backingBus || guitarBus) destroy();
    loading.value = true;
    try {
      ctx = Tone.getContext().rawContext;
      backingBus = ctx.createGain();
      guitarBus = ctx.createGain();
      backingBus.connect(ctx.destination);
      guitarBus.connect(ctx.destination);
      // The two files are ALTERNATE full mixes of the same performance —
      // `backing` = without guitar, `guitar` = with guitar — NOT a stem layered
      // on top of the other. So exactly one bus is audible at a time: the toggle
      // crossfades between them (see setGuitarOn), it never sums them. Summing
      // both played the performance twice, ~half a beat out of phase (the files
      // aren't sample-aligned to each other), which smeared/flammed the audio.
      backingBus.gain.value = guitarOn.value ? 0 : 1;
      guitarBus.gain.value  = guitarOn.value ? 1 : 0;

      const [bBuf, gBuf] = await Promise.all([
        fetchAndDecode(backingUrl),
        fetchAndDecode(guitarUrl),
      ]);
      backingBuffer = bBuf;
      guitarBuffer = gBuf;
      ready.value = true;
    } catch (e) {
      error.value = 'Could not load backing track audio.';
      console.warn('[useBackingTrack] load failed', e);
    } finally {
      loading.value = false;
    }
  }

  async function fetchAndDecode(url) {
    const resp = await fetch(url);
    if (!resp.ok) throw new Error('HTTP ' + resp.status);
    const ab = await resp.arrayBuffer();
    return ctx.decodeAudioData(ab);
  }

  function _stopSources() {
    for (const src of [backingSource, guitarSource]) {
      if (!src) continue;
      try { src.stop(); } catch (_) { /* already stopped */ }
      try { src.disconnect(); } catch (_) {}
    }
    backingSource = null;
    guitarSource = null;
  }

  /**
   * Start both buffers together, aligned to `atSeconds` of the shared timeline.
   *
   * `atSeconds` may be a number (used as-is) or a function returning the
   * current number — pass a function whenever the context might need to
   * resume() first (real async delay: tens to hundreds of ms), so the offset
   * used is the video's position at the moment playback actually starts, not
   * whatever it was when play() was first called. Using a stale number here
   * is exactly what caused the backing track to start behind the video by
   * however long the resume() took.
   */
  async function play(atSeconds = 0) {
    if (!ready.value) return;
    // Overlapping play() calls race: resyncTo() on a timeupdate tick can fire
    // again while an earlier play()'s ctx.resume() await is still pending.
    // Both calls would otherwise pass the checks above, and — because
    // _stopSources() only stops sources already assigned to backingSource/
    // guitarSource, not ones a concurrent call is *about to* create — both
    // would create and start their own pair of buffers, stacking audio.
    // Claiming a token before the only await, and bailing if a newer call
    // has claimed it by the time we resume, serializes these instead.
    const myToken = ++playToken;
    // Record the invocation time synchronously (before the await), so resyncTo
    // treats a re-seek as in flight the instant play() is called — not only
    // once it finishes and updates startedAtCtxTime. This is what stops the
    // rAF poll from stacking a fresh play() every ~16ms during the async gap.
    lastPlayInvokedAtCtxTime = ctx.currentTime;
    // The raw AudioContext can start (or fall back to) 'suspended' under the
    // browser's autoplay policy since this bypasses Tone's own start() gate —
    // without this, start() calls are silently queued and nothing audible
    // happens until an unrelated later gesture happens to resume the context.
    if (ctx.state === 'suspended') {
      try { await ctx.resume(); } catch (_) { /* ignore */ }
    }
    if (myToken !== playToken) {
      return; // a newer play() superseded this one
    }

    _stopSources();

    backingSource = ctx.createBufferSource();
    backingSource.buffer = backingBuffer;
    backingSource.connect(backingBus);

    guitarSource = ctx.createBufferSource();
    guitarSource.buffer = guitarBuffer;
    guitarSource.connect(guitarBus);

    const liveSeconds = typeof atSeconds === 'function' ? atSeconds() : atSeconds;
    const offset = Math.max(0, Math.min(liveSeconds, backingBuffer.duration));
    backingSource.start(0, offset);
    guitarSource.start(0, offset);

    startedAtCtxTime = ctx.currentTime;
    startedAtOffset = offset;
    playing.value = true;
  }

  function pause() {
    playToken++; // invalidate any in-flight play() awaiting ctx.resume()
    playing.value = false;
    _stopSources();
  }

  /** Re-seek both buffers together — same call used for pause+resume-at-position. */
  function seekTo(atSeconds) {
    if (!ready.value) return;
    const wasPlaying = !!backingSource;
    if (wasPlaying) play(atSeconds);
    else { startedAtOffset = atSeconds; startedAtCtxTime = ctx?.currentTime ?? 0; }
  }

  /**
   * Called on every video timeupdate while both are playing. The buffers run
   * on their own AudioContext clock once started — buffering stalls, YouTube
   * re-cueing, or a play() that fires before the video is truly rolling all
   * cause the two clocks to drift apart with nothing to pull them back. Hard
   * re-seek (via play()) once drift exceeds the threshold; small jitter under
   * that is left alone so a normal timeupdate tick doesn't cause audible
   * re-triggering.
   */
  let lastResyncVideoSeconds = -1;
  function resyncTo(videoSeconds) {
    if (!ready.value || !backingSource) return;
    // Frozen video clock = the player is buffering/stalled (the rAF poll keeps
    // firing but getCurrentTime() returns the same value). Our audio ctx clock
    // keeps advancing through that stall, so drift looks large — but re-seeking
    // to a frozen position is pointless churn (it'll be stale the instant the
    // video resumes and jumps forward). Skip until the video is actually moving
    // again; the post-buffer tick then does a single clean re-seek.
    if (videoSeconds === lastResyncVideoSeconds) return;
    lastResyncVideoSeconds = videoSeconds;
    // Suppress re-checks during the grace window after a (re)start. Key it on
    // whichever is later: the last completed start (startedAtCtxTime) or the
    // last *invoked* play() (lastPlayInvokedAtCtxTime, set synchronously). The
    // latter covers the async gap while a re-seek's ctx.resume()/await is still
    // pending and startedAtCtxTime hasn't updated yet — without it the ~60fps
    // poll re-fires play() every frame across that gap.
    const lastStart = Math.max(startedAtCtxTime, lastPlayInvokedAtCtxTime);
    if (ctx.currentTime - lastStart < RESYNC_GRACE_SEC) return;
    const drift = videoSeconds - currentPlaybackTime();
    if (Math.abs(drift) > RESYNC_THRESHOLD_SEC) {
      play(videoSeconds);
    }
  }

  /**
   * Toggle "with guitar" on/off. The two files are alternate full mixes, so
   * this crossfades between them — guitar bus up + backing bus down (or the
   * reverse) — keeping exactly one audible. A short ramp on each avoids a
   * click. (Both buffers keep running; only their gains move, so the switch is
   * gapless and stays sample-locked to the video.)
   */
  function setGuitarOn(on) {
    guitarOn.value = on;
    if (!guitarBus || !backingBus) return;
    const now = ctx.currentTime;
    for (const [bus, target] of [[guitarBus, on ? 1 : 0], [backingBus, on ? 0 : 1]]) {
      bus.gain.cancelScheduledValues(now);
      bus.gain.setValueAtTime(bus.gain.value, now);
      bus.gain.linearRampToValueAtTime(target, now + RAMP_SEC);
    }
  }

  function toggleGuitar() {
    setGuitarOn(!guitarOn.value);
  }

  function destroy() {
    playing.value = false;
    _stopSources();
    backingBus?.disconnect();
    guitarBus?.disconnect();
    backingBus = null;
    guitarBus = null;
    backingBuffer = null;
    guitarBuffer = null;
    ready.value = false;
  }

  return {
    ready,
    loading,
    error,
    guitarOn,
    playing,
    load,
    play,
    pause,
    seekTo,
    resyncTo,
    setGuitarOn,
    toggleGuitar,
    destroy,
  };
}
