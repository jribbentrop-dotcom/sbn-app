<script setup lang="ts">
import { ref, onMounted, onBeforeUnmount } from 'vue';
import { Link } from '@inertiajs/vue3';
import { grades, gradeImageSrc } from '@/composables/useGrades';

const activeIdx = ref(0);
const trackRef  = ref<HTMLElement | null>(null);

let dragStartX  = 0;
let isDragging  = false;

function setActive(idx: number) {
    activeIdx.value = Math.max(0, Math.min(grades.length - 1, idx));
    const cards = trackRef.value?.querySelectorAll<HTMLElement>('.gs-card');
    cards?.[activeIdx.value]?.scrollIntoView({ behavior: 'smooth', block: 'nearest', inline: 'center' });
}

function onKeydown(e: KeyboardEvent) {
    if (e.key === 'ArrowLeft')  setActive(activeIdx.value - 1);
    if (e.key === 'ArrowRight') setActive(activeIdx.value + 1);
}

function onMousedown(e: MouseEvent) {
    isDragging = true;
    dragStartX = e.clientX;
    trackRef.value?.classList.add('grabbing');
}
function onMouseup(e: MouseEvent) {
    if (!isDragging) return;
    isDragging = false;
    trackRef.value?.classList.remove('grabbing');
    const delta = e.clientX - dragStartX;
    if (Math.abs(delta) > 40) setActive(activeIdx.value + (delta < 0 ? 1 : -1));
}
function onMouseleave() {
    isDragging = false;
    trackRef.value?.classList.remove('grabbing');
}

let touchStartX = 0;
function onTouchstart(e: TouchEvent) { touchStartX = e.touches[0].clientX; }
function onTouchend(e: TouchEvent) {
    const delta = e.changedTouches[0].clientX - touchStartX;
    if (Math.abs(delta) > 40) setActive(activeIdx.value + (delta < 0 ? 1 : -1));
}

onMounted(()  => document.addEventListener('keydown', onKeydown));
onBeforeUnmount(() => document.removeEventListener('keydown', onKeydown));
</script>

<template>
    <section class="gs-section">
        <div class="gs-head home-wrap">
            <div class="eyebrow">Find your level</div>
            <h2>Five grades. <em>One clear path.</em></h2>
            <p>From your first open chord to rootless jazz voicings — every grade is a milestone, not just a label.</p>
        </div>

        <div class="gs-outer">
            <div
                class="gs-track"
                ref="trackRef"
                @mousedown="onMousedown"
                @mouseup="onMouseup"
                @mouseleave="onMouseleave"
                @touchstart.passive="onTouchstart"
                @touchend.passive="onTouchend"
            >
                <div
                    v-for="(g, i) in grades"
                    :key="g.slug"
                    class="gs-card"
                    :class="{ active: activeIdx === i }"
                    :style="`--gc: ${g.clr}`"
                    @click="activeIdx !== i && setActive(i)"
                >
                    <!-- Background image -->
                    <div class="gs-bg">
                        <img :src="gradeImageSrc(g.slug)" :alt="g.label" loading="lazy" />
                    </div>

                    <!-- Colour overlay -->
                    <div class="gs-overlay"></div>

                    <!-- Collapsed: vertical label -->
                    <div class="gs-label-vert">
                        <span class="vert-num">{{ g.n }}</span>
                        <span class="vert-name">{{ g.label }}</span>
                    </div>

                    <!-- Expanded: content -->
                    <div class="gs-content">
                        <div class="gs-badge">
                            <span class="gs-pip"></span>
                            Grade {{ g.n }} · {{ g.label }}
                        </div>
                        <h3 class="gs-title">
                            {{ g.title }}<br><em>{{ g.titleEm }}</em>
                        </h3>
                        <p class="gs-blurb">{{ g.blurb }}</p>
                        <div class="gs-chips">
                            <span v-for="chord in g.chords" :key="chord" class="gs-chip">{{ chord }}</span>
                        </div>
                        <Link href="/grades" class="gs-cta">{{ g.cta }} →</Link>
                    </div>
                </div>
            </div>

        </div>

        <!-- Controls -->
        <div class="gs-controls">
            <button
                class="gs-arrow"
                :disabled="activeIdx === 0"
                @click="setActive(activeIdx - 1)"
                aria-label="Previous grade"
            >←</button>

            <div class="gs-dots">
                <button
                    v-for="(g, i) in grades"
                    :key="g.slug"
                    class="gs-dot"
                    :class="{ active: activeIdx === i }"
                    @click="setActive(i)"
                    :aria-label="`Grade ${g.n}: ${g.label}`"
                ></button>
            </div>

            <button
                class="gs-arrow"
                :disabled="activeIdx === grades.length - 1"
                @click="setActive(activeIdx + 1)"
                aria-label="Next grade"
            >→</button>
        </div>
    </section>
