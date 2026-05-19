/**
 * mountSbnNodes — walks a v-html container, finds <sbn-*> custom elements,
 * fetches their data (once per slug, cached), and mounts a Vue component on
 * each element. Returns an unmount function to tear down all apps.
 *
 * Supported tags:
 *   <sbn-chord       slug="…">
 *   <sbn-rhythm      slug="…">
 *   <sbn-progression slug="…">
 *   <sbn-sheet       slug="…" key="C">
 *   <sbn-song        slug="…">
 *   <sbn-youtube     id="…" start="…">        ← attrs only, no fetch
 *   <sbn-widget      slug="…" …attrs>        ← edu interactive, no fetch
 */

import { createApp, h, type App } from 'vue';
import { getCategoryColor } from '../composables/useCategoryColors';
import { eduWidgets, isEduWidget } from '../edu/widgets/registry';
import { getVideoPlayhead } from '../composables/useVideoPlayhead';

/**
 * Snippet sync info passed in from the course player. Keyed by snippet id, it
 * lets an inline <sbn-progression video-snippet="…"> reach the shared playhead
 * that PracticePanel's <VideoEmbed> drives. See useVideoPlayhead's registry.
 */
export interface SnippetSyncInfo {
  startSec: number;
  tempoBpm: number;
}

// ── Component registry ──────────────────────────────────────────────────────

// Components mounted with fetched data. <sbn-song> is handled separately
// (renders as a styled link, no fetch, no component).
const components = {
  chord:       () => import('../Components/Library/ChordCard.vue'),
  rhythm:      () => import('../Components/Library/RhythmStrip.vue'),
  progression: () => import('../Components/Library/ChordProgressionViewer.vue'),
  sheet:       () => import('../Components/Course/SheetMiniPlayer.vue'),
} as const;

type NodeType = keyof typeof components;

// ── API endpoint map ────────────────────────────────────────────────────────
// Query string is built from the element's attrs so e.g. <sbn-chord root="F">
// produces /api/sbn/chords/{slug}?root=F.

function apiUrl(type: NodeType, slug: string, qs: string): string {
  const paths: Record<NodeType, string> = {
    chord:       `/api/sbn/chords/${slug}`,
    rhythm:      `/api/sbn/rhythms/${slug}`,
    progression: `/api/sbn/progressions/${slug}`,
    sheet:       `/api/sbn/exercises/${slug}`,
  };
  return qs ? `${paths[type]}?${qs}` : paths[type];
}

// ── Per-type props adapter ──────────────────────────────────────────────────
// Each component has its own prop convention; the registry maps API payload
// → the exact prop bag that component expects. Add new types here.

const propsFor: Record<NodeType, (data: any, el: HTMLElement) => Record<string, any>> = {
  chord:       (d, el) => ({
    chord: d,
    showRoot: true,
    mini: true,
    onChordClick: (el as any).__onChordSelect
      ? () => (el as any).__onChordSelect(d.slug, d.root_note ?? 'C')
      : undefined,
  }),
  rhythm:      (d) => ({ pattern: d, color: getCategoryColor(d.styleSlug) }),
  progression: (d) => ({ chords: d.chords ?? [] }),
  sheet:       (d, el) => ({ exercise: d, onChordSelect: (el as any).__onChordSelect ?? null }),
};

// ── Per-type query string from element attrs ────────────────────────────────

function queryStringFor(type: NodeType, el: HTMLElement): string {
  const params = new URLSearchParams();
  if (type === 'chord') {
    const root = el.getAttribute('root');
    if (root) params.set('root', root);
  } else if (type === 'progression') {
    const key = el.getAttribute('key');
    if (key) params.set('key', key);
  } else if (type === 'sheet') {
    const key = el.getAttribute('key');
    if (key) params.set('key', key);
  }
  return params.toString();
}

