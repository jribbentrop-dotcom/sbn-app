<script setup lang="ts">
import { ref, onBeforeUnmount } from 'vue';
import ChordDiagram from './ChordDiagram.vue';
import type { ChordDiagramData } from './ChordDiagram.vue';
import { getAudioEngine } from '../../audio/engine/AudioEngine.js';
import { chordDiagramToEvents } from '../../audio/adapters/chordDiagramToEvents.js';

export interface ProgressionChord {
    chordName: string;
    diagramData: ChordDiagramData | null;
    beats?: number;    // Duration in beats (default 4)
    slug?: string | null;
    numeral?: string;
}

export interface ChordProgressionViewerProps {
    chords: ProgressionChord[];
    interactive?: boolean;
    compact?: boolean;
    showFlowArrows?: boolean;
    /** Tint colour for cells and highlights. Defaults to var(--clr-accent). */
    color?: string | null;
    /** Apply SBN vintage card styling (background, border-accent). Defaults to false. */
    vintageCard?: boolean;
    
    // Metadata props
    name?: string;
    category?: string;
    numerals?: string;
    keyLabel?: string;
}

const props = withDefaults(defineProps<ChordProgressionViewerProps>(), {
    interactive: true,
    compact: false,
    showFlowArrows: true,
    color: null,
    vintageCard: false,
    name: '',
    category: '',
    numerals: '',
    keyLabel: '',
});

const isPlayingAll = ref(false);
const currentPlayingIndex = ref<number | null>(null);

const engine = getAudioEngine();

const unsubEnded = engine.on('ended', () => {
    if (isPlayingAll.value && currentPlayingIndex.value !== null) {
        playNextChord();
    } else {
        stopPlayback();
    }
});

onBeforeUnmount(() => {
    unsubEnded();
});

async function playChordAtIndex(index: number) {
    const chord = props.chords[index];
    if (!chord?.diagramData) return;

    await engine.init({ samplesBaseUrl: '/audio/rhythm-samples/' });

    // Set faster tempo for progression playback
    engine.setTempo(180);

    const beats = chord.beats || 0.5;
    const events = chordDiagramToEvents(
        { id: chord.diagramData.id, diagram_data: chord.diagramData.diagram_data },
        { durationBeats: beats, staggerBeats: 0.08 },
    );

    if (!events.length) return;

    currentPlayingIndex.value = index;
    engine.load(events);
    engine.play();
}

async function playNextChord() {
    if (currentPlayingIndex.value === null) {
        stopPlayback();
        return;
    }

    const nextIndex = currentPlayingIndex.value + 1;
    if (nextIndex >= props.chords.length) {
        stopPlayback();
        return;
    }

    const nextChord = props.chords[nextIndex];
    if (nextChord?.diagramData) {
        await playChordAtIndex(nextIndex);
    } else {
        currentPlayingIndex.value = nextIndex;
        playNextChord();
    }
}

async function playProgression() {
    if (props.chords.length === 0) return;
    isPlayingAll.value = true;

    if (currentPlayingIndex.value === null || currentPlayingIndex.value >= props.chords.length - 1) {
        currentPlayingIndex.value = 0;
    }

    await playChordAtIndex(currentPlayingIndex.value);
}

function stopPlayback() {
    isPlayingAll.value = false;
    currentPlayingIndex.value = null;
    engine.stop();
}

function togglePlayback() {
    if (isPlayingAll.value) {
        stopPlayback();
    } else {
        playProgression();
    }
}

function canPlayAll(): boolean {
    return props.chords.some(c => c.diagramData !== null);
}
</script>

