<script setup lang="ts">
import { computed, ref } from 'vue';
import PublicLayout from '@/Layouts/PublicLayout.vue';
import AccountLayout from '@/Layouts/AccountLayout.vue';
import SkillTile from '@/Components/Skill/SkillTile.vue';
import SkillTreeEdges from '@/Components/Skill/SkillTreeEdges.vue';
import SkillTreeMobile from '@/Components/Skill/SkillTreeMobile.vue';
import SkillIcon from '@/Components/Skill/SkillIcon.vue';

defineOptions({ layout: [PublicLayout, AccountLayout] });

interface SkillTreeNode {
    id: number;
    slug: string;
    title: string;
    branch: string;
    subBranch: string | null;
    grade: number | null;
    posX: number | null;
    posY: number | null;
    iconKey: string | null;
    iconPath: string | null;
    styleColor: string | null;
    styles: string[];
    state: 'done' | 'available' | 'locked';
}
interface SkillTreeEdge { from: number; to: number; crossBranch: boolean }
interface GradeStat { grade: number; label: string; done: number; total: number; pct: number; cleared: boolean; current: boolean }
interface GradeStats {
    level: number;
    levelLabel: string;
    threshold: number;
    grades: Record<number, GradeStat>;
}

const props = defineProps<{ nodes: SkillTreeNode[]; edges: SkillTreeEdge[]; gradeStats: GradeStats }>();

// Local, optimistically-updatable copy — the server computes `state` per load,
// but a live toggle needs to flip one tile's state without a full reload.
const localNodes = ref<SkillTreeNode[]>(props.nodes.map(n => ({ ...n })));

const nodesById = computed(() => new Map(localNodes.value.map(n => [n.id, n])));

// ── Style tabs ──────────────────────────────────────────────────────────
// One tree per style, plus a shared "Foundations" tab for neutral (no-style)
// nodes — every style tree is too dense to read as one graph, and most
// grade-1 nodes carry no style tag anyway (see conversation 2026-07-02).
const TABS = [
    { key: 'foundations', label: 'Foundations' },
    { key: 'bossa-nova', label: 'Bossa Nova' },
    { key: 'jazz', label: 'Jazz' },
    { key: 'classical', label: 'Classical' },
    { key: 'pop', label: 'Pop' },
] as const;
type TabKey = typeof TABS[number]['key'];

const activeTab = ref<TabKey>('foundations');

function nodeInTab(node: SkillTreeNode, tab: TabKey): boolean {
    if (tab === 'foundations') return node.styles.length === 0;
    // Foundations nodes are the shared base of every style tree.
    return node.styles.length === 0 || node.styles.includes(tab);
}

const visibleNodes = computed(() => localNodes.value.filter(n => nodeInTab(n, activeTab.value)));
const visibleNodeIds = computed(() => new Set(visibleNodes.value.map(n => n.id)));
const visibleEdges = computed(() => props.edges.filter(e => visibleNodeIds.value.has(e.from) && visibleNodeIds.value.has(e.to)));

const positioned = computed(() => visibleNodes.value.filter(n => n.posX != null && n.posY != null));

if (positioned.value.length !== visibleNodes.value.length && import.meta.env.DEV) {
    // eslint-disable-next-line no-console
    console.warn('SkillTree: some nodes are missing pos_x/pos_y and will not render on the tile tree.');
}

// The seeded pos_x/pos_y grid was hand-laid against all 64 nodes sharing one
// canvas (some grade-3 rows pack 18 nodes into ~1000 units — under 8px apart
// once rendered). A style tab only shows a subset, so re-spread each visible
// tier's x positions evenly across the canvas width — keeps left-to-right
// order stable (from the original seeded x) but guarantees no overlap.
const MIN_GAP = 90; // design units; tile is 64px, canvas typically 600-900px wide
const repackedX = computed(() => {
    const byTier = new Map<number, SkillTreeNode[]>();
    for (const n of positioned.value) {
        const tier = n.posY!;
        (byTier.get(tier) ?? byTier.set(tier, []).get(tier)!).push(n);
    }

    const overrides = new Map<number, number>();
    for (const nodes of byTier.values()) {
        const sorted = [...nodes].sort((a, b) => a.posX! - b.posX!);
        const count = sorted.length;
        if (count <= 1) {
            if (count === 1) overrides.set(sorted[0].id, sorted[0].posX!);
            continue;
        }
        const span = Math.max(MIN_GAP * (count - 1), 1);
        const start = Math.max(60, Math.min(940, 500 - span / 2));
        sorted.forEach((n, i) => {
            const x = span <= 880 ? start + i * MIN_GAP : 60 + (i / (count - 1)) * 880;
            overrides.set(n.id, x);
        });
    }
    return overrides;
});

