<script setup lang="ts">
import { onMounted, onUnmounted, ref } from 'vue';
import { Head } from '@inertiajs/vue3';
import PublicLayout from '@/Layouts/PublicLayout.vue';
import GradePanel from '@/Components/Grades/GradePanel.vue';
import { grades, gradeImageSrc } from '@/composables/useGrades';

defineOptions({ layout: PublicLayout });

const props = defineProps<{
    panels: Record<number, {
        skills: any[];
        chords: any[];
        rhythms: any[];
        progressions: any[];
        songs: any[];
        courses: any[];
    }>;
}>();

const activeGrade = ref(1);
const openPanels = ref<Set<number>>(new Set());
const gradesSection = ref<HTMLElement | null>(null);
let blockObserver: IntersectionObserver | null = null;
let stepObserver: IntersectionObserver | null = null;
let progressHandler: (() => void) | null = null;

function togglePanel(n: number) {
    if (openPanels.value.has(n)) {
        openPanels.value.delete(n);
    } else {
        openPanels.value.add(n);
    }
}

onMounted(() => {
    const supportsScrollTimeline = CSS.supports('animation-timeline', 'scroll()');
    const blocks = document.querySelectorAll<HTMLElement>('.grade-block');

    if (!supportsScrollTimeline) {
        blockObserver = new IntersectionObserver(entries => {
            entries.forEach(e => {
                if (e.isIntersecting) e.target.classList.add('visible');
            });
        }, { threshold: 0.15 });
        blocks.forEach(b => blockObserver!.observe(b));
    }

    stepObserver = new IntersectionObserver(entries => {
        entries.forEach(e => {
            if (e.isIntersecting) {
                const n = parseInt((e.target as HTMLElement).dataset.gradeN ?? '1', 10);
                activeGrade.value = n;
            }
        });
    }, { threshold: 0.4 });
    blocks.forEach(b => stepObserver!.observe(b));

    const section = gradesSection.value;
    const progressBar = document.querySelector<HTMLElement>('.stepper-progress');
    if (section && progressBar && !supportsScrollTimeline) {
        progressHandler = () => {
            const rect = section.getBoundingClientRect();
            const pct = Math.min(100, Math.max(0,
                (-rect.top / (section.offsetHeight - window.innerHeight)) * 100
            ));
            progressBar.style.width = pct + '%';
        };
        window.addEventListener('scroll', progressHandler, { passive: true });
    }
});

onUnmounted(() => {
    blockObserver?.disconnect();
    stepObserver?.disconnect();
    if (progressHandler) window.removeEventListener('scroll', progressHandler);
});
</script>

<template>
    <Head title="Difficulty Levels" />

    <div class="home-page grades-page">

        <!-- Page hero -->
        <section class="grades-hero">
            <div class="home-wrap">
                <div class="eyebrow reveal d1">Find your level</div>
                <h1 class="reveal d2">Five grades.<br><em>One clear path.</em></h1>
                <p class="grades-hero-lead reveal d3">
                    From your first open chord to rootless jazz voicings —
                    every grade is a milestone, not just a label.
                </p>
            </div>
        </section>

        <!-- Main grades section -->
        <section class="grades-section" id="grades" ref="gradesSection">

            <!-- Sticky stepper -->
            <div class="stepper-wrap">
                <ul class="stepper-steps">
                    <li v-for="g in grades" :key="g.n">
                        <a
                            :href="`#grade-${g.n}`"
                            class="step"
                            :class="{ active: activeGrade === g.n }"
                            :style="`--current-grade-clr: ${g.clr}`"
                        >
                            <span class="step-num">{{ g.n }}</span>
                            <span class="step-label">{{ g.label }}</span>
                        </a>
                    </li>
                </ul>
                <div
                    class="stepper-progress"
                    :style="`--current-grade-clr: ${grades[activeGrade - 1]?.clr ?? 'var(--clr-accent)'}`"
                ></div>
            </div>

            <!-- Grade blocks -->
            <div class="home-wrap">
                <div class="grades-body">
                    <div
                        v-for="g in grades"
                        :key="g.n"
                        class="grade-entry"
                    >
                        <div
                            class="grade-block"
                            :id="`grade-${g.n}`"
                            :style="`--grade-clr: ${g.clr}`"
                            :data-grade-n="g.n"
                        >
                            <div class="grade-img">
                                <img
                                    :src="gradeImageSrc(g.slug)"
                                    :alt="`${g.label} guitarist`"
                                    loading="lazy"
                                >
                            </div>

                            <div class="grade-text">
                                <div class="grade-badge">
                                    <span class="grade-pip"></span>
                                    Grade {{ g.n }} · {{ g.label }}
                                </div>
                                <h3>{{ g.title }} <em>{{ g.titleEm }}</em></h3>
                                <p>{{ g.blurb }}</p>
                            </div>
                        </div>

                        <!-- Full-width bar — IS the separator between grades -->
                        <button
                            class="grade-panel-toggle"
                            :class="{ open: openPanels.has(g.n) }"
                            :style="`--panel-clr: ${g.clr}`"
                            @click="togglePanel(g.n)"
                        >
                            <span class="gpt-line"></span>
                            <span class="gpt-label">
                                {{ openPanels.has(g.n) ? 'Hide examples' : `Grade ${g.n} examples` }}
                                <svg width="12" height="12" viewBox="0 0 12 12" fill="none" aria-hidden="true">
                                    <path d="M2 4l4 4 4-4" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>
                                </svg>
                            </span>
                            <span class="gpt-line"></span>
                        </button>

                        <Transition name="panel-slide">
                            <GradePanel
                                v-if="openPanels.has(g.n)"
                                :gradeN="g.n"
                                :gradeClr="g.clr"
                                :gradeLabel="g.label"
                                :data="panels[g.n]"
                                :allUrl="`/grades#grade-${g.n}`"
                            />
                        </Transition>
                    </div>
                </div>
            </div>
        </section>

    </div>
</template>

<style scoped>
.grades-body {
    display: flex;
    flex-direction: column;
    gap: 0 !important;
}

.grade-entry {
    display: flex;
    flex-direction: column;
}

/* Separation between entries — sits above each grade block except the first */
.grade-entry + .grade-entry {
    margin-top: 40px;
}

.grade-panel-toggle {
    display: flex;
    align-items: center;
    gap: 16px;
    width: 100%;
    padding: 18px 0 10px;
    border: none;
    background: transparent;
    color: var(--clr-text-muted, #888);
    cursor: pointer;
    transition: color .15s;
}
.grade-panel-toggle:hover {
    color: var(--panel-clr);
}
.grade-panel-toggle.open {
    color: var(--panel-clr);
}

.gpt-line {
    flex: 1;
    height: 1px;
    background: currentColor;
    opacity: .25;
    transition: opacity .15s;
}
.grade-panel-toggle:hover .gpt-line,
.grade-panel-toggle.open .gpt-line {
    opacity: .6;
}

.gpt-label {
    display: flex;
    align-items: center;
    gap: 6px;
    white-space: nowrap;
    font-size: .78rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: .07em;
    flex-shrink: 0;
}
.gpt-label svg {
    transition: transform .2s;
}
.grade-panel-toggle.open .gpt-label svg {
    transform: rotate(180deg);
}

/* Slide transition */
.panel-slide-enter-active,
.panel-slide-leave-active {
    transition: max-height .35s ease, opacity .25s ease;
    overflow: hidden;
    max-height: 1000px;
}
.panel-slide-enter-from,
.panel-slide-leave-to {
    max-height: 0;
    opacity: 0;
}
</style>