</template>

<style scoped>
/* ── Section head ── */
.gs-section {
    padding: 84px 0 64px;
}
.gs-head {
    text-align: center;
    max-width: 620px;
    margin: 0 auto 48px;
}
.gs-head h2 {
    font-family: var(--font-heading);
    font-weight: 600;
    font-size: clamp(2rem, 3.6vw, 2.8rem);
    letter-spacing: -.02em;
    margin-bottom: 14px;
}
.gs-head h2 em {
    font-style: italic;
    background: var(--clr-gradient);
    -webkit-background-clip: text;
    -webkit-text-fill-color: transparent;
    background-clip: text;
    padding-right: .08em;
    margin-right: -.08em;
}
.gs-head p {
    color: var(--clr-text-dim);
    font-size: 1.05rem;
}

/* ── Outer wrapper — no overflow:hidden so scrollIntoView works ── */
.gs-outer {
    position: relative;
    max-width: 1200px;
    margin: 0 auto;
    padding: 0 24px;
}

/* ── Track ── */
.gs-track {
    display: flex;
    gap: 14px;
    align-items: stretch;
    cursor: default;
    user-select: none;
}
.gs-track.grabbing { cursor: grabbing; }

/* ── Cards ── */
.gs-card {
    flex: 1 1 0;       /* collapsed cards share remaining space equally */
    min-width: 100px;
    max-width: 120px;
    position: relative;
    height: 460px;
    border-radius: 16px;
    overflow: hidden;
    cursor: pointer;
    transition: flex .52s cubic-bezier(.3,1,.3,1), max-width .52s cubic-bezier(.3,1,.3,1), box-shadow .4s ease;
}
.gs-card.active {
    flex: 0 0 480px;
    max-width: 480px;
    cursor: default;
    box-shadow: 0 12px 40px rgba(0,0,0,.18);
}
.gs-card:not(.active):hover {
    max-width: 140px;
}

