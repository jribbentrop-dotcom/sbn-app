<template>
    <div class="sbn-transport-bar" v-if="totalBeats > 0">
        <!-- ⏹ Stop/Reset: always visible, seeks back to beat 0 -->
        <button
            class="sbn-transport-stop"
            @click="$emit('stop')"
            title="Stop and return to start (Esc)"
        >⏹</button>

        <!-- ▶/⏸ Play / Pause toggle -->
        <button
            class="sbn-transport-play"
            :class="{ 'is-playing': isPlaying }"
            @click="$emit('toggle')"
            :title="isPlaying ? 'Pause (Space)' : currentBeat > 0 ? 'Resume (Space)' : 'Play (Space)'"
        >{{ isPlaying ? '⏸' : currentBeat > 0 ? '▶ Resume' : '▶ Play' }}</button>

        <div class="sbn-transport-seek">
            <input
                type="range"
                min="0"
                :max="totalBeats"
                step="0.25"
                :value="displayBeat"
                @mousedown="onSeekStart"
                @touchstart.passive="onSeekStart"
                @input="onSeekInput"
                @change="onSeekCommit"
            />
            <span class="sbn-transport-time">{{ barLabel(displayBeat) }} / {{ barLabel(totalBeats) }}</span>
        </div>

        <div class="sbn-transport-tempo">
            <span>♩</span>
            <input
                type="range"
                min="40"
                max="200"
                step="1"
                :value="tempo"
                @change="$emit('tempo-change', +$event.target.value)"
            />
            <span class="sbn-transport-bpm">{{ tempo }}</span>
        </div>

        <!-- Mixer: shown in chord view (chord + rhythm) or tab view (tab only) -->
        <div v-if="showMixer" class="sbn-transport-mixer">
            <div v-if="viewMode === 'chords'" class="sbn-transport-mixer-track">
                <span class="sbn-transport-mixer-label">♩ Chords</span>
                <input type="range" min="0" max="1" step="0.05"
                    :value="volumeChord"
                    @input="$emit('volume-chord', +$event.target.value)" />
            </div>
            <div v-if="viewMode === 'chords'" class="sbn-transport-mixer-track">
                <span class="sbn-transport-mixer-label">🥁 Rhythm</span>
                <input type="range" min="0" max="1" step="0.05"
                    :value="volumeRhythm"
                    @input="$emit('volume-rhythm', +$event.target.value)" />
            </div>
            <div v-if="viewMode === 'tab'" class="sbn-transport-mixer-track">
                <span class="sbn-transport-mixer-label">🎸 Tab</span>
                <input type="range" min="0" max="1" step="0.05"
                    :value="volumeTab"
                    @input="$emit('volume-tab', +$event.target.value)" />
            </div>
        </div>
    </div>
</template>

<script setup>
import { ref, computed } from 'vue';

const props = defineProps({
    isPlaying:      { type: Boolean, default: false },
    currentBeat:    { type: Number,  default: 0 },
    totalBeats:     { type: Number,  default: 0 },
    tempo:          { type: Number,  default: 120 },
    beatsPerMeasure:{ type: Number,  default: 4 },
    viewMode:       { type: String,  default: 'chords' },
    volumeChord:    { type: Number,  default: 1.0 },
    volumeRhythm:   { type: Number,  default: 1.0 },
    volumeTab:      { type: Number,  default: 1.0 },
    showMixer:      { type: Boolean, default: false },
});

const emit = defineEmits(['toggle', 'stop', 'seek', 'tempo-change', 'volume-chord', 'volume-rhythm', 'volume-tab']);

// While the user is dragging, freeze the live beat value.
const _seeking   = ref(false);
const _seekValue = ref(0);

const displayBeat = computed(() =>
    _seeking.value ? _seekValue.value : props.currentBeat
);

function onSeekStart(e) {
    _seeking.value   = true;
    _seekValue.value = +e.target.value;
}

function onSeekInput(e) {
    _seekValue.value = +e.target.value;
}

function onSeekCommit(e) {
    _seekValue.value = +e.target.value;
    _seeking.value   = false;
    emit('seek', _seekValue.value);
}

/** Format a beat position as "bar:beat" (1-indexed). */
function barLabel(beat) {
    const bpm  = props.beatsPerMeasure || 4;
    const bar  = Math.floor(beat / bpm) + 1;
    const b    = Math.floor(beat % bpm) + 1;
    return `${bar}:${b}`;
}
</script>