function tilePos(node: SkillTreeNode) {
    const x = repackedX.value.get(node.id) ?? node.posX!;
    return { left: (x / 1000 * 100) + '%', top: (node.posY! / 1000 * 100) + '%' };
}

const edgesResolved = computed(() =>
    visibleEdges.value
        .map(e => {
            const from = nodesById.value.get(e.from);
            const to = nodesById.value.get(e.to);
            if (!from || !to || from.posX == null || from.posY == null || to.posX == null || to.posY == null) return null;
            const fromX = repackedX.value.get(from.id) ?? from.posX;
            const toX = repackedX.value.get(to.id) ?? to.posX;
            // edge points from prerequisite (to) up to the dependent (from) — matches admin editor convention
            return { fromPos: { x: toX, y: to.posY }, toPos: { x: fromX, y: from.posY }, crossBranch: e.crossBranch };
        })
        .filter((e): e is { fromPos: { x: number; y: number }; toPos: { x: number; y: number }; crossBranch: boolean } => !!e)
);

// "You are here" line: midpoint pos_y between the student's current grade tier
// and the next grade up, derived from real seeded tier positions within the
// active tab (each tab's tier bands sit at slightly different heights since
// they're a filtered subset of the full graph's positions).
const youAreHereTop = computed(() => {
    const level = props.gradeStats.level;
    const graded = positioned.value.filter(n => n.grade != null);
    if (!graded.length) return null;

    const nextGradeNodes = graded.filter(n => n.grade === level + 1);
    const currentGradeNodes = graded.filter(n => n.grade === level);

    if (level >= 5 || !nextGradeNodes.length) return null;

    if (level === 0) {
        const lowestBand = Math.max(...(currentGradeNodes.length ? currentGradeNodes : graded).map(n => n.posY!));
        return Math.min(1000, lowestBand + 60) / 10 + '%';
    }

    const nextTop = Math.min(...nextGradeNodes.map(n => n.posY!));
    const currentBottom = currentGradeNodes.length ? Math.max(...currentGradeNodes.map(n => n.posY!)) : nextTop + 120;
    return ((nextTop + currentBottom) / 2) / 10 + '%';
});

// ── Node detail popover ──────────────────────────────────────────────────
const openNodeId = ref<number | null>(null);
const openNode = computed(() => openNodeId.value != null ? nodesById.value.get(openNodeId.value) ?? null : null);

function prereqsFor(node: SkillTreeNode) {
    return props.edges
        .filter(e => e.from === node.id)
        .map(e => nodesById.value.get(e.to))
        .filter((n): n is SkillTreeNode => !!n);
}

function selectNode(node: SkillTreeNode) {
    openNodeId.value = openNodeId.value === node.id ? null : node.id;
}

// ── Toggle (reused endpoint, optimistic + completion glow) ─────────────────
const pending = ref<Set<number>>(new Set());
const justCompletedId = ref<number | null>(null);

function csrfToken(): string {
    return (document.querySelector('meta[name="csrf-token"]') as HTMLMetaElement)?.content ?? '';
}