<template>
    <div 
        class="sbn-prog-viewer" 
        :class="{ 
            'sbn-prog-viewer--compact': compact,
            'sbn-vintage-card': vintageCard 
        }"
        :style="color ? { '--prog-color': color } : {}"
    >
        <!-- Metadata Header -->
        <div v-if="name || category || keyLabel || numerals" class="sbn-prog-viewer-header">
            <div class="sbn-prog-viewer-info">
                <h4 v-if="name" class="sbn-prog-viewer-name" v-html="name" />
                <div v-if="category || keyLabel" class="sbn-prog-viewer-sub">
                    <span v-if="category" class="sbn-prog-viewer-category" :style="{ color: color || 'var(--clr-accent)' }">
                        {{ category }}
                    </span>
                    <span v-if="category && keyLabel" class="sbn-prog-viewer-sep">•</span>
                    <span v-if="keyLabel" class="sbn-prog-viewer-key">{{ keyLabel }}</span>
                </div>
            </div>
            <div v-if="numerals" class="sbn-prog-viewer-numerals-badge">
                <span class="sbn-badge-label">PRO</span>
                <span class="sbn-badge-text">{{ numerals.replace(/,/g, ' – ') }}</span>
            </div>
        </div>

        <!-- Chord strip with inline play button -->
        <div class="sbn-prog-viewer-strip">
            <!-- Global play button - chord card style -->
            <button
                v-if="interactive && canPlayAll()"
                class="sbn-prog-viewer-global-play"
                :class="{ 'is-playing': isPlayingAll }"
                :aria-label="isPlayingAll ? 'Stop progression' : 'Play progression'"
                @click="togglePlayback"
            >
                <svg v-if="isPlayingAll" width="14" height="14" viewBox="0 0 24 24" fill="currentColor">
                    <rect x="6" y="4" width="4" height="16"/>
                    <rect x="14" y="4" width="4" height="16"/>
                </svg>
                <svg v-else width="14" height="14" viewBox="0 0 24 24" fill="currentColor">
                    <path d="M8 5v14l11-7z"/>
                </svg>
            </button>

            <div
                v-for="(chord, index) in chords"
                :key="index"
                class="sbn-prog-viewer-item"
                :class="{
                    'is-playing': currentPlayingIndex === index && isPlayingAll,
                    'is-interactive': interactive && !!chord.diagramData,
                    'is-placeholder': !chord.diagramData,
                }"
            >
                <!-- Chord tile -->
                <button
                    v-if="interactive && chord.diagramData"
                    class="sbn-prog-viewer-tile"
                    :aria-label="`Play ${chord.chordName}`"
                    @click="playChordAtIndex(index)"
                >
                    <div class="sbn-prog-viewer-tile-header">
                        <span class="sbn-prog-viewer-tile-name" v-html="chord.chordName" />
                        <span v-if="chord.numeral" class="sbn-prog-viewer-tile-numeral">{{ chord.numeral }}</span>
                    </div>
                    <ChordDiagram :chord="chord.diagramData" size="xs" :showName="false" :showMeta="false" :dot-color="color || 'var(--clr-accent)'" />
                </button>

                <div v-else class="sbn-prog-viewer-tile-static">
                    <div class="sbn-prog-viewer-tile-header">
                        <span class="sbn-prog-viewer-tile-name" v-html="chord.chordName" />
                        <span v-if="chord.numeral" class="sbn-prog-viewer-tile-numeral">{{ chord.numeral }}</span>
                    </div>
                    <ChordDiagram
                        v-if="chord.diagramData"
                        :chord="chord.diagramData"
                        size="xs"
                        :showName="false"
                        :showMeta="false"
                        :dot-color="color || 'var(--clr-accent)'"
                    />
                    <div v-else class="sbn-prog-viewer-placeholder">?</div>
                </div>

            </div>
        </div>
    </div>
</template>

<style scoped>
.sbn-prog-viewer {
    --tile-size: 110px;
    --prog-color: var(--clr-accent);
    background: var(--clr-white);
    border: 1px solid var(--clr-border);
    border-radius: var(--radius);
    padding: 16px;
    overflow: visible;
    transition: all 0.3s ease;
}

.sbn-prog-viewer--compact {
    --tile-size: 90px;
    padding: 12px;
}

/* Metadata Header */
.sbn-prog-viewer-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    margin-bottom: 20px;
    padding-bottom: 16px;
    border-bottom: 1px solid var(--clr-surface-2);
}

.sbn-prog-viewer-info {
    display: flex;
    flex-direction: column;
    gap: 4px;
}

.sbn-prog-viewer-name {
    margin: 0;
    font-size: 1.1rem;
    font-weight: 700;
    color: var(--clr-text);
    font-family: var(--font-heading, sans-serif);
}

