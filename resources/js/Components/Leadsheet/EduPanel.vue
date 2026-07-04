<template>
  <div class="sbn-edu-panel" ref="panelEl" @toggle.capture="onPanelToggle">
    <!-- Song info — collapsed by default; the panel's main job is the
         current chord + progressions, so this stays out of the way until asked for -->
    <details class="vC-panel">
      <summary class="vC-panel-summary">
        <span class="vC-panel-title">Song info</span>
      </summary>
      <div class="vC-panel-body">
        <div v-if="song.composer" class="sbn-edu-meta-item">
          <span class="sbn-edu-meta-label">Composer:</span>
          <span class="sbn-edu-meta-value">{{ song.composer }}</span>
        </div>
        <div v-if="song.performer" class="sbn-edu-meta-item">
          <span class="sbn-edu-meta-label">Performer:</span>
          <span class="sbn-edu-meta-value">{{ song.performer }}</span>
        </div>
        <div v-if="song.songKey" class="sbn-edu-meta-item">
          <span class="sbn-edu-meta-label">Key:</span>
          <span class="sbn-edu-meta-value">{{ song.songKey }}</span>
        </div>
        <div v-if="styleLabelText" class="sbn-edu-meta-item">
          <span class="sbn-edu-meta-label">Style:</span>
          <span class="sbn-edu-meta-value">{{ styleLabelText }}</span>
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
    </details>

    <!-- Current chord block -->
    <details class="vC-panel" open>
      <summary class="vC-panel-summary">
        <span class="vC-panel-title">Current chord</span>
      </summary>
      <div class="vC-panel-body">
        <div v-if="activeCard" class="sbn-edu-chord-detail">
          <a :href="chordDetailUrl" class="sbn-edu-chord-card-link">
            <LibraryChordCard :chord="activeCard" :show-root="true" :detail="true" />
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
    </details>

    <!-- Section progressions -->
    <details class="vC-panel" open>
      <summary class="vC-panel-summary">
        <span class="vC-panel-title">{{ progressionsScopeLabel }}</span>
        <span class="vC-panel-meta">{{ filteredProgressions.length }}</span>
      </summary>
      <div class="vC-panel-body">
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
    </details>

    <!-- Skill nodes this song is tagged as teaching -->
    <details v-if="skillNodes.length" class="vC-panel">
      <summary class="vC-panel-summary">
        <span class="vC-panel-title">Skill nodes</span>
        <span class="vC-panel-meta">{{ skillNodes.length }}</span>
      </summary>
      <div class="vC-panel-body">
        <div class="sbn-edu-skill-grid">
          <a
            v-for="node in skillNodes"
            :key="node.slug"
            :href="`/skills#${node.slug}`"
            class="sbn-edu-skill-tile"
            :data-branch="node.branch"
            :title="node.subBranch ? `${node.title} — ${node.branch} / ${node.subBranch}` : `${node.title} — ${node.branch}`"
          >
            <SkillIcon :icon-path="node.iconPath" :icon-key="node.iconKey" :branch="node.branch" :size="30" />
          </a>
        </div>
      </div>
    </details>

    <!-- Related theory — resolved from skill nodes / progressions / genre / difficulty,
         see EduContentService::conceptsForLeadsheet() -->
    <details v-if="relatedTheory.length" class="vC-panel">
      <summary class="vC-panel-summary">
        <span class="vC-panel-title">Related theory</span>
        <span class="vC-panel-meta">{{ relatedTheory.length }}</span>
      </summary>
      <div class="vC-panel-body vC-panel-body--flush">
        <details
          v-for="concept in relatedTheory"
          :key="concept.slug"
          class="sbn-edu-theory-item"
          @toggle="onTheoryToggle($event, concept)"
        >
          <summary>{{ concept.title }}</summary>
          <div :ref="el => setTheoryBodyEl(concept.slug, el)" v-html="concept.body_html" />
        </details>
      </div>
    </details>
  </div>
</template>

