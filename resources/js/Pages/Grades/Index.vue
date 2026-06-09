<script setup lang="ts">
import { onMounted, onUnmounted, ref } from 'vue';
import { Head } from '@inertiajs/vue3';
import PublicLayout from '@/Layouts/PublicLayout.vue';
import { grades, gradeImageSrc } from '@/composables/useGrades';

defineOptions({ layout: PublicLayout });

const activeGrade = ref(1);
const gradesSection = ref<HTMLElement | null>(null);
let blockObserver: IntersectionObserver | null = null;
let stepObserver: IntersectionObserver | null = null;
let progressHandler: (() => void) | null = null;

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
                            <div class="chord-chips">
                                <span
                                    v-for="chord in g.chords"
                                    :key="chord"
                                    class="chord-chip"
                                    :data-chord="chord"
                                >{{ chord }}</span>
                            </div>
                            <!-- TODO: wire to grade detail/lesson route -->
                            <a href="#" class="grade-cta">{{ g.cta }} →</a>
                        </div>
                    </div>
                </div>
            </div>
        </section>

    </div>
</template>