.sbn-prog-viewer-sub {
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 11px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.sbn-prog-viewer-category {
    font-weight: 800;
}

.sbn-prog-viewer-key {
    color: var(--clr-text-muted);
}

.sbn-prog-viewer-sep {
    color: var(--clr-border);
    font-weight: 400;
}

.sbn-prog-viewer-numerals-badge {
    display: flex;
    align-items: center;
    background: var(--clr-surface-2);
    border-radius: 4px;
    overflow: hidden;
    font-size: 11px;
    font-weight: 700;
    border: 1px solid var(--clr-border);
}

.sbn-badge-label {
    background: var(--clr-text);
    color: var(--clr-white);
    padding: 2px 6px;
}

.sbn-badge-text {
    padding: 2px 8px;
    color: var(--clr-text);
    letter-spacing: 0.5px;
}

/* Vintage Card Variant */
.sbn-prog-viewer.sbn-vintage-card {
  background: var(--clr-white);
  border: 1px solid var(--clr-border);
  border-right: 3px solid var(--prog-color);
  border-bottom: 3px solid var(--prog-color);
  padding: 20px;
}

.sbn-prog-viewer.sbn-vintage-card:hover {
  box-shadow: 3px 3px 0 var(--prog-color);
  transform: translate(-1px, -1px);
  border-color: var(--prog-color);
}

/* Strip layout with inline play button */
.sbn-prog-viewer-strip {
    display: flex;
    align-items: flex-start;
    gap: 8px;
    overflow-x: auto;
    padding: 8px 4px 4px;
    overflow: visible;
}

/* Global play button - Circular & Premium (Matching RhythmPattern) */
.sbn-prog-viewer-global-play {
    width: 38px;
    height: 38px;
    flex-shrink: 0;
    border: 1px solid var(--clr-border);
    background: var(--clr-white);
    color: var(--prog-color);
    cursor: pointer;
    border-radius: 50%;
    display: grid;
    place-items: center;
    transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
    padding: 0;
    margin-right: 12px;
    align-self: center;
}

.sbn-prog-viewer-global-play:hover {
    color: var(--prog-color);
    border-color: var(--prog-color);
    transform: scale(1.1);
}

.sbn-prog-viewer-global-play.is-playing {
    background: var(--prog-color);
    color: var(--clr-white);
    border-color: var(--prog-color);
    box-shadow: 0 0 0 4px color-mix(in srgb, var(--prog-color) 20%, transparent);
}

/* Individual chord item */
.sbn-prog-viewer-item {
    display: flex;
    align-items: center;
    position: relative;
    flex-shrink: 0;
}

/* Chord tile button */
.sbn-prog-viewer-tile {
    width: var(--tile-size);
    background: var(--clr-white);
    border: 1px solid var(--clr-border);
    border-radius: var(--radius);
    padding: 12px 10px 10px;
    cursor: pointer;
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 6px;
    transition: border-color 0.2s var(--ease), transform 0.2s var(--ease);
    overflow: visible;
}

.sbn-prog-viewer-tile:hover {
    border-color: var(--prog-color);
    transform: translateY(-2px);
}

/* Playing state */
.sbn-prog-viewer-item.is-playing .sbn-prog-viewer-tile {
    border-color: var(--prog-color);
    background: color-mix(in srgb, var(--prog-color) 5%, transparent);
    animation: pulse-tile 0.5s ease;
}

@keyframes pulse-tile {
    0%, 100% { box-shadow: 0 0 0 0 color-mix(in srgb, var(--prog-color) 20%, transparent); }
    50% { box-shadow: 0 0 0 4px color-mix(in srgb, var(--prog-color) 20%, transparent); }
}

/* Chord name */
.sbn-prog-viewer-tile-header {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 2px;
}

.sbn-prog-viewer-tile-name {
    font-size: 0.85em;
    font-weight: 600;
    color: var(--clr-text);
    text-align: center;
    font-family: var(--font-chord, serif);
    line-height: 1.2;
    white-space: nowrap;
}

.sbn-prog-viewer-tile-numeral {
    font-size: 10px;
    font-weight: 700;
    color: var(--clr-text-muted);
    text-transform: uppercase;
    letter-spacing: 0.5px;
    opacity: 0.8;
}

/* Static tile (non-interactive) */
.sbn-prog-viewer-tile-static {
    width: var(--tile-size);
    background: var(--clr-white);
    border: 1px solid var(--clr-border);
    border-radius: var(--radius);
    padding: 12px 10px 10px;
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 6px;
    overflow: visible;
}

/* Placeholder */
.sbn-prog-viewer-placeholder {
    width: 100%;
    height: calc(var(--tile-size) * 0.75);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.4em;
    color: var(--clr-text-muted);
    background: var(--clr-surface-2);
    border-radius: 4px;
}

/* Responsive */
@media (max-width: 640px) {
    .sbn-prog-viewer {
        --tile-size: 90px;
        padding: 12px;
    }

    .sbn-prog-viewer-strip {
        gap: 6px;
    }
}
</style>
