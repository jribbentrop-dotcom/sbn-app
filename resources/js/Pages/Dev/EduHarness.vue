<script setup lang="ts">
/**
 * Dev harness for the Edu Content System <sbn-widget> pipeline.
 *
 * Renders an edu topic's server-rendered body_html and runs mountSbnNodes
 * over it, so embedded <sbn-widget> tags become live Vue components. Reached
 * via the local-only route /dev/edu/{type}/{slug} — not a product surface.
 */
import { onBeforeUnmount, onMounted, ref } from 'vue';
import { Head } from '@inertiajs/vue3';
import { mountSbnNodes } from '../../lib/mountSbnNodes';

interface Topic {
  slug: string;
  type: string;
  title: string;
  summary: string;
  body_html: string;
  related: string[];
  see_also: string[];
}

defineProps<{ topic: Topic }>();

const bodyRef = ref<HTMLElement | null>(null);
let unmount: (() => void) | null = null;

onMounted(async () => {
  if (bodyRef.value) {
    unmount = await mountSbnNodes(bodyRef.value);
  }
});

onBeforeUnmount(() => {
  unmount?.();
  unmount = null;
});
</script>

<template>
  <Head><title>{{ topic.title }} — Edu Harness</title></Head>
  <div class="edu-harness">
    <p class="edu-harness-banner">
      Dev harness — Edu Content System pipeline. <code>{{ topic.type }}/{{ topic.slug }}</code>
    </p>
    <h1>{{ topic.title }}</h1>
    <p class="edu-harness-summary">{{ topic.summary }}</p>
    <article ref="bodyRef" class="edu-harness-body" v-html="topic.body_html" />
  </div>
</template>

<style scoped>
.edu-harness {
  max-width: 720px;
  margin: 32px auto;
  padding: 0 24px;
}

.edu-harness-banner {
  padding: 8px 12px;
  background: #fff3cd;
  border: 1px solid #ffe69c;
  border-radius: 6px;
  font-size: 13px;
  color: #664d03;
}

.edu-harness-summary {
  font-size: 16px;
  color: var(--clr-text-muted, #666);
  font-style: italic;
}

.edu-harness-body {
  line-height: 1.6;
}
</style>
