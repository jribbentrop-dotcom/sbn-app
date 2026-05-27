<script setup lang="ts">
import { ref } from 'vue';

const props = defineProps<{
    title?: string;
}>();

const track = ref<HTMLElement | null>(null);

function scroll(dir: 'left' | 'right') {
    if (!track.value) return;
    const amount = track.value.clientWidth * 0.75;
    track.value.scrollBy({ left: dir === 'right' ? amount : -amount, behavior: 'smooth' });
}
</script>

<template>
    <div class="sbn-media-shelf">
        <div v-if="title || $slots.heading" class="sbn-media-shelf__header">
            <h2 v-if="title" class="sbn-media-shelf__title">{{ title }}</h2>
            <slot name="heading" />
            <div class="sbn-media-shelf__nav">
                <button class="sbn-media-shelf__btn" aria-label="Scroll left"  @click="scroll('left')">‹</button>
                <button class="sbn-media-shelf__btn" aria-label="Scroll right" @click="scroll('right')">›</button>
            </div>
        </div>
        <div ref="track" class="sbn-media-shelf__track">
            <slot />
        </div>
    </div>
</template>

<style scoped>
.sbn-media-shelf {
    position: relative;
}

.sbn-media-shelf__header {
    display: flex;
    align-items: baseline;
    justify-content: space-between;
    gap: 12px;
    margin-bottom: 14px;
}

.sbn-media-shelf__title {
    font-size: 1.05em;
    font-weight: 700;
    color: var(--clr-text);
    margin: 0;
    padding-bottom: 8px;
    border-bottom: 2px solid var(--clr-border);
    flex: 1;
}

.sbn-media-shelf__nav {
    display: flex;
    gap: 4px;
    flex-shrink: 0;
}

.sbn-media-shelf__btn {
    width: 28px;
    height: 28px;
    border-radius: 50%;
    border: 1px solid var(--clr-border);
    background: var(--clr-white);
    color: var(--clr-text);
    font-size: 1.1em;
    line-height: 1;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: background 0.15s, border-color 0.15s;
    padding-bottom: 1px; /* optical centre for ‹ › glyphs */
}

.sbn-media-shelf__btn:hover {
    background: var(--clr-surface-2);
    border-color: var(--clr-text-muted);
}

/* The scrollable track */
.sbn-media-shelf__track {
    display: flex;
    gap: 10px;
    overflow-x: auto;
    scroll-snap-type: x mandatory;
    -webkit-overflow-scrolling: touch;
    scrollbar-width: none;          /* Firefox */
    padding-bottom: 4px;            /* room for box-shadow on cards */
}

.sbn-media-shelf__track::-webkit-scrollbar {
    display: none;
}

/* Every direct child snaps */
.sbn-media-shelf__track > * {
    scroll-snap-align: start;
    flex-shrink: 0;
}
</style>
