<template>
  <Teleport to="body">
    <div
      v-if="open"
      ref="pickerEl"
      class="sbn-ve-chord-picker"
      :style="{ position: 'fixed', top: top + 'px', left: left + 'px', zIndex: 9998 }"
      @click.stop
    >
      <!-- Inline chord name editor — root+quality picker comes in Step 5 -->
      <input
        ref="inputEl"
        type="text"
        class="sbn-ve-chord-picker-input"
        v-model="localValue"
        placeholder="e.g. Cmaj7"
        @keydown.enter.prevent="apply"
        @keydown.esc.prevent="close"
      />
      <button class="sbn-ve-chord-picker-ok" @click="apply">OK</button>
    </div>
  </Teleport>
</template>

<script setup>
import { ref, watch, nextTick, onMounted, onUnmounted } from 'vue';

/**
 * ChordPicker
 * Minimal inline text editor for the chord symbol.
 * Full root+quality picker (port of Alpine dropdown) is Step 5 scope.
 */

const props = defineProps({
  open:  { type: Boolean, default: false },
  top:   { type: Number,  default: 0 },
  left:  { type: Number,  default: 0 },
  value: { type: String,  default: '' },
  si:    { type: Number,  default: 0 },
  mi:    { type: Number,  default: 0 },  // used as gi (global index) by ChordGridView
  ci:    { type: Number,  default: 0 },
});

const emit = defineEmits(['update:open', 'apply']);

const localValue = ref('');
const inputEl    = ref(null);
const pickerEl   = ref(null);

// Pre-fill and auto-focus when opened
watch(() => props.open, async (newVal) => {
  if (newVal) {
    localValue.value = props.value;
    await nextTick();
    inputEl.value?.focus();
    inputEl.value?.select();
  }
});

// Click-outside to close
function onDocumentClick(e) {
  if (props.open && pickerEl.value && !pickerEl.value.contains(e.target)) {
    close();
  }
}

onMounted(() => document.addEventListener('mousedown', onDocumentClick, true));
onUnmounted(() => document.removeEventListener('mousedown', onDocumentClick, true));

const apply = () => {
  const trimmed = localValue.value.trim();
  if (trimmed) {
    emit('apply', { name: trimmed, si: props.si, mi: props.mi, ci: props.ci });
  }
  close();
};

const close = () => {
  emit('update:open', false);
};
</script>
