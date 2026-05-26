<template>
  <div class="sbn-edu-panel">
    <!-- Song info header -->
    <div class="sbn-edu-header">
      <h3 class="sbn-edu-song-title">{{ song.title }}</h3>
      <div class="sbn-edu-song-meta">
        <div v-if="song.composer" class="sbn-edu-meta-item">
          <span class="sbn-edu-meta-label">Composer:</span>
          <span class="sbn-edu-meta-value">{{ song.composer }}</span>
        </div>
        <div v-if="song.songKey" class="sbn-edu-meta-item">
          <span class="sbn-edu-meta-label">Key:</span>
          <span class="sbn-edu-meta-value">{{ song.songKey }}</span>
        </div>
        <div v-if="song.tempo" class="sbn-edu-meta-item">
          <span class="sbn-edu-meta-label">Tempo:</span>
          <span class="sbn-edu-meta-value">{{ song.tempo }} BPM</span>
        </div>
        <div v-if="song.timeSignature" class="sbn-edu-meta-item">
          <span class="sbn-edu-meta-label">Time:</span>
          <span class="sbn-edu-meta-value">{{ song.timeSignature }}</span>
        </div>
      </div>
    </div>

    <!-- Current chord block -->
    <div class="sbn-edu-section">
      <h4 class="sbn-edu-section-title">Current Chord</h4>
      <div v-if="activeCard" class="sbn-edu-chord-detail">
        <a :href="chordDetailUrl" class="sbn-edu-chord-card-link">
          <LibraryChordCard :chord="activeCard" :show-root="true" />
        </a>

        <!-- Learn more expander — related concept, if one exists -->
        <details
          v-if="relatedConcept"
          class="sbn-edu-learn-more"
          @toggle="onLearnMoreToggle"
        >
          <summary>Learn more: {{ relatedConcept.title }}</summary>
          <div ref="conceptBodyEl" v-html="relatedConcept.body_html" />
        </details>
      </div>
      <div v-else-if="currentChord" class="sbn-edu-chord-detail">
        <!-- Fallback when no voicing data available -->
        <a :href="chordDetailUrl" class="sbn-edu-chord-card-link">
          <div class="sbn-edu-chord-name" v-html="formatChordHtml(currentChord)"></div>
        </a>

        <!-- Learn more expander — related concept, if one exists -->
        <details
          v-if="relatedConcept"
          class="sbn-edu-learn-more"
          @toggle="onLearnMoreToggle"
        >
          <summary>Learn more: {{ relatedConcept.title }}</summary>
          <div ref="conceptBodyEl" v-html="relatedConcept.body_html" />
        </details>
      </div>
      <div v-else class="sbn-edu-empty-state">
        <p>Click a chord to learn more</p>
      </div>
    </div>

    <!-- Section progressions -->
    <div class="sbn-edu-section">
      <h4 class="sbn-edu-section-title">
        {{ progressionsScopeLabel }}
      </h4>
      <div v-if="filteredProgressions.length > 0" class="sbn-edu-progressions">
        <div
          v-for="progression in filteredProgressions"
          :key="progression.id"
          class="sbn-edu-progression-item"
          :class="{ 'is-active': progression.id === hoveredProgressionId }"
          @mouseenter="emit('progression-hover', progression.id)"
          @mouseleave="emit('progression-hover', null)"
        >
          <a
            :href="`/library/progressions/${progression.slug}`"
            class="sbn-edu-progression-link"
          >
            <div class="sbn-edu-progression-name">{{ progression.name }}</div>
            <div class="sbn-numeral-chip-row">
              <span
                v-for="n in progression.numeralsDisplay.split('–').map(s => s.trim()).filter(Boolean)"
                :key="n"
                class="sbn-numeral-chip"
              >{{ n }}</span>
            </div>
            <div style="margin-top: 6px;">
              <span class="sbn-cat-badge sbn-cat-badge-filled" :style="{ '--cat-clr': getCategoryColor(progression.category) }">
                {{ progression.category }}
              </span>
            </div>
          </a>
        </div>
      </div>
      <div v-else class="sbn-edu-empty-state">
        <p v-if="isFilteredBySection">No detected progressions in this section.</p>
        <p v-else>No progressions detected for this song yet.</p>
      </div>
    </div>
  </div>
</template>

<script setup>
import { computed, ref } from 'vue';
import { getCategoryColor } from '@/composables/useCategoryColors';
import { mountSbnNodes } from '@/lib/mountSbnNodes';

// Import chord formatting utilities (reuse from tab-editor)
import { formatChordHtml } from '@/tab-editor/utils/chordFormat.js';

// Import library chord card component
import LibraryChordCard from '@/Components/Library/ChordCard.vue';

