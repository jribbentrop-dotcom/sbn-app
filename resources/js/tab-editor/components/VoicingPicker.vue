<template>
  <div v-if="open" class="sbn-ve-voicing-picker">
    <!-- Context panel / modal for picking fretboard voicings -->
    <div class="sbn-ve-vp-header">
      <h4>Select Voicing: {{ chordName }}</h4>
      <button @click="close">Close</button>
    </div>
    <div class="sbn-ve-vp-body">
      <div v-if="loading">Loading voicings...</div>
      <div v-else>
        <!-- Voicing results grid -->
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, watch } from 'vue';
import { fetchVoicings } from '../utils/voicingApi';

/**
 * VoicingPicker
 * Context panel / modal that searches and lists fretboard voicings for a specific chord.
 */

const props = defineProps({
  open: { type: Boolean, default: false },
  chordName: { type: String, default: '' },
  voicingKey: { type: String, default: '' }
});

const emit = defineEmits(['update:open', 'select-voicing']);

const loading = ref(false);
const results = ref([]);

watch(() => props.open, async (newVal) => {
  if (newVal && props.chordName) {
    loading.value = true;
    try {
      const data = await fetchVoicings({ root: props.chordName }); // basic placeholder test
      if (data && data.results) {
        results.value = data.results;
      }
    } finally {
      loading.value = false;
    }
  }
});

const close = () => {
  emit('update:open', false);
};
</script>
