<script setup lang="ts">
import { ref, onMounted, onBeforeUnmount, nextTick } from 'vue';
import { Link } from '@inertiajs/vue3';
import SkillIcon from '@/Components/Skill/SkillIcon.vue';

interface PathNode {
    id: string;
    slug: string;
    title: string;
    grade: 1 | 2 | 3 | 4 | 5;
    branch: string;
    iconPath: string;
    row: number; // 0-indexed visual row (top to bottom)
    col: number; // 0-indexed position within the row
}

interface PathEdge {
    from: string;
    to: string;
}

// Hand-curated subset of the real skill graph — a coherent bossa/jazz comping
// path from first chords to chord melody. Slugs, grades, icon paths, and the
// prerequisite edges below are all real (verified against sbn_skill_nodes /
// sbn_skill_node_prerequisites), not illustrative placeholders.
const nodes: PathNode[] = [
    { id: 'the-basic-8',          slug: 'the-basic-8',          title: 'The Basic 8',        grade: 1, branch: 'harmony',   iconPath: 'images/skills/the-basic-8.svg',          row: 0, col: 0 },

    { id: 'triads',               slug: 'triads',               title: 'Triads',             grade: 2, branch: 'harmony',   iconPath: 'images/skills/triads.svg',               row: 1, col: 0 },
    { id: 'pulse-subdivision',    slug: 'pulse-subdivision',     title: 'Pulse & Subdivision', grade: 2, branch: 'rhythm',   iconPath: 'images/skills/pulse-subdivision.svg',    row: 1, col: 1 },

    { id: 'shell-voicings',       slug: 'shell-voicings',        title: 'Shell Voicings',     grade: 2, branch: 'harmony',   iconPath: 'images/skills/shell-voicings.svg',       row: 2, col: 0 },
    { id: 'two-four-feel',        slug: 'two-four-feel',         title: '2/4 Feel',           grade: 2, branch: 'rhythm',    iconPath: 'images/skills/two-four-feel.svg',        row: 2, col: 1 },
    { id: 'syncopation',          slug: 'syncopation',           title: 'Syncopation',        grade: 2, branch: 'rhythm',    iconPath: 'images/skills/syncopation.svg',          row: 2, col: 2 },

    { id: 'ii-v-i-major',         slug: 'ii-v-i-major',          title: 'ii–V–I in Major',    grade: 3, branch: 'harmony',   iconPath: 'images/skills/ii-v-i-major.svg',         row: 3, col: 0 },
    { id: 'comping-patterns',     slug: 'comping-patterns',      title: 'Comping Patterns',   grade: 3, branch: 'rhythm',    iconPath: 'images/skills/comping-patterns.svg',     row: 3, col: 1 },

    { id: 'chord-melody',         slug: 'chord-melody',          title: 'Chord Melody',       grade: 5, branch: 'harmony',   iconPath: 'images/skills/chord-melody.svg',         row: 4, col: 0 },
];

const edges: PathEdge[] = [
    { from: 'the-basic-8', to: 'triads' },
    { from: 'the-basic-8', to: 'pulse-subdivision' },
    { from: 'triads', to: 'shell-voicings' },
    { from: 'pulse-subdivision', to: 'two-four-feel' },
    { from: 'pulse-subdivision', to: 'syncopation' },
    { from: 'shell-voicings', to: 'ii-v-i-major' },
    { from: 'two-four-feel', to: 'comping-patterns' },
    { from: 'syncopation', to: 'comping-patterns' },
    { from: 'ii-v-i-major', to: 'chord-melody' },
    { from: 'comping-patterns', to: 'chord-melody' },
];

const rowCounts = [1, 2, 3, 2, 1];
const ROW_COUNT = rowCounts.length;
const byId = Object.fromEntries(nodes.map(n => [n.id, n]));

const outerRef = ref<HTMLElement | null>(null);
const stickyRef = ref<HTMLElement | null>(null);
const treeRef = ref<HTMLElement | null>(null);
const containerWidth = ref(640);
const containerHeight = ref(560);
const outerHeight = ref<string | undefined>(undefined); // set once pinning is confirmed active; undefined = CSS default (auto)
const revealedRow = ref(-1); // -1 = nothing revealed yet; N = rows 0..N are revealed
const iconSize = ref(22); // bumped to 28 on desktop (>700px) in updateSize()