const props = defineProps({
  currentChord: {
    type: String,
    default: null,
  },
  currentSectionId: {
    type: String,
    default: null,
  },
  selectionKey: {
    type: String,
    default: null,
  },
  song: {
    type: Object,
    required: true,
  },
  progressions: {
    type: Array,
    required: true,
  },
  chordCards: {
    type: Object,
    default: () => ({}),
  },
  qualityByKey: {
    type: Object,
    default: () => ({}),
  },
  eduChordQualities: {
    type: Object,
    default: () => ({}),
  },
  eduRelatedConcepts: {
    type: Object,
    default: () => ({}),
  },
  // Id of the progression currently hovered in this list — drives the
  // 'is-active' marker. Owned by LeadsheetViewer so the grid can react too.
  hoveredProgressionId: {
    type: Number,
    default: null,
  },
});

// Emitted when the user hovers/leaves a progression entry (id, or null).
const emit = defineEmits(['progression-hover']);

// Look up a value by per-slot key, falling back to bare-name (mirrors the
// grid's lookup pattern: cv[`${name}@${gi}.${ci}`] || cv[name]). Voicings can
// be stored either way; the viewer must accept both.
function _lookupWithFallback(map, key) {
  if (!map || !key) return null;
  if (map[key] != null) return map[key];
  const bare = key.replace(/@\d+\.\d+$/, '');
  return map[bare] ?? null;
}

// Active chord card from the enriched map
const activeCard = computed(() =>
  _lookupWithFallback(props.chordCards, props.selectionKey)
);

// ── Related concept expander ───────────────────────────────────────────────────────
// Resolves the first related concept slug for the active quality and looks it
// up in eduRelatedConcepts. Returns null if no related concept exists.
const relatedConcept = computed(() => {
  const qualitySlug = _lookupWithFallback(props.qualityByKey, props.selectionKey);
  if (!qualitySlug) return null;
  const quality = props.eduChordQualities[qualitySlug];
  const conceptSlug = quality?.related?.[0];
  if (!conceptSlug) return null;
  return props.eduRelatedConcepts[conceptSlug] ?? null;
});

// The body element for the active concept — used by mountSbnNodes.
const conceptBodyEl = ref(null);

// Track which concept slugs have already had their widgets mounted so we only
// call mountSbnNodes once per concept per panel lifetime (widgets misbehave if
// mounted into hidden / already-mounted elements).
const mountedConcepts = new Set();

function onLearnMoreToggle(event) {
  if (!event.target.open) return;
  const concept = relatedConcept.value;
  if (!concept || !concept.has_widgets) return;
  if (mountedConcepts.has(concept.slug)) return;
  mountedConcepts.add(concept.slug);
  if (conceptBodyEl.value) {
    mountSbnNodes(conceptBodyEl.value);
  }
}

// ── Chord slug generation ─────────────────────────────────────────────────────────────
const chordSlug = computed(() => {
  if (!props.currentChord) return '';
  // Simple slug generation - this should match the chord library's slug format
  return props.currentChord.toLowerCase()
    .replace(/[^a-z0-9]/g, '-')
    .replace(/-+/g, '-')
    .replace(/^-|-$/g, '');
});

const chordRoot = computed(() => {
  const name = props.currentChord || (activeCard.value ? activeCard.value.name : '');
  if (!name) return '';
  const roots2 = ['C#', 'Db', 'D#', 'Eb', 'F#', 'Gb', 'G#', 'Ab', 'A#', 'Bb'];
  if (name.length >= 2) {
    const r2 = name.substring(0, 2);
    if (roots2.includes(r2)) return r2;
  }
  return name.substring(0, 1);
});

const chordDetailUrl = computed(() => {
  const root = encodeURIComponent(chordRoot.value);
  if (activeCard.value && activeCard.value.slug) {
    return `/library/chords/${activeCard.value.slug}?root=${root}`;
  }
  return `/library/chords/${chordSlug.value}?root=${root}`;
});

// True only when section-level filtering is actually being applied.
const isFilteredBySection = computed(() => {
  if (!props.progressions || !props.currentSectionId) return false;
  return props.progressions.some(p => p.sectionId != null);
});

const progressionsScopeLabel = computed(() =>
  isFilteredBySection.value
    ? 'Progressions in this section'
    : 'Progressions in this song'
);

// ── Filter progressions by section ─────────────────────────────────────────────────────
const filteredProgressions = computed(() => {
  if (!props.progressions || props.progressions.length === 0) return [];

  // Section attribution is optional. Only filter if BOTH a section is selected
  // AND at least one progression carries sectionId data (R3). Otherwise show all
  // progressions in the song so the panel never appears empty when data exists.
  const anyHasSectionId = props.progressions.some(p => p.sectionId != null);
  if (props.currentSectionId && anyHasSectionId) {
    return props.progressions.filter(p => p.sectionId === props.currentSectionId);
  }

  return props.progressions;
});
</script>

