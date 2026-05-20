/**
 * Edu widget registry — the single source of truth for interactive
 * illustrations embeddable in edu topic bodies via <sbn-widget slug="…">.
 *
 * Each entry is a lazy import thunk: `() => import('./Widget.vue')`. The
 * mounter (mountSbnNodes' sbn-widget branch) awaits the thunk, then
 * `createApp(mod.default)`. This mirrors how mountSbnNodes already loads its
 * fetch-backed components — one mental model for the whole file.
 *
 * (The Edu Content System plan §4.1 originally sketched defineAsyncComponent
 * here; raw thunks were chosen instead for consistency with mountSbnNodes,
 * which never resolves components through Vue's template layer.)
 *
 * Adding an interactive = one Vue component + one line here.
 */

export const eduWidgets = {
  'triad-builder':    () => import('./TriadBuilder.vue'),
  'circle-of-fifths': () => import('./CircleOfFifths.vue'),
  'drop2-visualizer': () => import('./Drop2Visualizer.vue'),
  'voice-leading':    () => import('./VoiceLeading.vue'),
} as const;

export type EduWidgetSlug = keyof typeof eduWidgets;

/** True if `slug` names a registered widget. Narrows to EduWidgetSlug. */
export function isEduWidget(slug: string): slug is EduWidgetSlug {
  return Object.prototype.hasOwnProperty.call(eduWidgets, slug);
}
