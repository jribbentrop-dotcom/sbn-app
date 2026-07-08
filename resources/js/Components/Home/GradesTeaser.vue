<script setup lang="ts">
import { ref, onMounted, onUnmounted } from 'vue';
import { Link } from '@inertiajs/vue3';
import { grades } from '@/composables/useGrades';

const outerRef  = ref<HTMLElement | null>(null);
const activeIdx = ref(0);
const scrollPct = ref(0);

let current = -1;

function setGrade(idx: number) {
    if (idx === current) return;
    current = idx;
    activeIdx.value = idx;

    const g = grades[idx];
    document.documentElement.style.setProperty('--active-clr', g.clr);

    if (idx > 0) {
        const hint = document.querySelector<HTMLElement>('.gt-scroll-hint');
        if (hint) hint.style.opacity = '0';
    }
}

function onScroll() {
    const outer = outerRef.value;
    if (!outer) return;

    const rect  = outer.getBoundingClientRect();
    const total = outer.offsetHeight - window.innerHeight;
    const pct   = Math.min(1, Math.max(0, -rect.top / total));
    scrollPct.value = pct;

    const idx = Math.min(4, Math.floor(pct * 5));
    setGrade(idx);
}

function scrollToGrade(idx: number) {
    const outer = outerRef.value;
    if (!outer) return;
    const segH   = outer.offsetHeight / 5;
    const target = outer.offsetTop + segH * idx + 1;
    window.scrollTo({ top: target, behavior: 'smooth' });
}

onMounted(() => {
    document.documentElement.style.setProperty('--active-clr', grades[0].clr);
    window.addEventListener('scroll', onScroll, { passive: true });
});

onUnmounted(() => {
    window.removeEventListener('scroll', onScroll);
    document.documentElement.style.removeProperty('--active-clr');
});
</script>

<template>
    <div class="gt-section-head home-wrap">
        <div class="eyebrow">Find your level</div>
        <h2>Five grades. <em>One clear path.</em></h2>
        <p>From your first open chord to rootless jazz voicings — every grade is a milestone, not just a label.</p>
    </div>

    <div class="gt-outer" ref="outerRef">
        <div class="gt-sticky">


            <!-- Left progress bar -->
            <div class="gt-progress-bar">
                <div class="gt-progress-fill" :style="`height: ${scrollPct * 100}%`"></div>
            </div>

            <!-- Content grid -->
            <div class="gt-content">

                <!-- Left: text panel -->
                <div class="gt-text-panel" :class="`grade-transition`">
                    <div class="gt-counter" :style="`color: var(--active-clr)`">
                        {{ grades[activeIdx].n }}
                    </div>
                    <Transition name="gt-fade" mode="out-in">
                        <div :key="activeIdx" class="gt-text-inner">
                            <div class="gt-label">{{ grades[activeIdx].label }}</div>
                            <p class="gt-blurb">{{ grades[activeIdx].blurb }}</p>
                            <!-- TODO: wire to grade detail route -->
                            <Link href="/grades" class="gt-cta">{{ grades[activeIdx].cta }} →</Link>
                        </div>
                    </Transition>
                </div>

                <!-- Right: vertical stepper -->
                <div class="gt-stepper">
                    <div
                        v-for="(g, i) in grades"
                        :key="g.slug"
                        class="gt-stepper-item"
                        :class="{
                            active: activeIdx === i,
                            past:   activeIdx > i,
                        }"
                        @click="scrollToGrade(i)"
                    >
                        <div class="gt-dot"></div>
                        <span class="gt-step-label">{{ g.label }}</span>
                    </div>
                </div>

            </div>

            <!-- Scroll hint -->
            <div class="gt-scroll-hint">scroll ↓</div>

        </div>
    </div>
</template>

<style scoped>
/* ── Section heading ── */
.gt-section-head {
    text-align: center;
    max-width: 620px;
    margin: 84px auto 0;
    padding-bottom: 0;
}
.gt-section-head h2 {
    font-family: var(--font-heading);
    font-weight: 600;
    font-size: clamp(2rem, 3.6vw, 2.8rem);
    letter-spacing: -.02em;
    margin-bottom: 14px;
}
.gt-section-head h2 em {
    font-style: italic;
    color: var(--clr-accent);
}
.gt-section-head p {
    color: var(--clr-text-dim);
    font-size: 1.05rem;
}

/* ── Outer scroll container ── */
.gt-outer {
    height: calc(560px + 100vh);
    position: relative;
    max-width: 1200px;
    margin: 32px auto 84px;
    padding: 0 24px;
}

/* ── Sticky block ── */
.gt-sticky {
    position: sticky;
    top: calc(var(--header-height, 64px) + 24px);
    height: 560px;
    overflow: hidden;
    display: flex;
    align-items: center;
    justify-content: center;
}


/* ── Left progress bar ── */
.gt-progress-bar {
    position: absolute;
    left: 0;
    top: 0;
    bottom: 0;
    width: 3px;
    background: var(--clr-line);
    z-index: 3;
}
.gt-progress-fill {
    width: 100%;
    background: var(--active-clr, var(--clr-accent));
    transition: background-color .4s ease;
    height: 0%;
}

