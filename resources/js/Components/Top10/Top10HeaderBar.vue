<script setup lang="ts">
import { onMounted, onUnmounted, ref } from 'vue';
import { Link, router } from '@inertiajs/vue3';

defineProps<{
    /** Route of the currently-active Top10 list, e.g. '/top10/bossa-nova-songs' */
    active: string;
}>();

const lists = [
    { href: '/top10/bossa-nova-chords',   label: 'Bossa Nova Chords' },
    { href: '/top10/latin-jazz-standards', label: 'Latin Jazz Standards' },
    { href: '/top10/bossa-nova-songs',     label: 'Bossa Nova Songs' },
];

// Loading state during Inertia navigation between the lists.
const loading = ref(false);
const pendingHref = ref<string | null>(null);

const onStart = (e: any) => {
    const url: string = e.detail?.visit?.url?.pathname ?? '';
    const match = lists.find(l => l.href === url);
    if (!match) return;            // only react to inter-list navigation
    loading.value = true;
    pendingHref.value = match.href;
};
const onFinish = () => {
    loading.value = false;
    pendingHref.value = null;
};

let stopStart: (() => void) | null = null;
let stopFinish: (() => void) | null = null;

onMounted(() => {
    stopStart = router.on('start', onStart);
    stopFinish = router.on('finish', onFinish);
});
onUnmounted(() => {
    stopStart?.();
    stopFinish?.();
});
</script>

<template>
    <nav class="top10-header-bar" aria-label="Top 10 lists">
        <ul class="top10-bar-steps">
            <li v-for="(list, i) in lists" :key="list.href">
                <Link
                    :href="list.href"
                    class="top10-bar-step"
                    :class="{ active: active === list.href, pending: pendingHref === list.href }"
                >
                    <span class="top10-bar-num">{{ i + 1 }}</span>
                    <span class="top10-bar-label">{{ list.label }}</span>
                </Link>
            </li>
        </ul>
        <div class="top10-bar-progress" :class="{ active: loading }"></div>
    </nav>
</template>

<style scoped>
.top10-header-bar {
    position: sticky;
    top: var(--header-height, 64px);
    z-index: 100;
    background: color-mix(in srgb, var(--clr-bg) 80%, transparent);
    backdrop-filter: blur(14px);
    -webkit-backdrop-filter: blur(14px);
    border-bottom: 1px solid var(--clr-line);
    height: 64px;
    display: flex;
    align-items: center;
    justify-content: center;
    overflow-x: auto;
}

.top10-bar-steps {
    display: flex;
    gap: 0;
    list-style: none;
    margin: 0;
    padding: 0;
    height: 100%;
}

.top10-bar-step {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 0 20px;
    font-size: .83rem;
    font-weight: 600;
    text-decoration: none;
    color: var(--clr-text-dim);
    border-bottom: 2px solid transparent;
    transition: color .2s, border-color .2s;
    height: 100%;
    white-space: nowrap;
}
.top10-bar-step:hover {
    color: var(--clr-accent);
}
.top10-bar-step.active {
    color: var(--clr-accent);
    border-bottom-color: var(--clr-accent);
}
.top10-bar-step.pending {
    color: var(--clr-accent);
}

.top10-bar-num {
    width: 24px;
    height: 24px;
    border-radius: 50%;
    background: var(--clr-bg-elev);
    border: 1.5px solid var(--clr-line);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: .75rem;
    font-weight: 700;
    transition: background .2s, border-color .2s, color .2s;
    color: var(--clr-text-dim);
    flex-shrink: 0;
}
.top10-bar-step.active .top10-bar-num {
    background: var(--clr-accent);
    border-color: var(--clr-accent);
    color: #fff;
}

.top10-bar-label { white-space: nowrap; }
.top10-bar-step.pending .top10-bar-num {
    border-color: var(--clr-accent);
    color: var(--clr-accent);
}

/* Indeterminate loading bar — slides under the header during inter-list navigation */
.top10-bar-progress {
    position: absolute;
    bottom: 0;
    left: 0;
    height: 2px;
    width: 100%;
    background: var(--clr-accent);
    transform: scaleX(0);
    transform-origin: left;
    opacity: 0;
    pointer-events: none;
}
.top10-bar-progress.active {
    opacity: 1;
    animation: top10-bar-load 1.1s cubic-bezier(.4, 0, .2, 1) infinite;
}
@keyframes top10-bar-load {
    0%   { transform: translateX(-100%) scaleX(.4); }
    50%  { transform: translateX(0%)    scaleX(.55); }
    100% { transform: translateX(100%)  scaleX(.4); }
}

@media (prefers-reduced-motion: reduce) {
    .top10-bar-progress.active { animation: none; transform: scaleX(1); opacity: .6; }
}
</style>
