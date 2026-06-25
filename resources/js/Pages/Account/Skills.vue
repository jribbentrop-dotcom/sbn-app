<script setup lang="ts">
import { reactive, computed } from 'vue';
import PublicLayout from '@/Layouts/PublicLayout.vue';
import AccountLayout from '@/Layouts/AccountLayout.vue';
import SkillIcon from '@/Components/Skill/SkillIcon.vue';

defineOptions({ layout: [PublicLayout, AccountLayout] });

interface SkillNode {
    slug: string;
    title: string;
    branch: string;
    subBranch: string | null;
    grade: number | null;
    iconKey: string | null;
    iconPath: string | null;
    done: boolean;
}

interface GradeStat { grade: number; label: string; done: number; total: number; pct: number; cleared: boolean; current: boolean }
interface GradeStats {
    level: number;
    levelLabel: string;
    threshold: number;
    grades: Record<number, GradeStat>;
}

const props = defineProps<{ nodes: SkillNode[]; gradeStats: GradeStats }>();

// Plain reactive objects — Vue tracks property additions/deletions on these.
const done = reactive<Record<string, true>>(
    Object.fromEntries(props.nodes.filter(n => n.done).map(n => [n.slug, true]))
);
const pending = reactive<Record<string, true>>({});

function isDone(slug: string) { return !!done[slug]; }

function toggle(node: SkillNode) {
    if (pending[node.slug]) return;
    pending[node.slug] = true;

    const wasDone = !!done[node.slug];
    wasDone ? delete done[node.slug] : (done[node.slug] = true);

    fetch(`/account/skills/${node.slug}/toggle`, {
        method: 'POST',
        headers: { 'X-CSRF-TOKEN': (document.querySelector('meta[name="csrf-token"]') as HTMLMetaElement)?.content ?? '' },
    }).catch(() => {
        wasDone ? (done[node.slug] = true) : delete done[node.slug];
    }).finally(() => {
        delete pending[node.slug];
    });
}

const BRANCH_ORDER = ['harmony', 'rhythm', 'melody', 'technique', 'ear-training', 'reading-theory'];
const BRANCH_LABELS: Record<string, string> = {
    harmony: 'Harmony',
    rhythm: 'Rhythm',
    melody: 'Melody',
    technique: 'Technique',
    'ear-training': 'Ear Training',
    'reading-theory': 'Reading & Theory',
};

const byBranch = computed(() => {
    const map = new Map<string, SkillNode[]>();
    for (const branch of BRANCH_ORDER) map.set(branch, []);
    for (const node of props.nodes) {
        if (map.has(node.branch)) map.get(node.branch)!.push(node);
    }
    return [...map.entries()].filter(([, nodes]) => nodes.length > 0);
});

function branchProgress(branch: string) {
    const nodes = byBranch.value.find(([b]) => b === branch)?.[1] ?? [];
    const done = nodes.filter(n => isDone(n.slug)).length;
    return { done, total: nodes.length };
}

// ── Live grade computation ───────────────────────────────────────────────────
// Mirrors App\Services\SkillGradeService so the level updates instantly as the
// student ticks nodes (no server round-trip). Threshold + labels come from the
// server payload (single source of truth); the math is duplicated by necessity
// because the toggles are optimistic and we don't refetch on every click.
const MAX_GRADE = 5;
const threshold = computed(() => props.gradeStats.threshold ?? 0.7);

const liveGrades = computed(() => {
    // tally graded nodes only: grade => {total, done}
    const tally: Record<number, { total: number; done: number }> = {};
    for (const n of props.nodes) {
        if (n.grade == null) continue;
        (tally[n.grade] ??= { total: 0, done: 0 }).total++;
        if (isDone(n.slug)) tally[n.grade].done++;
    }
    const maxDefined = Object.keys(tally).length ? Math.max(...Object.keys(tally).map(Number)) : 0;

    const grades: GradeStat[] = [];
    for (let g = 1; g <= MAX_GRADE; g++) {
        const t = tally[g]?.total ?? 0;
        const d = tally[g]?.done ?? 0;
        const cleared = t === 0 ? true : d / t >= threshold.value;
        grades.push({
            grade: g,
            label: props.gradeStats.grades[g]?.label ?? `Grade ${g}`,
            done: d, total: t,
            pct: t === 0 ? 0 : Math.round((d / t) * 100),
            cleared, current: false,
        });
    }

    let level = 0;
    for (let g = 1; g <= MAX_GRADE; g++) {
        if (grades[g - 1].cleared) level = g; else break;
    }
    level = Math.min(level, maxDefined);

    for (let g = 1; g <= MAX_GRADE; g++) {
        if (grades[g - 1].total > 0 && !grades[g - 1].cleared) { grades[g - 1].current = true; break; }
    }

    return { level, levelLabel: level === 0 ? 'Getting started' : (props.gradeStats.grades[level]?.label ?? `Grade ${level}`), grades };
});

