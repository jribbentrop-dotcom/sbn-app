<script setup lang="ts">
import { computed, ref, onBeforeUnmount } from 'vue';
import { router } from '@inertiajs/vue3';
import ChordDiagram from './ChordDiagram.vue';
import type { ChordDiagramData } from './ChordDiagram.vue';
import { getAudioEngine } from '../../audio/engine/AudioEngine.js';
import { chordDiagramToEvents } from '../../audio/adapters/chordDiagramToEvents.js';
import { getSharedNylon } from '../../audio/engine/voices/sharedNylon.js';
import { chordShowUrl } from '../../composables/useChordUrl';
import { formatChordNameHtml } from '../../composables/useChordName';

interface Props {
    chord: ChordDiagramData;
    mini?: boolean;
    detail?: boolean;
    showRoot?: boolean;
    onChordClick?: (() => void) | null;
    noNav?: boolean;
    /** Navigate in the same tab via Inertia instead of opening a new tab. */
    sameTab?: boolean;
    /** Override chord-dot fill colour (forwarded to ChordDiagram). */
    dotColor?: string;
}

const props = withDefaults(defineProps<Props>(), { mini: false, detail: false, showRoot: true, onChordClick: null, noNav: false, sameTab: false, dotColor: undefined });

const formattedName = computed(() =>
    formatChordNameHtml(props.chord as any, props.showRoot)
);

const inversionLabel = computed(() => {
    if (!props.chord.inversion || props.chord.inversion === 'root') return '';
    return props.chord.inversion_label;
});

const popularityTier = computed(() => {
    const p = props.chord.popularity ?? 0;
    if (p >= 11) return { tier: 'iconic',     label: 'Iconic' };
    if (p >= 6)  return { tier: 'essential',  label: 'Essential' };
    if (p >= 3)  return { tier: 'common',     label: 'Common' };
    if (p >= 1)  return { tier: 'occasional', label: 'Rare' };
    return null;
});

const difficultyStars = computed(() => {
    const d = props.chord.difficulty ?? 0;
    if (!d) return [];
    return Array.from({ length: 5 }, (_, i) => i < d);
});

const isPlaying = ref(false);
const engine = getAudioEngine();
const nylon = getSharedNylon();

const unsubEnded       = engine.on('ended',       () => { isPlaying.value = false; });
const unsubPlayStarted = engine.on('playStarted', () => { isPlaying.value = false; });

onBeforeUnmount(() => {
    unsubEnded();
    unsubPlayStarted();
});

const cardRef = ref<HTMLElement | null>(null);

function handleCardClick() {
    if (props.noNav) return;
    if (props.onChordClick) {
        props.onChordClick();
        return;
    }
    if (props.chord.slug) {
        // chordShowUrl is the single source of truth for chord detail URLs —
        // it handles alias matches, transposed roots and rootless voicings that
        // a naive slug+root concat would get wrong.
        const url = chordShowUrl(props.chord as any);
        if (props.sameTab) {
            router.visit(url);
        } else {
            window.open(url, '_blank');
        }
    }
}

async function playChord() {
    await engine.init({ samplesBaseUrl: '/audio/rhythm-samples/' });
    await nylon.init('/audio/nylon/');
    nylon.releaseAll();

    const events = chordDiagramToEvents(
        { id: props.chord.id, diagram_data: props.chord.diagram_data },
        { startBeat: 0, durationBeats: 4, staggerBeats: 0.28 },
    );
    if (!events.length) return;

    isPlaying.value = true;

    // Schedule via NylonSampler. staggerBeats=0.28 @ 120 BPM = 140ms per string.
    const BEAT_SEC = 60 / 120;
    const STAGGER_MS = 0.28 * BEAT_SEC * 1000; // ~140ms
    const { now } = await import('tone');
    const t0 = now();

    events.forEach((ev: any) => {
        const timeSec = t0 + ev.time * BEAT_SEC;
        const durSec  = ev.duration * BEAT_SEC;
        nylon.trigger(ev.pitch, timeSec, durSec, 0.72);
    });

    // Animate SVG dots in sync with arpeggio
    events.forEach((ev: any, i: number) => {
        const stringNum = ev.stringNum;
        if (!stringNum) return;
        setTimeout(() => {
            const dot = cardRef.value?.querySelector(
                `.sbn-svg-dot[data-string="${stringNum}"]`
            );
            if (!dot) return;
            dot.classList.add('sbn-dot-ping');
            setTimeout(() => dot.classList.remove('sbn-dot-ping'), 500);
        }, i * STAGGER_MS);
    });

    // Clear isPlaying after last note + sustain
    setTimeout(() => { isPlaying.value = false; }, (events.length - 1) * STAGGER_MS + 2000);
}
</script>

<template>
    <div
        ref="cardRef"
        class="sbn-chord-card"
        :class="{ 'sbn-chord-card--mini': mini, 'sbn-chord-card--detail': detail, 'sbn-chord-card--clickable': !!onChordClick || !!chord.slug }"
        @click="handleCardClick"
    >
        <!-- Chord name -->
        <div class="sbn-card-chord-name" v-html="formattedName" />

        <!-- Diagram -->
        <div class="sbn-card-diagram">
            <ChordDiagram :chord="chord" v-bind="dotColor ? { dotColor } : {}" />
            <button
                class="sbn-play-btn"
                :class="{ 'is-playing': isPlaying }"
                title="Play chord"
                aria-label="Play chord"
                @click.stop.prevent="playChord"
            >
                <svg width="18" height="18" viewBox="0 0 24 24" fill="currentColor">
                    <path d="M8 5v14l11-7z"/>
                </svg>
            </button>
        </div>

        <!-- Footer: popularity + difficulty -->
        <div class="sbn-card-footer">
            <div>
                <span
                    v-if="popularityTier"
                    class="sbn-card-pop"
                    :class="`sbn-pop-${popularityTier.tier}`"
                >{{ popularityTier.label }}</span>
            </div>
            <div>
                <span v-if="difficultyStars.length" class="sbn-card-diff">
                    <span
                        v-for="(filled, i) in difficultyStars"
                        :key="i"
                        class="sbn-diff-star"
                        :class="{ filled }"
                    >★</span>
                </span>
            </div>
        </div>
    </div>
</template>
