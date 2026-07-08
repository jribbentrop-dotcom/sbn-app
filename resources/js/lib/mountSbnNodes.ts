/**
 * mountSbnNodes — walks a v-html container, finds <sbn-*> custom elements,
 * fetches their data (once per slug, cached), and mounts a Vue component on
 * each element. Returns an unmount function to tear down all apps.
 *
 * Supported tags:
 *   <sbn-chord          slug="…">
 *   <sbn-rhythm         slug="…">
 *   <sbn-progression    slug="…">
 *   <sbn-sheet          slug="…" key="C">
 *   <sbn-song           slug="…" bars="5-8">    ← leadsheet excerpt → SheetMiniPlayer
 *   <sbn-youtube        id="…" start="…">          ← attrs only, no fetch
 *   <sbn-widget         slug="…" …attrs>           ← edu interactive, no fetch
 *   <sbn-fretboard      slug="…" position="3">      ← vanilla JS hydration via chords.js
 *                                                     position overrides stored start_window (1-indexed)
 *   <sbn-synced-player  slug="…" type="leadsheet"  ← chord+rhythm player, fetch from API
 *                        start="0" end="7"
 *                        autoplay="false">
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
  /** Key the musician plays in (e.g. "Bb") — overrides the tag's key attr. */
  key?: string;
  /** Pinned chord slugs, one per numeral slot — bypasses the builder. */
  chords?: string[];
}

// ── Component registry ──────────────────────────────────────────────────────

// Components mounted with fetched data. <sbn-song> is handled separately
// (renders as a styled link, no fetch, no component).
const components = {
  chord:       () => import('../Components/Library/ChordCard.vue'),
  rhythm:      () => import('../Components/Library/RhythmStrip.vue'),
  progression: () => import('../Components/Library/ChordProgressionViewer.vue'),
  sheet:       () => import('../Components/Course/SheetMiniPlayer.vue'),
  fretboard:   () => import('../Components/Library/fretboard/SbnFretboard.vue'),
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
    fretboard:   `/api/sbn/fretboards/${slug}`,
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
  // Pass the full prop set so the inline course-player progression looks
  // identical to the library detail page (Pages/Library/Progressions/Show).
  // apiShow returns { progression, key, chords } — `d.progression` carries
  // name/category/numeralsDisplay/styleSlug.
  progression: (d) => ({
    chords:       d.chords ?? [],
    name:         d.progression?.name ?? '',
    category:     d.progression?.category ?? '',
    numerals:     d.progression?.numeralsDisplay ?? d.progression?.numerals ?? '',
    keyLabel:     d.key ?? '',
    color:        d.progression?.styleSlug ? getCategoryColor(d.progression.styleSlug) : null,
    interactive:  true,
  }),
  sheet:       (d, el) => ({ exercise: d, onChordSelect: (el as any).__onChordSelect ?? null, videoSync: d.videoSync ?? null }),
  // `position="3"` (1-indexed, matches the admin's window labels) overrides
  // the record's stored start_window per-embed, so one positions-mode
  // fretboard (e.g. all 5 pentatonic boxes) can open on a different window
  // in each lesson it's embedded in. Data is fetched once per slug and
  // cached, so we clone rather than mutate the shared payload.
  fretboard:   (d, el) => {
    const position = el.getAttribute('position');
    if (position === null) return { data: d };
    const idx = Number(position) - 1;
    if (!Number.isInteger(idx) || idx < 0) return { data: d };
    return { data: { ...d, start_window: idx } };
  },
};

// ── Per-type query string from element attrs ────────────────────────────────