/* ── Background image ── */
.gs-bg {
    position: absolute;
    inset: 0;
    background: color-mix(in srgb, var(--gc) 40%, #1a1a2e);
    transition: transform .52s cubic-bezier(.3,1,.3,1);
}
.gs-bg img {
    width: 100%;
    height: 100%;
    object-fit: cover;
    display: block;
}
.gs-card.active .gs-bg {
    transform: scale(1.03);
}

/* ── Colour overlay ── */
.gs-overlay {
    position: absolute;
    inset: 0;
    background: linear-gradient(
        160deg,
        color-mix(in srgb, var(--gc) 85%, #000) 0%,
        color-mix(in srgb, var(--gc) 60%, #000) 100%
    );
    opacity: .82;
    transition: opacity .4s ease;
    pointer-events: none;
}
.gs-card.active .gs-overlay { opacity: .55; }

/* ── Collapsed vertical label ── */
.gs-label-vert {
    position: absolute;
    inset: 0;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: flex-end;
    padding-bottom: 28px;
    gap: 10px;
    opacity: 1;
    transition: opacity .2s ease;
    pointer-events: none;
}
.gs-card.active .gs-label-vert {
    opacity: 0;
}
.vert-num {
    font-family: var(--font-heading);
    font-size: 2rem;
    font-weight: 600;
    color: #fff;
    line-height: 1;
}
.vert-name {
    font-size: 1rem;
    font-weight: 700;
    color: rgba(255,255,255,.85);
    letter-spacing: .08em;
    text-transform: uppercase;
    writing-mode: vertical-rl;
    text-orientation: mixed;
    transform: rotate(180deg);
}

/* ── Expanded content ── */
.gs-content {
    position: absolute;
    inset: 0;
    display: flex;
    flex-direction: column;
    justify-content: flex-end;
    padding: 32px 28px;
    min-width: 480px;
    opacity: 0;
    transform: translateY(10px);
    transition: opacity .3s ease .15s, transform .3s ease .15s;
    pointer-events: none;
}
.gs-card.active .gs-content {
    opacity: 1;
    transform: none;
    pointer-events: auto;
}

.gs-badge {
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: .72rem;
    font-weight: 700;
    letter-spacing: .1em;
    text-transform: uppercase;
    color: rgba(255,255,255,.7);
    margin-bottom: 12px;
}
.gs-pip {
    width: 8px;
    height: 8px;
    border-radius: 50%;
    background: var(--gc);
    flex-shrink: 0;
}

.gs-title {
    font-family: var(--font-heading);
    font-size: clamp(1.4rem, 2.2vw, 1.8rem);
    font-weight: 600;
    color: #fff;
    line-height: 1.25;
    letter-spacing: -.02em;
    margin-bottom: 14px;
}
.gs-title em {
    font-style: italic;
    color: rgba(255,255,255,.85);
}

.gs-blurb {
    font-size: .92rem;
    line-height: 1.6;
    color: rgba(255,255,255,.8);
    margin-bottom: 20px;
    max-width: 360px;
}

.gs-chips {
    display: flex;
    flex-wrap: wrap;
    gap: 6px;
    margin-bottom: 24px;
}
.gs-chip {
    font-size: .78rem;
    font-weight: 600;
    color: #fff;
    padding: 4px 10px;
    border-radius: 20px;
    background: rgba(255,255,255,.15);
    border: 1px solid rgba(255,255,255,.25);
    backdrop-filter: blur(4px);
}

.gs-cta {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    font-size: .9rem;
    font-weight: 700;
    color: #fff;
    text-decoration: none;
    padding: 10px 22px;
    border-radius: 8px;
    background: rgba(255,255,255,.18);
    border: 1px solid rgba(255,255,255,.3);
    backdrop-filter: blur(4px);
    align-self: flex-start;
    transition: background .15s;
}
.gs-cta:hover { background: rgba(255,255,255,.28); }

/* ── Controls ── */
.gs-controls {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 20px;
    margin-top: 28px;
}

.gs-arrow {
    width: 36px;
    height: 36px;
    border-radius: 50%;
    border: 1.5px solid var(--clr-line);
    background: transparent;
    color: var(--clr-text);
    font-size: 1rem;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: border-color .15s, color .15s;
}
.gs-arrow:hover:not(:disabled) { border-color: var(--clr-accent); color: var(--clr-accent); }
.gs-arrow:disabled { opacity: .3; cursor: default; }

.gs-dots {
    display: flex;
    gap: 8px;
    align-items: center;
}
.gs-dot {
    width: 8px;
    height: 8px;
    border-radius: 20px;
    background: var(--clr-line);
    border: none;
    cursor: pointer;
    transition: width .3s ease, background .3s ease;
    padding: 0;
}
.gs-dot.active {
    width: 22px;
    background: var(--clr-gradient);
}

/* ── Mobile ── */
@media (max-width: 700px) {
    .gs-card.active { flex: 0 0 calc(100vw - 80px); max-width: calc(100vw - 80px); }
}

/* ── Reduced motion ── */
@media (prefers-reduced-motion: reduce) {
    .gs-card,
    .gs-bg,
    .gs-content,
    .gs-label-vert,
    .gs-overlay { transition: none !important; }
}
</style>