// ── Edu widget props ────────────────────────────────────────────────────────
// Every attribute except `slug` becomes a prop. Values are JSON-decoded when
// they parse as JSON (numbers, booleans, arrays, objects), otherwise kept as
// the raw string — so `highlight="C"` stays "C" while `start="4"` becomes 4.

function widgetPropsFromAttrs(el: HTMLElement): Record<string, unknown> {
  const props: Record<string, unknown> = {};
  for (const attr of Array.from(el.attributes)) {
    if (attr.name === 'slug') continue;
    try {
      props[attr.name] = JSON.parse(attr.value);
    } catch {
      props[attr.name] = attr.value;
    }
  }
  return props;
}

// ── Fetch cache ─────────────────────────────────────────────────────────────

const cache = new Map<string, Promise<unknown>>();

function fetchData(type: NodeType, slug: string, qs: string): Promise<unknown> {
  const url = apiUrl(type, slug, qs);
  const key = `${type}:${url}`;
  if (!cache.has(key)) {
    cache.set(
      key,
      fetch(url, { headers: { Accept: 'application/json' } })
        .then((r) => {
          if (!r.ok) throw new Error(`SBN node fetch failed: ${r.status} ${url}`);
          return r.json();
        }),
    );
  }
  return cache.get(key)!;
}

// ── Mount ───────────────────────────────────────────────────────────────────