const NODE_ROW_PAD = 90; // top/bottom breathing room inside the sticky canvas

function nodePos(n: PathNode): { x: number; y: number } {
    const count = rowCounts[n.row] ?? 1;
    const pct = (n.col + 1) / (count + 1);
    const usableH = containerHeight.value - NODE_ROW_PAD * 2;
    const rowY = ROW_COUNT > 1 ? (n.row / (ROW_COUNT - 1)) * usableH : usableH / 2;
    return {
        x: pct * containerWidth.value,
        y: NODE_ROW_PAD + rowY,
    };
}

function edgePath(e: PathEdge): string {
    const a = nodePos(byId[e.from]);
    const b = nodePos(byId[e.to]);
    const midY = (a.y + b.y) / 2;
    return `M${a.x},${a.y} C${a.x},${midY} ${b.x},${midY} ${b.x},${b.y}`;
}

let pinningActive = false;
let ro: ResizeObserver | null = null;
let ticking = false;

function updateSize() {
    if (treeRef.value) {
        containerWidth.value = treeRef.value.clientWidth;
        containerHeight.value = treeRef.value.clientHeight;
    }
    iconSize.value = window.innerWidth > 700 ? 34 : 22;
    // .sps-outer's height must equal .sps-sticky's real rendered height (which
    // is viewport-capped via CSS, not a flat px value) plus one viewport of
    // scroll room — otherwise the pin range and the CSS box disagree and either
    // leave dead scroll space or cut the reveal off early. Recomputed on every
    // resize since the sticky height itself is responsive. Only applies when
    // pinning is actually active (desktop, no reduced-motion) — on mobile /
    // reduced-motion .sps-sticky is position:static, so .sps-outer should just
    // be its natural auto height (leaving outerHeight unset).
    if (pinningActive && stickyRef.value) {
        outerHeight.value = `calc(${stickyRef.value.offsetHeight}px + 100vh)`;
    }
}

function onScroll() {
    if (ticking) return;
    ticking = true;
    requestAnimationFrame(() => {
        ticking = false;
        const outer = outerRef.value;
        if (!outer) return;

        const rect = outer.getBoundingClientRect();

        // Two phases share one progress value:
        //  1. Approach — section is still scrolling into view, before pinning
        //     starts. Progress runs from 0 (bottom of viewport) to a small
        //     head start once the section top reaches the viewport top.
        //  2. Pinned — the classic sticky-scrub range once .sps-sticky is
        //     actually pinned, continuing progress up to 1.
        // This lets the first couple of rows start revealing while the user
        // is still scrolling the section into view, instead of waiting for
        // the full pin to engage.
        const APPROACH_HEAD_START = 0.25; // reveal begins ramping toward this by the time pinning starts
        const approachRange = window.innerHeight; // distance from "bottom of viewport" to "top of viewport"
        const approachPct = approachRange > 0
            ? Math.min(1, Math.max(0, (window.innerHeight - rect.top) / approachRange))
            : 0;

        const pinnedTotal = outer.offsetHeight - window.innerHeight;
        const pinnedPct = pinnedTotal > 0 ? Math.min(1, Math.max(0, -rect.top / pinnedTotal)) : 0;

        const pct = rect.top > 0
            ? approachPct * APPROACH_HEAD_START
            : APPROACH_HEAD_START + pinnedPct * (1 - APPROACH_HEAD_START);

        // Reveal rows progressively across the combined progress range; the
        // last row (index ROW_COUNT - 1) should land at pct === 1, so divide
        // by (ROW_COUNT - 1) rather than ROW_COUNT — otherwise the final row
        // lands early and the rest of the pinned range sits idle.
        const row = Math.round(pct * (ROW_COUNT - 1));
        revealedRow.value = Math.min(ROW_COUNT - 1, row);
    });
}

