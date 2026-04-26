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
      <div v-if="currentChord" class="sbn-edu-chord-detail">
        <div class="sbn-edu-chord-name">
          <span class="sbn-chord-symbol" v-html="formatChordHtml(currentChord)"></span>
        </div>
        <div v-if="chordQualityInfo" class="sbn-edu-chord-blurb">
          <p>{{ chordQualityInfo.blurb }}</p>
        </div>
        <div class="sbn-edu-chord-actions">
          <a 
            :href="`/library/chords/${chordSlug}`" 
            class="sbn-edu-link"
          >
            View in chord library →
          </a>
        </div>
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
        >
          <a 
            :href="`/library/progressions/${progression.slug}`"
            class="sbn-edu-progression-link"
          >
            <div class="sbn-edu-progression-name">{{ progression.name }}</div>
            <div class="sbn-edu-progression-numerals">{{ progression.numeralsDisplay }}</div>
            <div class="sbn-edu-progression-category">{{ progression.category }}</div>
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
import { computed } from 'vue';

// Import chord formatting utilities (reuse from tab-editor)
import { formatChordHtml } from '@/tab-editor/utils/chordFormat.js';

const props = defineProps({
  currentChord: {
    type: String,
    default: null,
  },
  currentSectionId: {
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
});

// ── Chord quality parsing ─────────────────────────────────────────────────────────────
const chordQualityInfo = computed(() => {
  if (!props.currentChord) return null;
  
  // Simple chord quality detection - this will be replaced with the service in Step 6
  const chord = props.currentChord.toLowerCase();
  
  // Basic quality patterns
  if (chord.includes('maj7') || chord.includes('△7') || chord.includes('M7')) {
    return { title: 'Major 7th', blurb: 'A major triad with an added major 7th interval. Creates a rich, jazzy sound often used in bossa nova and jazz standards.' };
  }
  if (chord.includes('m7') || chord.includes('min7') || chord.includes('-7')) {
    return { title: 'Minor 7th', blurb: 'A minor triad with an added minor 7th interval. Common in jazz, blues, and contemporary music for its sophisticated yet somber quality.' };
  }
  if (chord.includes('7') && !chord.includes('maj7') && !chord.includes('m7')) {
    return { title: 'Dominant 7th', blurb: 'A major triad with a flat 7th interval. Creates tension that naturally resolves to the tonic, essential for blues and jazz progressions.' };
  }
  if (chord.includes('m') || chord.includes('-') || chord.includes('min')) {
    return { title: 'Minor', blurb: 'A minor triad (root, minor third, perfect fifth). Creates a sad or contemplative mood, fundamental to most musical styles.' };
  }
  if (chord.includes('dim') || chord.includes('°')) {
    return { title: 'Diminished', blurb: 'A diminished triad with a flat fifth and flat seventh. Creates intense tension, often used as a passing chord or leading tone chord.' };
  }
  if (chord.includes('aug') || chord.includes('+')) {
    return { title: 'Augmented', blurb: 'An augmented triad with a raised fifth. Creates an unsettled, dreamy quality that wants to resolve.' };
  }
  
  // Default to major
  return { title: 'Major', blurb: 'A major triad (root, major third, perfect fifth). Creates a bright, happy sound that forms the foundation of most Western music.' };
});

// ── Chord slug generation ─────────────────────────────────────────────────────────────
const chordSlug = computed(() => {
  if (!props.currentChord) return '';
  // Simple slug generation - this should match the chord library's slug format
  return props.currentChord.toLowerCase()
    .replace(/[^a-z0-9]/g, '-')
    .replace(/-+/g, '-')
    .replace(/^-|-$/g, '');
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
  padding: 0;
}

/* Header */
.sbn-edu-header {
  padding-bottom: 16px;
  border-bottom: 1px solid var(--clr-border);
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

.sbn-edu-chord-blurb {
  font-size: 14px;
  line-height: 1.5;
  color: var(--clr-text-dim);
  padding: 12px;
  background: var(--clr-surface-2);
  border-radius: var(--radius-sm);
}

.sbn-edu-chord-blurb p {
  margin: 0;
}

.sbn-edu-chord-actions {
  text-align: center;
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

.sbn-edu-progression-item:hover {
  border-color: var(--clr-accent);
  box-shadow: 0 2px 4px rgba(0,0,0,0.1);
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

.sbn-edu-progression-numerals {
  font-size: 13px;
  color: var(--clr-accent);
  font-family: monospace;
  margin-bottom: 4px;
}

.sbn-edu-progression-category {
  font-size: 11px;
  color: var(--clr-text-muted);
  text-transform: uppercase;
  letter-spacing: 0.5px;
}

/* Links */
.sbn-edu-link {
  display: inline-block;
  padding: 8px 16px;
  background: var(--clr-accent);
  color: white;
  text-decoration: none;
  border-radius: var(--radius-sm);
  font-weight: 500;
  font-size: 13px;
  transition: all 0.15s;
}

.sbn-edu-link:hover {
  background: var(--clr-accent-dim);
  transform: translateY(-1px);
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
