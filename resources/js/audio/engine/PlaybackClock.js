/**
 * @typedef {import('../types.js').Beats} Beats
 * @typedef {import('../types.js').Unsubscribe} Unsubscribe
 *
 * PlaybackClock — the abstraction that lets us swap between
 * audio-driven time (ToneClock) and video-driven time (MediaElementClock).
 * The scheduler asks the clock "what beat are we at?" — nothing more.
 *
 * @typedef {Object} PlaybackClock
 * @property {() => number}       now           Audio-context time in seconds.
 * @property {() => Beats}        currentBeat   Musical position.
 * @property {() => Promise<void>} start
 * @property {() => void}         pause
 * @property {() => void}         stop
 * @property {(beat:Beats) => void} seek
 * @property {(bpm:number)  => void} setTempo
 * @property {(cb:(beat:Beats)=>void) => Unsubscribe} onTick
 * @property {boolean}            isExternal    True if an external element (video) owns time.
 */
export {};