onMounted(async () => {
    await nextTick();

    const prefersReducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    const isDesktop = window.matchMedia('(min-width: 701px)').matches; // matches the 700px CSS breakpoint below
    pinningActive = isDesktop && !prefersReducedMotion;

    updateSize();

    ro = new ResizeObserver(updateSize);
    if (treeRef.value) ro.observe(treeRef.value);
    if (stickyRef.value) ro.observe(stickyRef.value);

    if (!pinningActive) {
        revealedRow.value = ROW_COUNT - 1;
    } else {
        window.addEventListener('scroll', onScroll, { passive: true });
        onScroll();
    }
});

onBeforeUnmount(() => {
    window.removeEventListener('scroll', onScroll);
    ro?.disconnect();
});
</script>

<template>
    <section class="sps-section">
        <div class="sps-outer" ref="outerRef" :style="{ height: outerHeight }">
            <div class="sps-sticky" ref="stickyRef">
                <div class="sps-head home-wrap">
                    <div class="eyebrow">The skill system</div>
                    <h2>Your path, <em>your choice.</em></h2>
                    <p>Skills, rhythms, and repertoire branch and merge on the way to mastery — scroll to see how it connects.</p>
                </div>

                <div class="sps-tree" ref="treeRef">
                    <svg class="sps-overlay" :width="containerWidth" :height="containerHeight">
                        <path
                            v-for="e in edges"
                            :key="`${e.from}-${e.to}`"
                            class="sps-edge"
                            :class="{ on: revealedRow >= byId[e.to].row }"
                            :d="edgePath(e)"
                        />
                    </svg>

                    <Link
                        v-for="n in nodes"
                        :key="n.id"
                        :href="`/skills#${n.slug}`"
                        class="sps-node"
                        :class="[`branch-${n.branch}`, { on: revealedRow >= n.row }]"
                        :style="{ left: `${nodePos(n).x}px`, top: `${nodePos(n).y}px` }"
                    >
                        <span class="sps-node-icon">
                            <SkillIcon :icon-path="n.iconPath" :branch="n.branch" :size="iconSize" />
                        </span>
                        <span class="sps-node-tag">{{ n.title }}</span>
                    </Link>

                    <div class="sps-legend">
                        <span><span class="dot branch-harmony"></span>Harmony</span>
                        <span><span class="dot branch-rhythm"></span>Rhythm</span>
                    </div>
                </div>
            </div>
        </div>

        <div class="sps-cta">
            <Link href="/skills" class="sbn-btn sbn-btn-secondary">Browse the full skill glossary →</Link>
        </div>
    </section>
</template>