function queryStringFor(type: NodeType, el: HTMLElement, sync?: SnippetSyncInfo): string {
  const params = new URLSearchParams();
  if (type === 'chord') {
    const root = el.getAttribute('root');
    if (root) params.set('root', root);
  } else if (type === 'progression') {
    // Pinned snippet key overrides the tag's key attr; falls back to tag attr.
    const key = sync?.key ?? el.getAttribute('key');
    if (key) params.set('key', key);
    if (sync?.chords?.length) params.set('chords', sync.chords.join(','));
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
    /** Called when a sheet/progression play button is pressed so the practice panel auto-expands. */
    onExpandPractice?: (() => void) | null;
    /** Called when a sheet with video starts playing so the video panel auto-opens. */
    onExpandVideo?: (() => void) | null;
  } = {},
): Promise<() => void> {
  const apps: App[] = [];
  const tasks: Promise<void>[] = [];

  // ── <sbn-info> — animated practice focus card, attrs only, no fetch ─────────
  container.querySelectorAll<HTMLElement>('sbn-info').forEach((el) => {
    const heading = el.getAttribute('heading') ?? 'Practice focus';
    const items = (el.getAttribute('items') ?? '')
      .split('|').map(s => s.trim()).filter(Boolean);

    const card = document.createElement('div');
    card.className = 'sbn-info-box';

    const inner = document.createElement('div');
    inner.className = 'sbn-info-box-inner';

    const h = document.createElement('div');
    h.className = 'sbn-info-box-heading';
    h.textContent = heading;
    inner.appendChild(h);

    if (items.length) {
      const ul = document.createElement('ul');
      ul.className = 'sbn-info-box-list';
      items.forEach(item => {
        const li = document.createElement('li');
        li.className = 'sbn-info-box-item';
        li.innerHTML = `<span class="sbn-info-box-check">
          <svg width="13" height="13" viewBox="0 0 13 13" fill="none">
            <path d="M2.5 6.5l3 3 5-5" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/>
          </svg>
        </span><span>${item}</span>`;
        ul.appendChild(li);
      });
      inner.appendChild(ul);
    }

    card.appendChild(inner);
    el.replaceWith(card);
  });

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

  // ── <sbn-song> — mounts SheetMiniPlayer; bars="5-8" for excerpt, omit for full song ──
  container.querySelectorAll<HTMLElement>('sbn-song').forEach((el) => {
    const slug = el.getAttribute('slug') ?? '';
    if (!slug) return;

    const bars  = el.getAttribute('bars')  ?? '';
    const layer = el.getAttribute('layer') ?? '';
    if (options.onChordSelect) {
      (el as any).__onChordSelect = options.onChordSelect;
    }
    const params = new URLSearchParams();
    if (bars)              params.set('bars',  bars);
    if (layer === 'chord') params.set('layer', 'chord');
    const qs  = params.toString();
    const url = `/api/sbn/songs/${slug}/sheet${qs ? '?' + qs : ''}`;
    const task = fetch(url, { headers: { Accept: 'application/json' } })
      .then((r) => {
        if (!r.ok) throw new Error(`song-sheet fetch failed: ${r.status}`);
        return r.json();
      })
      .then(async (data: any) => {
        const mod = await import('../Components/Course/SheetMiniPlayer.vue');
        const Component = (mod as any).default ?? mod;
        const videoSync = data.videoSync ?? null;

        if (videoSync?.videoId) {
          const phKey = `sheet:song:${slug}`;
          const ph = getVideoPlayhead(phKey);
          const offset = (videoSync.videoTimeOffset as number) ?? 0;
          const app = createApp({
            render: () => h(Component, {
              ...propsFor.sheet(data, el),
              videoPlayhead: ph.playing.value ? ph.playheadSec.value - offset : null,
              videoSync,
              onVideoPlay: () => {
                const doPlay = () => {
                  const offset = (videoSync.videoTimeOffset as number) ?? 0;
                  const mappings: Array<{ videoTime: number }> = videoSync.mappings ?? [];
                  if (mappings.length) {
                    const firstRel = mappings.reduce((a: any, b: any) => a.videoTime < b.videoTime ? a : b).videoTime;
                    const lastRel  = mappings.reduce((a: any, b: any) => a.videoTime > b.videoTime ? a : b).videoTime;
                    const cur = ph.playheadSec.value;
                    const firstAbs = firstRel + offset;
                    const lastAbs  = lastRel  + offset;
                    if (cur < firstAbs || cur > lastAbs) ph.seek(firstAbs);
                  }
                  ph.play();
                };
                if (!ph.embedRef.value) {
                  options.onExpandPractice?.();
                  options.onExpandVideo?.();
                  setTimeout(doPlay, 300);
                } else {
                  options.onExpandVideo?.();
                  doPlay();
                }
              },
              onVideoPause: () => ph.pause(),
              onVideoSeek: (seconds: number) => { ph.seek(seconds + ((videoSync.videoTimeOffset as number) ?? 0)); ph.play(); },
            }),
          });
          app.mount(el);
          apps.push(app);
        } else {
          const app = createApp(Component, propsFor.sheet(data, el));
          app.mount(el);
          apps.push(app);
        }
      })
      .catch((err) => {
        console.warn(`[mountSbnNodes] Failed to mount <sbn-song slug="${slug}">:`, err);
        el.innerHTML = `<span class="sbn-node-error">song: ${slug}${bars ? ` bars ${bars}` : ''}</span>`;
      });

    tasks.push(task);
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

  // ── <sbn-chord|rhythm|progression|sheet|fretboard> — fetch then mount ───────────────
  for (const type of Object.keys(components) as NodeType[]) {
    container.querySelectorAll<HTMLElement>(`sbn-${type}`).forEach((el) => {
      const slug = el.getAttribute('slug') ?? '';
      if (!slug) return;
      if (options.onChordSelect) {
        (el as any).__onChordSelect = options.onChordSelect;
      }

      const snippetIdForQs = type === 'progression' ? (el.getAttribute('video-snippet') ?? '') : '';
      const syncForQs = snippetIdForQs ? options.snippetSync?.[snippetIdForQs] : undefined;
      const qs = queryStringFor(type, el, syncForQs);
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
                  // The viewer's play button drives the shared video clock
                  // instead of synth audio when a snippet is attached. Both
                  // callbacks resolve to the SAME registry playhead the
                  // PracticePanel <VideoEmbed> owns — so this plays the video.
                  // play() seeks to the snippet anchor before starting.
                  onVideoPlay: () => {
                    if (ph.playheadSec.value < sync.startSec) ph.seek(sync.startSec);
                    ph.play();
                  },
                  onVideoPause: () => ph.pause(),
                }),
              });
              app.mount(el);
              apps.push(app);
            } else {
              const app = createApp(Component, baseProps);
              app.mount(el);
              apps.push(app);
            }
          } else if (type === 'sheet') {
            const baseProps = propsFor[type](data, el);
            const videoSync = (data as any).videoSync ?? null;

            if (videoSync?.videoId) {
              const slug = el.getAttribute('slug') ?? '';
              const ph = getVideoPlayhead(`sheet:${slug}`);
              const offset = (videoSync.videoTimeOffset as number) ?? 0;
              const app = createApp({
                render: () => h(Component, {
                  ...baseProps,
                  videoPlayhead: ph.playing.value ? ph.playheadSec.value - offset : null,
                  videoSync,
                  // Play button drives the shared video clock. Seek to the start
                  // of the exercise/excerpt in the recording if the playhead is
                  // outside the mapped range. videoTimeOffset shifts re-based
                  // (0-relative) mapping times back to real video positions.
                  onVideoPlay: () => {
                    const doPlay = () => {
                      const offset = (videoSync.videoTimeOffset as number) ?? 0;
                      const mappings: Array<{ videoTime: number }> = videoSync.mappings ?? [];
                      if (mappings.length) {
                        const firstRel = mappings.reduce((a: any, b: any) => a.videoTime < b.videoTime ? a : b).videoTime;
                        const lastRel  = mappings.reduce((a: any, b: any) => a.videoTime > b.videoTime ? a : b).videoTime;
                        const cur = ph.playheadSec.value;
                        const firstAbs = firstRel + offset;
                        const lastAbs  = lastRel  + offset;
                        if (cur < firstAbs || cur > lastAbs) ph.seek(firstAbs);
                      }
                      ph.play();
                    };
                    if (!ph.embedRef.value) {
                      // Panel is collapsed — expand it + open video panel, then wait for VideoEmbed to mount.
                      options.onExpandPractice?.();
                      options.onExpandVideo?.();
                      setTimeout(doPlay, 300);
                    } else {
                      options.onExpandVideo?.();
                      doPlay();
                    }
                  },
                  onVideoPause: () => ph.pause(),
                  onVideoSeek: (seconds: number) => { ph.seek(seconds + ((videoSync.videoTimeOffset as number) ?? 0)); ph.play(); },
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

  // ── <sbn-synced-player> — fetch leadsheet/exercise bars + rhythm, mount ──────
  // Attrs: slug (required), type (leadsheet|exercise, default leadsheet),
  //        start (0-based bar index), end (0-based inclusive), autoplay (bool)
  container.querySelectorAll<HTMLElement>('sbn-synced-player').forEach((el) => {
    const slug     = el.getAttribute('slug') ?? '';
    const type     = el.getAttribute('type') ?? 'leadsheet';
    const start    = el.getAttribute('start') ?? '';
    const end      = el.getAttribute('end') ?? '';
    const autoplay = el.getAttribute('autoplay');
    if (!slug) return;

    const params = new URLSearchParams({ type });
    if (start !== '') params.set('start', start);
    if (end   !== '') params.set('end',   end);
    const url = `/api/sbn/synced-player/${slug}?${params}`;

    const task = fetch(url, { headers: { Accept: 'application/json' } })
      .then((r) => {
        if (!r.ok) throw new Error(`synced-player fetch failed: ${r.status}`);
        return r.json();
      })
      .then(async (data: any) => {
        const mod = await import('../Components/SyncedPlayer/SyncedPlayer.vue');
        const Component = (mod as any).default ?? mod;

        el.classList.add('sbn-synced-player-embed');
        const app = createApp(Component, {
          bars:          data.bars ?? [],
          rhythmPattern: data.rhythmPattern ?? undefined,
          autoplay:      autoplay === 'true',
          loop:          true,
        });
        app.mount(el);
        apps.push(app);
      })
      .catch((err) => {
        console.warn(`[mountSbnNodes] Failed to mount <sbn-synced-player slug="${slug}">:`, err);
        el.innerHTML = `<span class="sbn-node-error">synced-player: ${slug}</span>`;
      });

    tasks.push(task);
  });

  await Promise.all(tasks);

  return () => {
    apps.forEach((app) => app.unmount());
    apps.length = 0;
  };
}
