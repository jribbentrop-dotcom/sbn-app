<template>
  <Teleport to="body">
    <div
      v-if="open"
      ref="menuEl"
      class="sbn-context-menu sbn-chord-context-menu"
      :style="{ top: top + 'px', left: left + 'px' }"
      @click.stop
      @contextmenu.prevent
    >
      <!-- Chord slot operations -->
      <div class="sbn-cm-group">
        <button class="sbn-context-menu-item" @click="action('rename-chord')">
          <span class="sbn-cm-icon">✏️</span>
          <span class="sbn-context-menu-label">Rename chord…</span>
        </button>
        <button class="sbn-context-menu-item" @click="action('change-voicing')">
          <span class="sbn-cm-icon">🎸</span>
          <span class="sbn-context-menu-label">Change voicing…</span>
        </button>
        <button class="sbn-context-menu-item" @click="action('add-chord')">
          <span class="sbn-cm-icon">➕</span>
          <span class="sbn-context-menu-label">Add chord slot</span>
        </button>
        <button
          class="sbn-context-menu-item danger"
          @click="action('delete-chord')"
          :disabled="!hasChord"
          :class="{ disabled: !hasChord }"
        >
          <span class="sbn-cm-icon">🗑️</span>
          <span class="sbn-context-menu-label">Delete chord slot</span>
        </button>
      </div>

      <hr />

      <!-- Clipboard -->
      <div class="sbn-cm-group">
        <button class="sbn-context-menu-item" @click="action('copy-measure')">
          <span class="sbn-cm-icon">📋</span>
          <span class="sbn-context-menu-label">Copy bar</span>
        </button>
        <button class="sbn-context-menu-item" @click="action('cut-measure')">
          <span class="sbn-cm-icon">✂️</span>
          <span class="sbn-context-menu-label">Cut bar</span>
        </button>
        <button
          class="sbn-context-menu-item"
          @click="action('paste-measure')"
          :disabled="!hasClipboard"
          :class="{ disabled: !hasClipboard }"
        >
          <span class="sbn-cm-icon">📥</span>
          <span class="sbn-context-menu-label">Paste bar</span>
        </button>
      </div>

      <hr />

      <!-- Structural bar operations -->
      <div class="sbn-cm-group">
        <button class="sbn-context-menu-item" @click="action('insert-bar-before')">
          <span class="sbn-cm-icon">⬅️</span>
          <span class="sbn-context-menu-label">Insert bar before</span>
        </button>
        <button class="sbn-context-menu-item" @click="action('insert-bar-after')">
          <span class="sbn-cm-icon">➡️</span>
          <span class="sbn-context-menu-label">Insert bar after</span>
        </button>
        <button class="sbn-context-menu-item danger" @click="action('delete-bar')">
          <span class="sbn-cm-icon">🗑️</span>
          <span class="sbn-context-menu-label">Delete bar</span>
        </button>
      </div>

      <hr />

      <!-- Repeat / volta -->
      <div class="sbn-cm-group">
        <button class="sbn-context-menu-item" @click="action('toggle-repeat-start')">
          <span class="sbn-cm-icon">𝄆</span>
          <span class="sbn-context-menu-label">{{ hasRepeatStart ? 'Remove start repeat' : 'Start repeat' }}</span>
        </button>
        <button class="sbn-context-menu-item" @click="action('toggle-repeat-end')">
          <span class="sbn-cm-icon">𝄇</span>
          <span class="sbn-context-menu-label">{{ hasRepeatEnd ? 'Remove end repeat' : 'End repeat' }}</span>
        </button>
        <button class="sbn-context-menu-item" @click="action('set-volta-1')" :class="{ active: hasVolta(1) }">
          <span class="sbn-cm-icon">1.</span>
          <span class="sbn-context-menu-label">{{ hasVolta(1) ? 'Clear 1st ending' : 'Set 1st ending' }}</span>
        </button>
        <button class="sbn-context-menu-item" @click="action('set-volta-2')" :class="{ active: hasVolta(2) }">
          <span class="sbn-cm-icon">2.</span>
          <span class="sbn-context-menu-label">{{ hasVolta(2) ? 'Clear 2nd ending' : 'Set 2nd ending' }}</span>
        </button>
        <button class="sbn-context-menu-item" @click="action('set-volta-end')" v-if="contextData.volta && !contextData.voltaEnd">
          <span class="sbn-cm-icon">⌐</span>
          <span class="sbn-context-menu-label">End bracket here</span>
        </button>
        <button class="sbn-context-menu-item" @click="action('extend-volta-here')" v-if="!contextData.volta && nearVoltaEnd">
          <span class="sbn-cm-icon">⌐</span>
          <span class="sbn-context-menu-label">Extend bracket to here</span>
        </button>
        <button class="sbn-context-menu-item danger" @click="action('clear-volta')" v-if="contextData.volta">
          <span class="sbn-cm-icon">✕</span>
          <span class="sbn-context-menu-label">Clear volta bracket</span>
        </button>
      </div>

      <hr />

      <!-- Section operations -->
      <div class="sbn-cm-group">
        <button class="sbn-context-menu-item" @click="action('duplicate-section')">
          <span class="sbn-cm-icon">🔁</span>
          <span class="sbn-context-menu-label">Duplicate section</span>
        </button>
      </div>
    </div>
  </Teleport>
</template>

<script setup>
import { ref, computed, onMounted, onUnmounted } from 'vue';

const props = defineProps({
  open:         { type: Boolean, default: false },
  top:          { type: Number,  default: 0 },
  left:         { type: Number,  default: 0 },
  contextData:  { type: Object,  default: () => ({}) },
  hasClipboard: { type: Boolean, default: false },
  // True when the immediately preceding measure is the voltaEnd of a bracket,
  // so we can offer "Extend bracket to here".
  nearVoltaEnd: { type: Boolean, default: false },
});

const emit = defineEmits(['update:open', 'action']);

const menuEl = ref(null);

const hasChord = computed(() =>
  props.contextData?.chordName !== undefined && props.contextData?.chordName !== null
);

const hasRepeatStart = computed(() => !!props.contextData?.repeatStart);
const hasRepeatEnd   = computed(() => !!props.contextData?.repeatEnd);

function hasVolta(num) {
  return props.contextData?.volta?.number === num;
}

function action(id) {
  emit('action', id, props.contextData);
  close();
}

function close() {
  emit('update:open', false);
}

// Click-outside closes the menu (capture phase to beat other handlers)
function onDocClick(e) {
  if (props.open && menuEl.value && !menuEl.value.contains(e.target)) {
    close();
  }
}

function onEscape(e) {
  if (e.key === 'Escape' && props.open) close();
}

onMounted(() => {
  document.addEventListener('mousedown', onDocClick, true);
  document.addEventListener('keydown',   onEscape);
});
onUnmounted(() => {
  document.removeEventListener('mousedown', onDocClick, true);
  document.removeEventListener('keydown',   onEscape);
});
</script>