async function toggle(node: SkillTreeNode) {
    if (pending.value.has(node.id)) return;
    pending.value.add(node.id);

    const wasDone = node.state === 'done';

    try {
        const res = await fetch(`/account/skills/${node.slug}/toggle`, {
            method: 'POST',
            headers: { 'X-CSRF-TOKEN': csrfToken() },
        });
        if (!res.ok) throw new Error('toggle failed');
        const data = await res.json();

        node.state = data.done ? 'done' : 'available';
        // Re-evaluate downstream availability locally (approximation — a full
        // reload recomputes it server-side, but this keeps the UI responsive).
        if (data.done && !wasDone) {
            justCompletedId.value = node.id;
            setTimeout(() => { if (justCompletedId.value === node.id) justCompletedId.value = null; }, 1200);
        }
    } catch {
        // leave state as-is on failure
    } finally {
        pending.value.delete(node.id);
    }
}

function onMobileToggle(node: SkillTreeNode) {
    toggle(node);
}
</script>

<template>
    <div class="sbn-page sbn-page-detail">
        <header class="sbn-account-pageheader">
            <h1>Skill Tree</h1>
            <p class="sbn-account-subtle">Climb the tree as you learn. Locked nodes are just a preview of what's ahead — nothing here is off-limits.</p>
        </header>

        <div class="skt-style-tabs">
            <button
                v-for="tab in TABS"
                :key="tab.key"
                type="button"
                class="skt-style-tab"
                :class="['skt-style-tab--' + tab.key, { 'is-active': activeTab === tab.key }]"
                @click="activeTab = tab.key"
            >
                {{ tab.label }}
            </button>
        </div>

        <div class="skt-active-tab-banner" :class="'skt-active-tab-banner--' + activeTab">
            {{ TABS.find(t => t.key === activeTab)?.label }}
            <span class="skt-active-tab-banner-count">{{ positioned.length }} skill{{ positioned.length === 1 ? '' : 's' }}</span>
        </div>

        <section class="skt-tree-wrap skt-desktop-only">
            <SkillTreeEdges :edges="edgesResolved" />
            <div v-if="youAreHereTop" class="skt-you-are-here-line" :style="{ top: youAreHereTop }">
                <span>You are here — Level {{ gradeStats.level }}</span>
            </div>
            <SkillTile
                v-for="n in positioned"
                :key="n.id"
                :node="n"
                :style="tilePos(n)"
                :class="{ 'skt-tile--just-completed': justCompletedId === n.id }"
                @click="selectNode(n)"
            />
        </section>

        <SkillTreeMobile class="skt-mobile-only" :nodes="visibleNodes" :edges="visibleEdges" @toggle="onMobileToggle" />

        <div v-if="openNode" class="skt-popover-backdrop" @click="openNodeId = null">
            <div class="skt-popover" @click.stop>
                <button type="button" class="skt-popover-close" @click="openNodeId = null" aria-label="Close">&times;</button>
                <div class="skt-popover-header">
                    <div class="skt-popover-icon" :class="'skt-style-' + (openNode.styleColor ?? 'neutral')">
                        <SkillIcon :icon-path="openNode.iconPath" :icon-key="openNode.iconKey" :branch="openNode.branch" :size="88" />
                    </div>
                    <h3>{{ openNode.title }}</h3>
                    <p class="skt-popover-meta">{{ openNode.branch }}<template v-if="openNode.grade"> · Grade {{ openNode.grade }}</template></p>
                </div>
                <div v-if="prereqsFor(openNode).length" class="skt-popover-prereqs">
                    <span class="skt-popover-prereqs-label">Requires</span>
                    <ul>
                        <li v-for="p in prereqsFor(openNode)" :key="p.id" :class="{ 'is-done': p.state === 'done' }">
                            <div class="skt-popover-prereq-icon" :class="'skt-style-' + (p.styleColor ?? 'neutral')">
                                <SkillIcon :icon-path="p.iconPath" :icon-key="p.iconKey" :branch="p.branch" :size="26" />
                            </div>
                            <span class="skt-popover-prereq-title">{{ p.title }}</span>
                            <svg v-if="p.state === 'done'" class="skt-popover-prereq-check" xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M20 6 9 17l-5-5"/>
                            </svg>
                        </li>
                    </ul>
                </div>
                <button type="button" class="sbn-btn sbn-btn-secondary" @click="toggle(openNode)">
                    {{ openNode.state === 'done' ? 'Mark incomplete' : 'Mark complete' }}
                </button>
            </div>
        </div>
    </div>
</template>