<style scoped>
.sbn-edu-panel {
  display: flex;
  flex-direction: column;
  gap: 24px;
  height: 100%;
  padding: 24px;
  background: var(--clr-surface);
  border: 1px solid var(--clr-border);
  border-radius: var(--radius);
}

/* Header */
.sbn-edu-header {
  padding-bottom: 16px;
}

.sbn-edu-song-title {
  margin: 0 0 12px 0;
  font-size: 20px;
  font-weight: 700;
  color: var(--clr-text);
  line-height: 1.3;
}

.sbn-edu-song-meta {
  display: flex;
  flex-direction: column;
  gap: 6px;
}

.sbn-edu-meta-item {
  display: flex;
  justify-content: space-between;
  align-items: center;
  font-size: 13px;
}

.sbn-edu-meta-label {
  color: var(--clr-text-muted);
  font-weight: 500;
}

.sbn-edu-meta-value {
  color: var(--clr-text);
  font-weight: 600;
}

/* Sections */
.sbn-edu-section {
  display: flex;
  flex-direction: column;
  gap: 12px;
}

.sbn-edu-section-title {
  margin: 0;
  font-size: 16px;
  font-weight: 600;
  color: var(--clr-text);
}

/* Chord detail */
.sbn-edu-chord-card-link {
  text-decoration: none;
  color: inherit;
  display: block;
  transition: transform 0.2s var(--ease, cubic-bezier(0.4, 0, 0.2, 1));
}

.sbn-edu-chord-card-link:hover {
  transform: translateY(-2px);
}

.sbn-edu-chord-detail {
  display: flex;
  flex-direction: column;
  gap: 12px;
}

.sbn-edu-chord-name {
  font-size: 24px;
  font-weight: 700;
  color: var(--clr-text);
  text-align: center;
  padding: 12px;
  background: var(--clr-surface);
  border-radius: var(--radius);
  border: 2px solid var(--clr-accent-border);
}

/* Progressions */
.sbn-edu-progressions {
  display: flex;
  flex-direction: column;
  gap: 8px;
}

.sbn-edu-progression-item {
  border-radius: var(--radius-sm);
  overflow: hidden;
  border: 1px solid var(--clr-border);
  transition: all 0.15s;
}

.sbn-edu-progression-item:hover,
.sbn-edu-progression-item.is-active {
  border-color: var(--clr-accent);
  box-shadow: 0 2px 4px rgba(0,0,0,0.1);
}

.sbn-edu-progression-item.is-active {
  background: var(--clr-accent-bg, rgba(232, 93, 59, 0.08));
}

.sbn-edu-progression-link {
  display: block;
  padding: 12px;
  text-decoration: none;
  color: inherit;
}

.sbn-edu-progression-name {
  font-size: 14px;
  font-weight: 600;
  color: var(--clr-text);
  margin-bottom: 4px;
}


.sbn-edu-progression-category {
  font-size: 11px;
  color: var(--clr-text-muted);
  text-transform: uppercase;
  letter-spacing: 0.5px;
}

/* Learn more expander */
.sbn-edu-learn-more {
  font-size: 14px;
  border: 1px solid var(--clr-border);
  border-radius: var(--radius-sm);
  overflow: hidden;
}

.sbn-edu-learn-more summary {
  padding: 8px 12px;
  cursor: pointer;
  font-weight: 500;
  color: var(--clr-accent);
  list-style: none;
  user-select: none;
}

.sbn-edu-learn-more summary::-webkit-details-marker {
  display: none;
}

.sbn-edu-learn-more summary::before {
  content: '▶ ';
  font-size: 10px;
  opacity: 0.6;
}

.sbn-edu-learn-more[open] summary::before {
  content: '▼ ';
}

.sbn-edu-learn-more > div {
  padding: 12px;
  line-height: 1.6;
  color: var(--clr-text-dim);
  border-top: 1px solid var(--clr-border);
}

.sbn-edu-learn-more > div p {
  margin: 0 0 8px 0;
}

.sbn-edu-learn-more > div p:last-child {
  margin-bottom: 0;
}

.sbn-edu-learn-more > div ul,
.sbn-edu-learn-more > div ol {
  margin: 0 0 8px 0;
  padding-left: 20px;
}

/* Empty states */
.sbn-edu-empty-state {
  padding: 20px;
  text-align: center;
  color: var(--clr-text-muted);
  font-size: 14px;
  font-style: italic;
}

.sbn-edu-empty-state p {
  margin: 0;
}

/* Mobile responsive */
@media (max-width: 768px) {
  .sbn-edu-panel {
    gap: 20px;
  }
  
  .sbn-edu-song-title {
    font-size: 18px;
  }
  
  .sbn-edu-chord-name {
    font-size: 20px;
  }
  
  .sbn-edu-progression-link {
    padding: 10px;
  }
}
</style>
