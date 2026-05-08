<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { mountSbnNodes } from '../../lib/mountSbnNodes';

interface Subsection { title: string; slug: string }
interface LessonData {
  slug: string;
  title: string;
  content: string | null;
  isPreview: boolean;
  subsections: Subsection[];
  sortOrder?: number;
}
interface LessonNav { slug: string; title: string }

const props = defineProps<{
  lesson: LessonData | null;
  hasAccess: boolean;
  productSlug: string | null;
  courseSlug: string;
  prevLesson: LessonNav | null;
  nextLesson: LessonNav | null;
}>();

const emit = defineEmits<{
  subsectionChange: [slug: string | null];
  openSidebar: [];
}>();

const contentRef = ref<HTMLElement | null>(null);
const activeSubsection = ref<string | null>(null);
const chunkIds = ref<string[]>([]);
let unmountSbnNodes: (() => void) | null = null;

const isLocked = computed(() => !!props.lesson && !props.hasAccess && !props.lesson.isPreview);
const unlockHref = computed(() => (props.productSlug ? `/shop/product/${props.productSlug}` : '/shop'));

function refreshChunks(): void {
  if (!contentRef.value) return;
  const root = contentRef.value;
  const headings = Array.from(root.querySelectorAll<HTMLElement>('h2[id^="section-"]'));
  chunkIds.value = [];

  if (!headings.length) {
    activeSubsection.value = null;
    emit('subsectionChange', null);
    return;
  }

  const nodes = Array.from(root.childNodes);
  const chunks: Array<{ slug: string; nodes: ChildNode[] }> = [];
  let current: { slug: string; nodes: ChildNode[] } | null = null;

  for (const node of nodes) {
    if (node.nodeType === Node.ELEMENT_NODE) {
      const el = node as HTMLElement;
      if (el.tagName === 'H2' && el.id.startsWith('section-')) {
        current = { slug: el.id, nodes: [node] };
        chunks.push(current);
        continue;
      }
    }
    if (current) current.nodes.push(node);
  }

  for (const chunk of chunks) {
    const wrapper = document.createElement('div');
    wrapper.className = 'sbn-subsection-chunk';
    wrapper.dataset.section = chunk.slug;
    for (const node of chunk.nodes) wrapper.appendChild(node);
    root.appendChild(wrapper);
    chunkIds.value.push(chunk.slug);
  }

  const target = window.location.hash?.replace('#', '');
  activeSubsection.value = target && chunkIds.value.includes(target) ? target : chunkIds.value[0] ?? null;
  setActiveChunk(activeSubsection.value);
}

function setActiveChunk(slug: string | null): void {
  if (!contentRef.value) return;
  const chunks = Array.from(contentRef.value.querySelectorAll<HTMLElement>('.sbn-subsection-chunk'));
  chunks.forEach((chunk) => {
    const active = chunk.dataset.section === slug;
    chunk.classList.toggle('is-active', active);
    chunk.style.display = active ? '' : 'none';
  });
  activeSubsection.value = slug;
  emit('subsectionChange', slug);
  if (slug) window.history.replaceState({}, '', `#${slug}`);
}

function goSubsection(slug: string): void { setActiveChunk(slug); }
defineExpose({ goSubsection });

async function mountNodes(): Promise<void> {
  if (unmountSbnNodes) { unmountSbnNodes(); unmountSbnNodes = null; }
  if (!contentRef.value) return;
  unmountSbnNodes = await mountSbnNodes(contentRef.value);
}

watch(() => props.lesson?.slug, async () => {
  await nextTick();
  refreshChunks();
  await mountNodes();
});

onMounted(async () => {
  refreshChunks();
  await mountNodes();
});

onBeforeUnmount(() => {
  if (unmountSbnNodes) { unmountSbnNodes(); unmountSbnNodes = null; }
});

function subLabel(slug: string): string {
  const sub = props.lesson?.subsections.find(s => s.slug === slug);
  if (sub) return sub.title;
  return slug.replace('section-', '').replace(/-/g, ' ');
}
</script>

<template>
  <div class="vC-content-wrap">
    <!-- Lesson header -->
    <div v-if="lesson" class="vC-head">
      <div class="vC-head-eyebrow">
        <button type="button" class="vC-mobile-menu-btn" @click="emit('openSidebar')">
          <svg width="16" height="16" viewBox="0 0 16 16">
            <path d="M2 4h12M2 8h12M2 12h12" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/>
          </svg>
        </button>
        {{ lesson.title }}
      </div>
      <h1 class="vC-title">{{ lesson.title }}</h1>
      <div v-if="chunkIds.length" class="vC-head-tabs">
        <button
          v-for="(slug, i) in chunkIds"
          :key="slug"
          type="button"
          class="vC-tab"
          :class="{ 'is-active': activeSubsection === slug }"
          @click="setActiveChunk(slug)"
        >
          {{ i + 1 }}. {{ subLabel(slug) }}
        </button>
      </div>
    </div>

    <!-- Lesson body -->
    <div class="vC-prose">
      <div v-if="isLocked" class="vC-lock-cta">
        <div class="vC-lock-icon">🔒</div>
        <h3>This lesson is locked</h3>
        <p>Unlock the full course to continue learning.</p>
        <Link :href="unlockHref" class="sbn-btn sbn-btn-primary">Unlock course</Link>
      </div>
      <article
        v-else-if="lesson?.content"
        ref="contentRef"
        class="vC-lesson-html lesson-body"
        v-html="lesson.content"
      />
      <p v-else class="vC-empty">No lesson selected.</p>
    </div>

    <!-- Prev / Next footer -->
    <footer class="vC-foot">
      <Link
        v-if="prevLesson"
        :href="`/learn/${courseSlug}/play/${prevLesson.slug}`"
        class="sbn-btn sbn-btn-secondary"
      >
        ← Previous
      </Link>
      <span v-else />
      <Link
        v-if="nextLesson"
        :href="`/learn/${courseSlug}/play/${nextLesson.slug}`"
        class="sbn-btn sbn-btn-primary"
      >
        Next lesson →
      </Link>
      <span v-else />
    </footer>
  </div>
</template>
