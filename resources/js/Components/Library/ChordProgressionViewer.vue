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
}

export interface ChordProgressionViewerProps {
    chords: ProgressionChord[];
    interactive?: boolean;
    compact?: boolean;
    showFlowArrows?: boolean;
}

const props = withDefaults(defineProps<ChordProgressionViewerProps>(), {
    interactive: true,
    compact: false,
    showFlowArrows: true,
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
    <div class="sbn-prog-viewer" :class="{ 'sbn-prog-viewer--compact': compact }">
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
                    <span class="sbn-prog-viewer-tile-name" v-html="chord.chordName" />
                    <ChordDiagram :chord="chord.diagramData" size="xs" :showName="false" :showMeta="false" />
                </button>

                <div v-else class="sbn-prog-viewer-tile-static">
                    <span class="sbn-prog-viewer-tile-name" v-html="chord.chordName" />
                    <ChordDiagram
                        v-if="chord.diagramData"
                        :chord="chord.diagramData"
                        size="xs"
                        :showName="false"
                        :showMeta="false"
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
    background: var(--clr-surface-2);
    border: 1px solid var(--clr-border);
    border-radius: var(--radius);
    padding: 16px;
    overflow: visible;
}

.sbn-prog-viewer--compact {
    --tile-size: 90px;
    padding: 12px;
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

/* Global play button - centered circle, slightly bigger, blue theme */
.sbn-prog-viewer-global-play {
    width: 34px;
    height: 34px;
    flex-shrink: 0;
    border: 1px solid var(--clr-border);
    background: var(--clr-white);
    color: var(--clr-text-muted);
    cursor: pointer;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: all 0.15s var(--ease);
    padding: 0;
    margin-right: 8px;
    align-self: center;
}

.sbn-prog-viewer-global-play:hover {
    background: #3b82f6;
    color: #fff;
    border-color: #3b82f6;
}

.sbn-prog-viewer-global-play.is-playing {
    background: #3b82f6;
    color: #fff;
    border-color: #3b82f6;
    transform: scale(0.95);
}

/* Individual chord item */
.sbn-prog-viewer-item {
    display: flex;
    align-items: center;
    position: relative;
    flex-shrink: 0;
}

/* Chord tile button - matches chord card style with blue hover */
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
    border-color: #3b82f6; /* Blue highlight to distinguish from chord cards */
    transform: translateY(-2px);
}

/* Playing state - blue highlight animation */
.sbn-prog-viewer-item.is-playing .sbn-prog-viewer-tile {
    border-color: #3b82f6;
    background: rgba(59, 130, 246, 0.08);
    animation: pulse-tile 0.5s ease;
}

@keyframes pulse-tile {
    0%, 100% { box-shadow: 0 0 0 0 rgba(59, 130, 246, 0.2); }
    50% { box-shadow: 0 0 0 4px rgba(59, 130, 246, 0.2); }
}

/* Chord name */
.sbn-prog-viewer-tile-name {
    font-size: 0.85em;
    font-weight: 600;
    color: var(--clr-text);
    text-align: center;
    font-family: var(--font-chord, serif);
    line-height: 1.2;
    white-space: nowrap;
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

    .sbn-prog-viewer-arrow {
        display: none;
    }
}
</style>
