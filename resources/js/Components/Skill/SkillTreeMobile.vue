<script setup lang="ts">
import { ref, computed, watch } from 'vue';
import SkillIcon from '@/Components/Skill/SkillIcon.vue';
import { BRANCH_ORDER, BRANCH_LABELS } from '@/Constants/skillBranches';

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

const props = defineProps<{ nodes: SkillTreeNode[]; edges: SkillTreeEdge[] }>();
defineEmits<{ toggle: [node: SkillTreeNode] }>();

const nodesById = computed(() => new Map(props.nodes.map(n => [n.id, n])));

const availableBranches = computed(() =>
    BRANCH_ORDER.filter(b => props.nodes.some(n => n.branch === b))
);

const activeBranch = ref(availableBranches.value[0] ?? BRANCH_ORDER[0]);

// The parent's style-tab switch changes which branches have nodes (props.nodes
// is pre-filtered) — reset to the first available branch when the active one
// drops out, so the list never renders as silently empty.
watch(availableBranches, (branches) => {
    if (!branches.includes(activeBranch.value)) {
        activeBranch.value = branches[0] ?? BRANCH_ORDER[0];
    }
});

const branchNodes = computed(() =>
    props.nodes
        .filter(n => n.branch === activeBranch.value)
        .slice()
        .sort((a, b) => (a.grade ?? 99) - (b.grade ?? 99) || a.title.localeCompare(b.title))
);

// For each node, incomplete cross-branch prerequisites (the actionable "why locked" info).
function crossBranchNotes(node: SkillTreeNode): string[] {
    return props.edges
        .filter(e => e.from === node.id && e.crossBranch)
        .map(e => nodesById.value.get(e.to))
        .filter((n): n is SkillTreeNode => !!n && n.state !== 'done')
        .map(n => `${n.title} (${BRANCH_LABELS[n.branch] ?? n.branch})`);
}
</script>

<template>
    <div>
        <div class="skt-branch-switcher">
            <button
                v-for="b in availableBranches"
                :key="b"
                type="button"
                class="skt-branch-tab"
                :class="{ 'is-active': activeBranch === b }"
                @click="activeBranch = b"
            >
                {{ BRANCH_LABELS[b] ?? b }}
            </button>
        </div>

        <div class="skt-branch-list">
            <button
                v-for="node in branchNodes"
                :key="node.id"
                type="button"
                class="skt-branch-list-item skill-node-card"
                :class="{ 'is-done': node.state === 'done', 'is-locked': node.state === 'locked' }"
                @click="$emit('toggle', node)"
            >
                <div class="skill-node-icon">
                    <SkillIcon :icon-path="node.iconPath" :icon-key="node.iconKey" :branch="node.branch" :size="20" />
                </div>
                <div class="skt-branch-list-item-body">
                    <span class="skill-node-title">{{ node.title }}</span>
                    <span v-if="node.state === 'locked' && crossBranchNotes(node).length" class="skt-branch-list-prereq-note">
                        Requires: {{ crossBranchNotes(node).join(', ') }}
                    </span>
                </div>
                <div class="skill-node-check" aria-hidden="true">
                    <svg v-if="node.state === 'done'" xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M20 6 9 17l-5-5"/>
                    </svg>
                    <svg v-else-if="node.state === 'locked'" xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M16.5 10.5V6.75a4.5 4.5 0 1 0-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 0 0 2.25-2.25v-6.75a2.25 2.25 0 0 0-2.25-2.25H6.75a2.25 2.25 0 0 0-2.25 2.25v6.75a2.25 2.25 0 0 0 2.25 2.25Z"/>
                    </svg>
                </div>
            </button>
        </div>
    </div>
</template>