/* ── Content grid ── */
.gt-content {
    position: relative;
    z-index: 2;
    display: grid;
    grid-template-columns: 1fr 1fr;
    align-items: center;
    gap: 48px;
    width: 100%;
    padding: 0 48px;
}

/* ── Text panel ── */
.gt-counter {
    font-family: var(--font-heading);
    font-size: clamp(4rem, 7vw, 7rem);
    font-weight: 600;
    line-height: 1;
    letter-spacing: -.04em;
    transition: color .5s ease;
    margin-bottom: 4px;
}

.gt-text-inner {
    transition: opacity .18s ease, transform .18s ease;
}

.gt-label {
    font-family: var(--font-heading);
    font-size: clamp(1.4rem, 2.4vw, 2rem);
    font-weight: 600;
    letter-spacing: -.02em;
    color: var(--clr-text);
    margin-bottom: 16px;
}

.gt-blurb {
    color: var(--clr-text-dim);
    font-size: 1.05rem;
    line-height: 1.65;
    max-width: 420px;
    margin-bottom: 28px;
}

.gt-cta {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    font-weight: 600;
    font-size: .95rem;
    color: var(--active-clr, var(--clr-accent));
    text-decoration: none;
    transition: opacity .15s;
}
.gt-cta:hover { opacity: .75; }

/* Vue transition */
.gt-fade-enter-active,
.gt-fade-leave-active {
    transition: opacity .18s ease, transform .18s ease;
}
.gt-fade-enter-from {
    opacity: 0;
    transform: translateY(6px);
}
.gt-fade-leave-to {
    opacity: 0;
    transform: translateY(-6px);
}

/* ── Vertical stepper ── */
.gt-stepper {
    display: flex;
    flex-direction: column;
    gap: 0;
    align-items: flex-start;
}

.gt-stepper-item {
    display: flex;
    align-items: center;
    gap: 14px;
    cursor: pointer;
    padding: 12px 0;
    position: relative;
    width: 100%;
    user-select: none;
}

/* Connector line between dots */
.gt-stepper-item:not(:last-child)::after {
    content: "";
    position: absolute;
    left: 9px;
    top: calc(50% + 10px);
    bottom: calc(-50% + 10px);
    width: 1px;
    background: var(--clr-line);
}

.gt-dot {
    width: 20px;
    height: 20px;
    border-radius: 50%;
    border: 1.5px solid var(--clr-line);
    background: #fff;
    flex-shrink: 0;
    transition: background .3s, border-color .3s, box-shadow .3s;
}

.gt-step-label {
    font-size: .88rem;
    font-weight: 500;
    color: var(--clr-text-dim);
    opacity: .35;
    transition: opacity .3s;
    white-space: nowrap;
}

/* Past state */
.gt-stepper-item.past .gt-dot {
    background: color-mix(in srgb, var(--active-clr) 12%, #fff);
    border-color: color-mix(in srgb, var(--active-clr) 30%, var(--clr-line));
}
.gt-stepper-item.past .gt-step-label { opacity: .55; }

/* Active state */
.gt-stepper-item.active .gt-dot {
    background: var(--active-clr, var(--clr-accent));
    border-color: var(--active-clr, var(--clr-accent));
    box-shadow: 0 0 0 4px color-mix(in srgb, var(--active-clr) 22%, transparent);
}
.gt-stepper-item.active .gt-step-label {
    opacity: 1;
    color: var(--clr-text);
    font-weight: 600;
}

/* ── Scroll hint ── */
.gt-scroll-hint {
    position: absolute;
    bottom: 28px;
    left: 50%;
    transform: translateX(-50%);
    font-size: .8rem;
    color: var(--clr-text-dim);
    letter-spacing: .06em;
    text-transform: uppercase;
    opacity: .7;
    animation: gt-bounce 2s ease-in-out infinite;
    transition: opacity .4s ease;
    z-index: 3;
    pointer-events: none;
}
@keyframes gt-bounce {
    0%, 100% { transform: translateX(-50%) translateY(0); }
    50%       { transform: translateX(-50%) translateY(6px); }
}

/* ── Mobile ── */
@media (max-width: 700px) {
    .gt-outer { margin: 48px auto; }
    .gt-sticky { height: 480px; }
    .gt-content {
        grid-template-columns: 1fr;
        padding: 0 24px;
        align-items: flex-start;
        padding-top: 36px;
    }
    .gt-stepper {
        flex-direction: row;
        justify-content: center;
        gap: 0;
        width: 100%;
    }
    .gt-stepper-item {
        flex-direction: column;
        align-items: center;
        gap: 6px;
        padding: 0 12px;
        width: auto;
    }
    .gt-stepper-item::after { display: none; }
    .gt-step-label { display: none; }
}

/* ── Reduced motion ── */
@media (prefers-reduced-motion: reduce) {
    .gt-img-slide,
    .gt-text-inner,
    .gt-dot,
    .gt-cta,
    .gt-progress-fill { transition: none !important; }
    .gt-scroll-hint { animation: none !important; }
    .gt-fade-enter-active,
    .gt-fade-leave-active { transition: none !important; }
}
</style>
