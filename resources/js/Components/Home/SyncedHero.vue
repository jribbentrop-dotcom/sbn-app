<script setup lang="ts">
import SyncedPlayer from '../SyncedPlayer/SyncedPlayer.vue';
import type { ChordDiagramData } from '../Library/ChordDiagram.vue';
import type { RhythmPatternData } from '../Library/RhythmPattern.vue';

defineProps<{
    progression?: ChordDiagramData[];
    rhythmPattern?: RhythmPatternData;
    barsPerChord?: number;
}>();
</script>

<template>
    <div class="synced-hero sbn-synced-hero-card">
        <span class="demo-tag">Live · play-along</span>
        <SyncedPlayer
            :progression="progression"
            :rhythm-pattern="rhythmPattern"
            :bars-per-chord="barsPerChord"
            :autoplay="true"
        />
    </div>
</template>

<style scoped>
.synced-hero {
    position: relative;
    /* overflow:visible so .demo-tag (top:-12px) is not clipped */
    /* Card frame (border, shadow, radius) in sbn-synced-hero-card in sbn-design-system.css */
}

.demo-tag {
    position: absolute;
    top: -12px; left: 24px;
    background: var(--clr-fifth, var(--clr-accent-2));
    color: #fff;
    font-size: .72rem;
    font-weight: 700;
    letter-spacing: .06em;
    text-transform: uppercase;
    padding: 5px 12px;
    border-radius: 8px;
    z-index: 1;
}
</style>
