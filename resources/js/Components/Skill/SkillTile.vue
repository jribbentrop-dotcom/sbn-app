<script setup lang="ts">
import SkillIcon from '@/Components/Skill/SkillIcon.vue';

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
    state: 'done' | 'available' | 'locked';
}

defineProps<{ node: SkillTreeNode }>();
defineEmits<{ click: [] }>();
</script>

<template>
    <button
        type="button"
        class="skt-tile"
        :class="['skt-tile--' + node.state, 'skt-style-' + (node.styleColor ?? 'neutral')]"
        :aria-label="node.title"
        @click="$emit('click')"
    >
        <div class="skt-tile-shape">
            <SkillIcon :icon-path="node.iconPath" :icon-key="node.iconKey" :branch="node.branch" :size="40" />
        </div>
        <div v-if="node.state === 'done'" class="skt-tile-badge skt-tile-badge--done" aria-hidden="true">
            <svg xmlns="http://www.w3.org/2000/svg" width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round">
                <path d="M20 6 9 17l-5-5"/>
            </svg>
        </div>
        <div v-else-if="node.state === 'locked'" class="skt-tile-badge skt-tile-badge--locked" aria-hidden="true">
            <svg xmlns="http://www.w3.org/2000/svg" width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M16.5 10.5V6.75a4.5 4.5 0 1 0-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 0 0 2.25-2.25v-6.75a2.25 2.25 0 0 0-2.25-2.25H6.75a2.25 2.25 0 0 0-2.25 2.25v6.75a2.25 2.25 0 0 0 2.25 2.25Z"/>
            </svg>
        </div>
        <span class="skt-tile-label">{{ node.title }}</span>
    </button>
</template>