// Only show grades that have nodes (hide an empty top grade until curated).
const visibleGrades = computed(() => liveGrades.value.grades.filter(g => g.total > 0));
</script>

<template>
    <div class="sbn-page sbn-page-detail">
        <header class="sbn-account-pageheader">
            <h1>My Skills</h1>
            <p class="sbn-account-subtle">Mark skills as you learn them. No pressure — just a map of where you are.</p>
        </header>

        <!-- Grade / level panel -->
        <section class="skill-grade-panel">
            <div class="skill-grade-level">
                <span class="skill-grade-level-num">{{ liveGrades.level || '–' }}</span>
                <div class="skill-grade-level-meta">
                    <span class="skill-grade-level-lbl">Your level</span>
                    <strong class="skill-grade-level-name">{{ liveGrades.levelLabel }}</strong>
                </div>
            </div>
            <div class="skill-grade-bars">
                <div
                    v-for="g in visibleGrades"
                    :key="g.grade"
                    class="skill-grade-bar"
                    :class="{ 'is-cleared': g.cleared, 'is-current': g.current }"
                >
                    <div class="skill-grade-bar-head">
                        <span class="skill-grade-bar-name">{{ g.grade }} · {{ g.label }}</span>
                        <span class="skill-grade-bar-count">{{ g.done }}/{{ g.total }}</span>
                    </div>
                    <div class="skill-grade-bar-track">
                        <div class="skill-grade-bar-fill" :style="{ width: g.pct + '%' }" />
                        <div class="skill-grade-bar-threshold" :style="{ left: Math.round(threshold * 100) + '%' }" :title="`${Math.round(threshold * 100)}% to clear`" />
                    </div>
                </div>
            </div>
        </section>

        <div class="skill-branches">
            <section v-for="[branch, nodes] in byBranch" :key="branch" class="skill-branch">
                <div class="skill-branch-header">
                    <div class="skill-branch-icon">
                        <SkillIcon :branch="branch" :size="20" />
                    </div>
                    <h2 class="skill-branch-title">{{ BRANCH_LABELS[branch] }}</h2>
                    <div class="skill-branch-progress">
                        {{ branchProgress(branch).done }}/{{ branchProgress(branch).total }}
                    </div>
                </div>

                <div class="skill-node-grid">
                    <button
                        v-for="node in nodes"
                        :key="node.slug"
                        class="skill-node-card"
                        :class="{ 'is-done': isDone(node.slug), 'is-pending': !!pending[node.slug] }"
                        @click="toggle(node)"
                        :aria-pressed="isDone(node.slug)"
                        :aria-label="(isDone(node.slug) ? 'Mark incomplete: ' : 'Mark complete: ') + node.title"
                    >
                        <div class="skill-node-icon">
                            <SkillIcon :icon-path="node.iconPath" :icon-key="node.iconKey" :branch="node.branch" :size="20" />
                        </div>
                        <span class="skill-node-title">{{ node.title }}</span>
                        <div class="skill-node-check" aria-hidden="true">
                            <svg v-if="isDone(node.slug)" xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M20 6 9 17l-5-5"/>
                            </svg>
                        </div>
                    </button>
                </div>
            </section>
        </div>
    </div>
</template>

