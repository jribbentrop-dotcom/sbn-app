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

const props = defineProps<{ nodes: SkillNode[] }>();

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
</script>

<template>
    <div class="sbn-page sbn-page-detail">
        <header class="sbn-account-pageheader">
            <h1>My Skills</h1>
            <p class="sbn-account-subtle">Mark skills as you learn them. No pressure — just a map of where you are.</p>
        </header>

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