<style scoped>
.sps-section {
    background: var(--clr-white, #fff);
    padding: 84px 0 64px;
}

.sps-head {
    text-align: center;
    max-width: 620px;
    margin: 0 auto;
    padding-top: 8px;
}
.sps-head h2 {
    font-family: var(--font-heading);
    font-weight: 600;
    font-size: clamp(2rem, 3.6vw, 2.8rem);
    letter-spacing: -.02em;
    margin-bottom: 14px;
}
.sps-head h2 em {
    font-style: italic;
    color: var(--clr-accent);
}
.sps-head p {
    color: var(--clr-text-dim);
    font-size: 1.05rem;
}

/* ── Sticky scroll-scrub container (matches GradesTeaser pattern) ──
   The heading pins together with the tree (not left behind above it), so
   .sps-sticky holds both. .sps-outer's height is set inline from JS to
   .sps-sticky's real rendered height + 100vh of scroll room — that single
   extra viewport is exactly the range .sps-sticky stays pinned for, enough
   to scrub through all 5 rows without leftover dead space once the last
   row lands. */
.sps-outer {
    position: relative;
}

.sps-sticky {
    position: sticky;
    top: calc(var(--header-height, 64px) + 24px);
    /* Caps at 800px on tall screens but shrinks to fit shorter viewports
       (minus header + top margin + bottom breathing room) so the whole
       pinned block is always fully visible instead of overflowing. */
    height: min(800px, calc(100vh - var(--header-height, 64px) - 64px));
    min-height: 480px;
    display: flex;
    flex-direction: column;
}

/* On big desktop monitors, stretch the pinned block taller so the tree
   fills more of the available height instead of leaving unused vertical
   space above/below it. */
@media (min-width: 1200px) {
    .sps-sticky {
        height: min(980px, calc(100vh - var(--header-height, 64px) - 64px));
    }
}

.sps-tree {
    position: relative;
    flex: 1;
    max-width: 640px;
    width: 100%;
    margin: 0 auto;
    overflow: hidden;
}

.sps-overlay {
    position: absolute;
    inset: 0;
    overflow: visible;
}

.sps-edge {
    fill: none;
    stroke: var(--clr-border, #e2e5ea);
    stroke-width: 2;
    stroke-dasharray: 600;
    stroke-dashoffset: 600;
    transition: stroke-dashoffset .8s ease;
}
.sps-edge.on {
    stroke-dashoffset: 0;
}

.sps-node {
    --node-size: 44px;
    position: absolute;
    display: flex;
    align-items: center;
    justify-content: center;
    width: var(--node-size);
    height: var(--node-size);
    border-radius: 50%;
    background: var(--clr-white, #fff);
    border: 1.5px solid var(--clr-border);
    transform: translate(-50%, -50%) scale(.5);
    opacity: 0;
    text-decoration: none;
    transition: opacity .45s ease, transform .45s cubic-bezier(.34, 1.56, .64, 1), border-color .2s ease;
}
.sps-node.on {
    opacity: 1;
    transform: translate(-50%, -50%) scale(1);
}
.sps-node:hover {
    border-color: currentColor;
}

@media (min-width: 701px) {
    .sps-node {
        --node-size: 58px;
    }
}

.sps-node.branch-harmony      { color: var(--clr-branch-harmony); }
.sps-node.branch-rhythm       { color: var(--clr-branch-rhythm); }
.sps-node.branch-melody       { color: var(--clr-branch-melody); }
.sps-node.branch-technique    { color: var(--clr-branch-technique); }
.sps-node.branch-ear-training { color: var(--clr-branch-ear-training); }
.sps-node.branch-reading-theory { color: var(--clr-branch-reading-theory); }

.sps-node-icon {
    display: flex;
    align-items: center;
    justify-content: center;
    color: currentColor;
}
/* SkillIcon's custom-SVG branch renders an <img width height>, but the
   global `img { max-width: 100%; height: auto }` reset in base.css
   overrides those attributes and recomputes size from the (unconstrained)
   flex parent — silently ignoring the `size` prop. Pin both dimensions
   here so the prop actually controls rendered size. */
.sps-node-icon :deep(img) {
    max-width: none;
    width: v-bind('`${iconSize}px`');
    height: v-bind('`${iconSize}px`');
}

.sps-node-tag {
    position: absolute;
    top: calc(var(--node-size) + 8px);
    left: 50%;
    transform: translateX(-50%);
    font-size: .7rem;
    font-family: var(--font-mono, monospace);
    color: var(--clr-text);
    white-space: nowrap;
    background: rgba(255, 255, 255, .92);
    padding: 2px 7px;
    border-radius: 4px;
    pointer-events: none;
}

.sps-legend {
    position: absolute;
    bottom: 8px;
    left: 0;
    right: 0;
    display: flex;
    gap: 24px;
    justify-content: center;
    font-family: var(--font-mono, monospace);
    font-size: .72rem;
    color: var(--clr-text-dim);
}
.sps-legend span {
    display: inline-flex;
    align-items: center;
    gap: 8px;
}
.sps-legend .dot {
    width: 10px;
    height: 10px;
    border-radius: 50%;
    background: currentColor;
}
.sps-legend .branch-harmony { color: var(--clr-branch-harmony); }
.sps-legend .branch-rhythm  { color: var(--clr-branch-rhythm); }

.sps-cta {
    display: flex;
    justify-content: center;
    margin-top: 32px;
}

/* Below 700px, pinningActive is false: JS never sets an inline height on
   .sps-outer, so it falls back to auto here — no need to fight an inline
   style with !important. */
@media (max-width: 700px) {
    .sps-sticky {
        position: static;
        height: auto;
    }
    .sps-tree {
        max-width: 100%;
        height: 560px;
        padding: 0 16px;
    }
}

@media (prefers-reduced-motion: reduce) {
    .sps-sticky { position: static; }
    .sps-node,
    .sps-edge {
        transition: opacity .3s ease !important;
        transform: translate(-50%, -50%) scale(1) !important;
    }
    .sps-edge {
        stroke-dashoffset: 0 !important;
    }
}
</style>