<style scoped>
/* ── Grade / level panel ─────────────────────────────────────────────────── */
.skill-grade-panel {
    display: flex;
    gap: 1.5rem;
    align-items: flex-start;
    flex-wrap: wrap;
    padding: 1.25rem 1.5rem;
    margin-top: 1.25rem;
    border: 1px solid var(--sbn-border, #e5e5e5);
    border-radius: 12px;
    background: var(--sbn-card-bg, #fff);
}
.skill-grade-level {
    display: flex;
    align-items: center;
    gap: 0.875rem;
    flex-shrink: 0;
}
.skill-grade-level-num {
    display: grid;
    place-items: center;
    width: 56px;
    height: 56px;
    border-radius: 14px;
    background: var(--sbn-accent, #b8860b);
    color: #fff;
    font-size: 1.75rem;
    font-weight: 700;
    font-variant-numeric: tabular-nums;
}
.skill-grade-level-meta { display: flex; flex-direction: column; }
.skill-grade-level-lbl {
    font-size: 0.7rem;
    text-transform: uppercase;
    letter-spacing: 0.06em;
    color: var(--sbn-muted, #888);
}
.skill-grade-level-name { font-size: 1.05rem; font-weight: 600; }

.skill-grade-bars {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
    gap: 0.625rem 1rem;
    flex: 1;
    min-width: 240px;
}
.skill-grade-bar-head {
    display: flex;
    justify-content: space-between;
    align-items: baseline;
    margin-bottom: 0.3rem;
    gap: 0.5rem;
}
.skill-grade-bar-name { font-size: 0.78rem; font-weight: 600; }
.skill-grade-bar-count {
    font-size: 0.72rem;
    color: var(--sbn-muted, #888);
    font-variant-numeric: tabular-nums;
}
.skill-grade-bar-track {
    position: relative;
    height: 7px;
    border-radius: 999px;
    background: var(--sbn-track, #ececec);
    overflow: hidden;
}
.skill-grade-bar-fill {
    height: 100%;
    border-radius: 999px;
    background: color-mix(in srgb, var(--sbn-accent, #b8860b) 55%, #ccc);
    transition: width 0.25s ease;
}
.skill-grade-bar.is-cleared .skill-grade-bar-fill { background: var(--sbn-accent, #b8860b); }
.skill-grade-bar.is-current .skill-grade-bar-name { color: var(--sbn-accent, #b8860b); }
/* threshold tick — the % needed to "clear" this grade */
.skill-grade-bar-threshold {
    position: absolute;
    top: 0;
    bottom: 0;
    width: 2px;
    background: var(--sbn-text, #1a1a1a);
    opacity: 0.4;
}

.skill-branches {
    display: flex;
    flex-direction: column;
    gap: 2.5rem;
    margin-top: 1.5rem;
}

.skill-branch-header {
    display: flex;
    align-items: center;
    gap: 0.6rem;
    margin-bottom: 0.875rem;
}

.skill-branch-icon {
    color: var(--sbn-accent, #b8860b);
    flex-shrink: 0;
    display: flex;
}

.skill-branch-title {
    font-size: 1rem;
    font-weight: 600;
    letter-spacing: 0.01em;
    margin: 0;
}

.skill-branch-progress {
    margin-left: auto;
    font-size: 0.8rem;
    color: var(--sbn-muted, #888);
    font-variant-numeric: tabular-nums;
}

.skill-node-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
    gap: 0.5rem;
}

.skill-node-card {
    display: flex;
    align-items: center;
    gap: 0.625rem;
    padding: 0.625rem 0.75rem;
    border: 1px solid var(--sbn-border, #e5e5e5);
    border-radius: 8px;
    background: var(--sbn-card-bg, #fff);
    cursor: pointer;
    text-align: left;
    transition: border-color 0.15s, background 0.15s, opacity 0.1s;
    color: var(--sbn-text, #1a1a1a);
}

.skill-node-card:hover {
    border-color: var(--sbn-accent, #b8860b);
}

.skill-node-card.is-done {
    background: color-mix(in srgb, var(--sbn-accent, #b8860b) 8%, transparent);
    border-color: color-mix(in srgb, var(--sbn-accent, #b8860b) 40%, transparent);
}

.skill-node-card.is-pending {
    opacity: 0.6;
    pointer-events: none;
}

.skill-node-icon {
    flex-shrink: 0;
    color: var(--sbn-muted, #888);
    display: flex;
}

.skill-node-card.is-done .skill-node-icon {
    color: var(--sbn-accent, #b8860b);
}

.skill-node-title {
    flex: 1;
    font-size: 0.85rem;
    line-height: 1.3;
}

.skill-node-check {
    flex-shrink: 0;
    width: 16px;
    height: 16px;
    display: flex;
    align-items: center;
    justify-content: center;
    color: var(--sbn-accent, #b8860b);
}
</style>
