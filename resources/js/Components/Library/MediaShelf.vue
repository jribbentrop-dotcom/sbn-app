<script setup lang="ts">
import { ref, onMounted, onBeforeUnmount } from 'vue';
import { Link } from '@inertiajs/vue3';

const props = defineProps<{
    title?: string;
    viewAllHref?: string;
}>();

const track = ref<HTMLElement | null>(null);
const canScrollLeft  = ref(false);
const canScrollRight = ref(false);

function updateScrollState() {
    const el = track.value;
    if (!el) return;
    canScrollLeft.value  = el.scrollLeft > 0;
    canScrollRight.value = el.scrollLeft + el.clientWidth < el.scrollWidth - 1;
}

function scroll(dir: 'left' | 'right') {
    if (!track.value) return;
    track.value.scrollBy({ left: dir === 'right' ? 142 : -142, behavior: 'smooth' });
}

let ro: ResizeObserver | null = null;

onMounted(() => {
    const el = track.value;
    if (!el) return;
    el.addEventListener('scroll', updateScrollState, { passive: true });
    ro = new ResizeObserver(updateScrollState);
    ro.observe(el);
    updateScrollState();
});

onBeforeUnmount(() => {
    track.value?.removeEventListener('scroll', updateScrollState);
    ro?.disconnect();
});
</script>

<template>
    <div class="sbn-media-shelf">
        <div v-if="title" class="sbn-section-heading-row">
            <h2 class="sbn-section-heading">{{ title }}</h2>
            <Link v-if="viewAllHref" :href="viewAllHref" class="sbn-section-link">View all →</Link>
        </div>
        <div class="sbn-media-shelf__wrap">
            <div ref="track" class="sbn-media-shelf__track">
                <slot />
            </div>
            <button v-show="canScrollLeft"  class="sbn-card-scroll-btn sbn-card-scroll-btn--prev" @click="scroll('left')"  aria-label="Scroll left">‹</button>
            <button v-show="canScrollRight" class="sbn-card-scroll-btn sbn-card-scroll-btn--next" @click="scroll('right')" aria-label="Scroll right">›</button>
        </div>
    </div>
</template>

<style scoped>

.sbn-media-shelf__wrap {
    position: relative;
}

.sbn-media-shelf__track {
    display: flex;
    gap: 10px;
    overflow-x: auto;
    scroll-snap-type: x mandatory;
    -webkit-overflow-scrolling: touch;
    scrollbar-width: none;
    padding-bottom: 4px;
}

.sbn-media-shelf__track::-webkit-scrollbar {
    display: none;
}

.sbn-media-shelf__track > * {
    scroll-snap-align: start;
    flex-shrink: 0;
}
</style>