export async function mountSbnNodes(
  container: HTMLElement,
  options: {
    onChordSelect?: ((slug: string, root: string, voicingData?: any) => void) | null;
    /** snippet id → sync anchor, for inline <sbn-progression> video sync. */
    snippetSync?: Record<string, SnippetSyncInfo> | null;
  } = {},
): Promise<() => void> {
  const apps: App[] = [];
  const tasks: Promise<void>[] = [];

  // ── <sbn-youtube> — attrs only, no fetch ──────────────────────────────────
  container.querySelectorAll<HTMLElement>('sbn-youtube').forEach((el) => {
    const videoId = el.getAttribute('id') ?? el.getAttribute('video-id') ?? el.getAttribute('videoid') ?? '';
    const start   = Number(el.getAttribute('start') ?? '0');
    if (!videoId) return;

    // Inline minimal iframe embed — no separate component needed
    const YoutubeEmbed = {
      props: { videoId: String, start: Number },
      template: `<div class="sbn-youtube-embed">
        <iframe
          :src="\`https://www.youtube-nocookie.com/embed/\${videoId}?start=\${start}\`"
          width="560" height="315"
          frameborder="0"
          allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture"
          allowfullscreen
        />
      </div>`,
    };

    const app = createApp(YoutubeEmbed, { videoId, start });
    app.mount(el);
    apps.push(app);
  });

  // ── <sbn-song> — render as styled link to leadsheet viewer (no fetch) ─────
  container.querySelectorAll<HTMLElement>('sbn-song').forEach((el) => {
    const slug = el.getAttribute('slug') ?? '';
    if (!slug) return;
    const label = el.getAttribute('label') ?? slug;
    el.innerHTML = `<a class="sbn-song-link" href="/library/songs/${slug}/viewer">${label} ↗</a>`;
  });

  // ── <sbn-widget> — edu interactive from the widget registry, no fetch ─────
  // Unknown slug renders a visible placeholder and warns; it must never blank
  // a page. Any attribute other than `slug` is passed to the widget as a prop,
  // JSON-decoded when it parses as JSON (so `highlight="C"` → string "C",
  // `count="3"` → number 3, `flags="[1,2]"` → array).
  container.querySelectorAll<HTMLElement>('sbn-widget').forEach((el) => {
    const slug = el.getAttribute('slug') ?? '';

    if (!isEduWidget(slug)) {
      console.warn(`[mountSbnNodes] Unknown edu widget slug="${slug}"`);
      el.innerHTML = `<span class="sbn-node-error">Unknown widget: ${slug || '(no slug)'}</span>`;
      return;
    }

    const props = widgetPropsFromAttrs(el);
    const task = eduWidgets[slug]()
      .then((mod: any) => {
        const Component = mod.default ?? mod;
        el.classList.add('sbn-widget-embed');
        const app = createApp(Component, props);
        app.mount(el);
        apps.push(app);
      })
      .catch((err) => {
        console.warn(`[mountSbnNodes] Failed to mount <sbn-widget slug="${slug}">:`, err);
        el.innerHTML = `<span class="sbn-node-error">widget: ${slug}</span>`;
      });

    tasks.push(task);
  });

  // ── <sbn-chord|rhythm|progression|sheet> — fetch then mount ───────────────
  for (const type of Object.keys(components) as NodeType[]) {
    container.querySelectorAll<HTMLElement>(`sbn-${type}`).forEach((el) => {
      const slug = el.getAttribute('slug') ?? '';
      if (!slug) return;
      if (options.onChordSelect) {
        (el as any).__onChordSelect = options.onChordSelect;
      }

      const qs = queryStringFor(type, el);
      const task = fetchData(type, slug, qs)
        .then(async (data: any) => {
          const mod = await components[type]();
          const Component = (mod as any).default ?? mod;

          if (type === 'rhythm') {
            // Special polished wrapper for rhythm embeds
            el.classList.add('sbn-rhythm-embed-wrap');
            el.innerHTML = `
              <div class="sbn-pattern-row sbn-pattern-row--${data.styleSlug}" style="cursor: default;">
                <div class="sbn-pattern-row-head">
                  <span class="sbn-pattern-row-name">${data.name}</span>
                  <div class="sbn-pattern-row-badges">
                    <span class="sbn-badge sbn-badge-muted">${data.timeSignature}</span>
                    <span class="sbn-badge sbn-badge-muted">${data.bpm} BPM</span>
                  </div>
                </div>
                <div class="sbn-rhythm-mount-point"></div>
              </div>
            `;
            const mountPoint = el.querySelector('.sbn-rhythm-mount-point');
            if (mountPoint) {
              const app = createApp(Component, propsFor[type](data, el));
              app.mount(mountPoint);
              apps.push(app);
            }
          } else if (type === 'progression') {
            // Inline <sbn-progression> is the synced surface in the course
            // player: the body component shows the highlight, PracticePanel's
            // <VideoEmbed> owns the clock. When the tag carries a
            // video-snippet whose id is in the snippetSync map, mount via a
            // render function so the shared playhead stays reactive (root
            // props passed to createApp are static otherwise).
            const baseProps = propsFor[type](data, el);
            const snippetId = el.getAttribute('video-snippet') ?? '';
            const sync = snippetId ? options.snippetSync?.[snippetId] : undefined;

            if (sync) {
              const ph = getVideoPlayhead(snippetId);
              const app = createApp({
                render: () => h(Component, {
                  ...baseProps,
                  videoPlayhead: ph.playing.value ? ph.playheadSec.value : null,
                  videoStartSec: sync.startSec,
                  tempoBpm: sync.tempoBpm,
                }),
              });
              app.mount(el);
              apps.push(app);
            } else {
              const app = createApp(Component, baseProps);
              app.mount(el);
              apps.push(app);
            }
          } else {
            if (type === 'chord') {
              el.classList.add('sbn-chord-embed');
            }
            const app = createApp(Component, propsFor[type](data, el));
            app.mount(el);
            apps.push(app);
          }
        })
        .catch((err) => {
          console.warn(`[mountSbnNodes] Failed to mount <sbn-${type} slug="${slug}">:`, err);
          el.innerHTML = `<span class="sbn-node-error">${type}: ${slug}</span>`;
        });

      tasks.push(task);
    });
  }

  await Promise.all(tasks);

  return () => {
    apps.forEach((app) => app.unmount());
    apps.length = 0;
  };
}