<script setup>
import { computed, ref } from 'vue';
import { getCategoryColor } from '@/composables/useCategoryColors';
import { styleLabel } from '@/composables/useBreadcrumb';
import { mountSbnNodes } from '@/lib/mountSbnNodes';
import SkillIcon from '@/Components/Skill/SkillIcon.vue';

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
  /** Skill nodes this song is tagged as teaching — {slug,title,branch,subBranch}[] */
  skillNodes: {
    type: Array,
    default: () => [],
  },
  /** Concept topics from EduContentService::conceptsForLeadsheet() — EduTopic::toArray()[] */
  relatedTheory: {
    type: Array,
    default: () => [],
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

const styleLabelText = computed(() => styleLabel(props.song.styleSlug));

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

// ── Panel accordion — close siblings when one opens (mirrors PracticePanel) ──
const panelEl = ref(null);

function onPanelToggle(event) {
  const opened = event.target;
  // @toggle.capture bubbles up from nested <details> too (the "Learn more" /
  // "Related theory" concept expanders live inside these top-level panels) —
  // only react to an actual top-level panel toggling, or opening a nested
  // concept expander would force-close its own parent panel out from under it.
  if (!opened.classList?.contains('vC-panel')) return;
  if (!opened.open) return;
  panelEl.value?.querySelectorAll(':scope > details.vC-panel').forEach(d => {
    if (d !== opened) d.open = false;
  });
}

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

// ── Related theory expanders — one per concept, each can mount independently ──
const theoryBodyEls = {};

function setTheoryBodyEl(slug, el) {
  theoryBodyEls[slug] = el;
}

function onTheoryToggle(event, concept) {
  if (!event.target.open) return;
  if (!concept.has_widgets) return;
  if (mountedConcepts.has(concept.slug)) return;
  mountedConcepts.add(concept.slug);
  const el = theoryBodyEls[concept.slug];
  if (el) {
    mountSbnNodes(el);
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
  gap: 12px;
  height: 100%;
  padding: 24px;
  background: var(--clr-surface);
  border: 1px solid var(--clr-border);
  border-radius: var(--radius);
}

/* ── Unified collapsible panel (matches PracticePanel's .vC-panel in the
   course player, so both sidebars read as one visual language) ── */
.vC-panel {
  border: 1px solid var(--clr-border);
  border-radius: var(--radius-sm);
  overflow: hidden;
}

.vC-panel-summary {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 8px;
  padding: 10px 14px;
  cursor: pointer;
  list-style: none;
  user-select: none;
  background: var(--clr-surface-2, var(--clr-bg));
  transition: background 0.12s;
}

.vC-panel-summary::-webkit-details-marker { display: none; }

.vC-panel-summary:hover {
  background: var(--clr-bg-hover);
}

.vC-panel-title {
  font-size: 12px;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: 0.06em;
  color: var(--clr-text-dim);
  display: flex;
  align-items: center;
  gap: 6px;
}

.vC-panel-title::before {
  content: '';
  display: inline-block;
  width: 6px;
  height: 6px;
  border-right: 1.5px solid var(--clr-text-muted);
  border-bottom: 1.5px solid var(--clr-text-muted);
  transform: rotate(-45deg);
  transition: transform 0.15s;
  flex-shrink: 0;
  margin-top: 1px;
}

.vC-panel[open] .vC-panel-title::before {
  transform: rotate(45deg);
  margin-top: -2px;
}

.vC-panel-meta {
  font-size: 11px;
  color: var(--clr-text-muted);
  font-weight: 500;
  flex-shrink: 0;
}

.vC-panel-body {
  padding: 12px 14px;
  border-top: 1px solid var(--clr-border);
  display: flex;
  flex-direction: column;
  gap: 10px;
}

/* Flush variant — full-width content (e.g. Related theory), no side padding
   eating into the room widgets have to render. */
.vC-panel-body--flush {
  padding: 0;
  gap: 0;
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

/* Skill node icon grid — icon-only tiles, full title/branch shown via
   native title tooltip on hover (see EduPanel's :title binding) */
.sbn-edu-skill-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(52px, 1fr));
  gap: 8px;
}

.sbn-edu-skill-tile {
  --_branch-clr: var(--clr-text-muted);
  display: flex;
  align-items: center;
  justify-content: center;
  aspect-ratio: 1;
  color: var(--_branch-clr);
  background: var(--clr-surface-2, var(--clr-bg));
  border: 1px solid var(--clr-border);
  border-radius: var(--radius-sm);
  transition: color 0.15s, border-color 0.15s, background 0.15s, transform 0.15s;
}

.sbn-edu-skill-tile[data-branch="harmony"]        { --_branch-clr: #f39c12; }
.sbn-edu-skill-tile[data-branch="rhythm"]         { --_branch-clr: #3b82f6; }
.sbn-edu-skill-tile[data-branch="melody"]         { --_branch-clr: #ec4899; }
.sbn-edu-skill-tile[data-branch="technique"]      { --_branch-clr: #10b981; }
.sbn-edu-skill-tile[data-branch="ear-training"]   { --_branch-clr: #8b5cf6; }
.sbn-edu-skill-tile[data-branch="reading-theory"] { --_branch-clr: #64748b; }

.sbn-edu-skill-tile:hover {
  border-color: var(--_branch-clr);
  background: color-mix(in srgb, var(--_branch-clr) 12%, transparent);
  transform: translateY(-1px);
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
  /* Widgets (e.g. <sbn-widget>) are built full-size for standalone theory
     pages — clip/contain them here so they never overflow the narrow sidebar
     column, without shrinking or restyling the widgets themselves. */
  max-width: 100%;
  overflow-x: hidden;
}

.sbn-edu-learn-more > div :deep(.sbn-widget-embed) {
  max-width: 100%;
  border-radius: var(--radius-sm);
  overflow: hidden;
}

/* Related theory list — full-width items, neutral (non-accent) titles so this
   reads as reference content rather than a call-to-action like "Learn more". */
.sbn-edu-theory-item {
  border-bottom: 1px solid var(--clr-border);
}

.sbn-edu-theory-item:last-child {
  border-bottom: none;
}

.sbn-edu-theory-item summary {
  padding: 10px 14px;
  cursor: pointer;
  font-weight: 600;
  font-size: 13px;
  color: var(--clr-text-dim);
  list-style: none;
  user-select: none;
  display: flex;
  align-items: center;
  gap: 6px;
  transition: color 0.15s;
}

.sbn-edu-theory-item summary:hover {
  color: var(--clr-text);
}

.sbn-edu-theory-item summary::-webkit-details-marker {
  display: none;
}

.sbn-edu-theory-item summary::before {
  content: '';
  width: 0;
  height: 0;
  border-style: solid;
  border-width: 4px 0 4px 5px;
  border-color: transparent transparent transparent currentColor;
  opacity: 0.5;
  transition: transform 0.15s;
  flex-shrink: 0;
}

.sbn-edu-theory-item[open] summary::before {
  transform: rotate(90deg);
}

.sbn-edu-theory-item > div {
  padding: 0 14px 14px;
  font-size: 12px;
  line-height: 1.6;
  color: var(--clr-text-dim);
  max-width: 100%;
  overflow-x: hidden;
  box-sizing: border-box;
}

.sbn-edu-theory-item > div :deep(.sbn-widget-embed) {
  margin: 0 -14px;
  max-width: calc(100% + 28px);
  overflow: hidden;
}

.sbn-edu-theory-item > div p {
  padding: 0 4px;
  margin: 0 0 10px;
}

.sbn-edu-theory-item > div p:last-child {
  margin-bottom: 0;
}

.sbn-edu-theory-item > div ul,
.sbn-edu-theory-item > div ol {
  margin: 0 0 10px;
  padding: 0 4px 0 26px;
}

.sbn-edu-theory-item > div li {
  margin-bottom: 4px;
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
  
  .sbn-edu-chord-name {
    font-size: 20px;
  }
  
  .sbn-edu-progression-link {
    padding: 10px;
  }
}
</style>
