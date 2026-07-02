<script setup lang="ts">
interface ResolvedEdge {
    fromPos: { x: number; y: number };
    toPos: { x: number; y: number };
    crossBranch: boolean;
}

defineProps<{ edges: ResolvedEdge[] }>();
</script>

<template>
    <svg class="skt-edges" viewBox="0 0 1000 1000" preserveAspectRatio="none">
        <line
            v-for="(e, i) in edges"
            :key="i"
            :x1="e.fromPos.x" :y1="e.fromPos.y"
            :x2="e.toPos.x" :y2="e.toPos.y"
            :class="e.crossBranch ? 'skt-edge skt-edge--cross' : 'skt-edge skt-edge--same'"
        />
    </svg>
</template>
