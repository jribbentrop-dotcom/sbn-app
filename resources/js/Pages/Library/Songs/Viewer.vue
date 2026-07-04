<script setup>
import { Head } from '@inertiajs/vue3';
import { computed } from 'vue';
import PublicLayout from '@/Layouts/PublicLayout.vue';
import LeadsheetViewer from '@/Components/Leadsheet/LeadsheetViewer.vue';

defineOptions({ layout: PublicLayout });

const props = defineProps({
  leadsheet: {
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
  chordNumerals: {
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
  skillNodes: {
    type: Array,
    default: () => [],
  },
  relatedTheory: {
    type: Array,
    default: () => [],
  },
  versions: {
    type: Array,
    default: () => [],
  },
  activeVersion: {
    type: String,
    default: '',
  },
});

const cinemaUrl = computed(() => `/library/songs/${props.leadsheet.slug}/cinema`);

// SEO metadata
const metaDescription = computed(() => {
  const parts = ['Lead sheet'];
  if (props.leadsheet.title) parts.push(`for ${props.leadsheet.title}`);
  if (props.leadsheet.composer) parts.push(`by ${props.leadsheet.composer}`);
  if (props.leadsheet.songKey) parts.push(`in ${props.leadsheet.songKey}`);
  if (props.leadsheet.tempo) parts.push(`at ${props.leadsheet.tempo} BPM`);
  return parts.join(' ') + '.';
});
</script>

<template>
  <Head :title="`${leadsheet.title}${leadsheet.composer ? ' — ' + leadsheet.composer : ''} | SBN`">
    <meta name="description" :content="metaDescription" />
    <meta property="og:title" :content="leadsheet.title" />
    <meta property="og:description" :content="metaDescription" />
    <meta property="og:type" content="article" />
  </Head>

  <LeadsheetViewer
    :leadsheet="leadsheet"
    :progressions="progressions"
    :chord-cards="chordCards"
    :quality-by-key="qualityByKey"
    :chord-numerals="chordNumerals"
    :edu-chord-qualities="eduChordQualities"
    :edu-related-concepts="eduRelatedConcepts"
    :skill-nodes="skillNodes"
    :related-theory="relatedTheory"
    :cinema-url="cinemaUrl"
    :versions="versions"
    :active-version="activeVersion"
  />
</template>
