<script setup lang="ts">
import SyncedPlayer, { type LeadsheetBar } from '../SyncedPlayer/SyncedPlayer.vue';
import type { RhythmPatternData } from '../Library/RhythmPattern.vue';

defineProps<{
    bars?: LeadsheetBar[];
    rhythmPattern?: RhythmPatternData;
    muted?: boolean;
    rhythmCaption?: string;
    rhythmLink?: string;
    citation?: string;
    startChordName?: string;
}>();
</script>

<template>
    <div class="synced-hero sbn-synced-hero-card">
        <span class="demo-tag">Live · play-along</span>
        <SyncedPlayer
            :bars="bars"
            :rhythm-pattern="rhythmPattern"
            :autoplay="false"
            :muted="muted"
            :loop="true"
            :rhythm-caption="rhythmCaption"
            :rhythm-link="rhythmLink"
            :citation="citation"
            :start-chord-name="startChordName"
        />
    </div>
</template>

<style scoped>
.synced-hero {
    position: relative;
    width: 100%;
    max-width: 480px;
    margin-left: auto;
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
