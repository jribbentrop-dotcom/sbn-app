/**
 * mountSbnNodes — walks a v-html container, finds <sbn-*> custom elements,
 * fetches their data (once per slug, cached), and mounts a Vue component on
 * each element. Returns an unmount function to tear down all apps.
 *
 * Supported tags:
 *   <sbn-chord       slug="…">
 *   <sbn-rhythm      slug="…">
 *   <sbn-progression slug="…">
 *   <sbn-song        slug="…">
 *   <sbn-youtube     video-id="…" start="…">  ← attrs only, no fetch
 */

import { createApp, type App } from 'vue';

// ── Component registry ──────────────────────────────────────────────────────

const components = {
  chord:       () => import('../Components/Library/ChordCard.vue'),
  rhythm:      () => import('../Components/Library/RhythmCard.vue'),
  progression: () => import('../Components/Library/ChordProgressionViewer.vue'),
  song:        () => import('../Pages/Library/Songs/Show.vue'),
} as const;

type NodeType = keyof typeof components;

// ── API endpoint map ────────────────────────────────────────────────────────

function apiUrl(type: NodeType, slug: string): string {
  const paths: Record<NodeType, string> = {
    chord:       `/api/sbn/chords/${slug}`,
    rhythm:      `/api/sbn/rhythms/${slug}`,
    progression: `/api/sbn/progressions/${slug}`,
    song:        `/api/sbn/songs/${slug}/viewer-data`,
  };
  return paths[type];
}

// ── Prop key per type ───────────────────────────────────────────────────────
// Each component accepts a single top-level prop wrapping its data object.

function propKey(type: NodeType): string {
  const keys: Record<NodeType, string> = {
    chord:       'chord',
    rhythm:      'pattern',
    progression: 'progression',
    song:        'song',
  };
  return keys[type];
}

// ── Fetch cache ─────────────────────────────────────────────────────────────

const cache = new Map<string, Promise<unknown>>();

function fetchData(type: NodeType, slug: string): Promise<unknown> {
  const key = `${type}:${slug}`;
  if (!cache.has(key)) {
    cache.set(
      key,
      fetch(apiUrl(type, slug), { headers: { Accept: 'application/json' } })
        .then((r) => {
          if (!r.ok) throw new Error(`SBN node fetch failed: ${r.status} ${apiUrl(type, slug)}`);
          return r.json();
        }),
    );
  }
  return cache.get(key)!;
}

// ── Mount ───────────────────────────────────────────────────────────────────

export async function mountSbnNodes(container: HTMLElement): Promise<() => void> {
  const apps: App[] = [];
  const tasks: Promise<void>[] = [];

  // ── <sbn-youtube> — attrs only, no fetch ──────────────────────────────────
  container.querySelectorAll<HTMLElement>('sbn-youtube').forEach((el) => {
    const videoId = el.getAttribute('video-id') ?? el.getAttribute('videoid') ?? '';
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

  // ── <sbn-chord|rhythm|progression|song> — fetch then mount ─────────────────
  for (const type of Object.keys(components) as NodeType[]) {
    container.querySelectorAll<HTMLElement>(`sbn-${type}`).forEach((el) => {
      const slug = el.getAttribute('slug') ?? '';
      if (!slug) return;

      const task = fetchData(type, slug)
        .then(async (data) => {
          const mod = await components[type]();
          const Component = (mod as any).default ?? mod;

          const app = createApp(Component, { [propKey(type)]: data });
          app.mount(el);
          apps.push(app);
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
