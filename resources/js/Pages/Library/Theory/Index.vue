<script setup lang="ts">
import { ref, computed, onMounted, nextTick, watch } from 'vue';
import { Head } from '@inertiajs/vue3';
import PublicLayout from '@/Layouts/PublicLayout.vue';
import { widgetCatalog, allWidgetTags } from '@/edu/widgets/catalog';
import { eduWidgets, isEduWidget } from '@/edu/widgets/registry';
import { createApp } from 'vue';

defineOptions({ layout: PublicLayout });

const PAGE_SIZE = 5;

// ── Filter state ──────────────────────────────────────────────────────────────
const search    = ref('');
const activeTags = ref<Set<string>>(new Set());

function toggleTag(tag: string) {
    const next = new Set(activeTags.value);
    next.has(tag) ? next.delete(tag) : next.add(tag);
    activeTags.value = next;
}

const hasFilters = computed(() => search.value.trim() !== '' || activeTags.value.size > 0);
const filtersOpen = ref(false);

function clearFilters() {
    search.value = '';
    activeTags.value = new Set();
}

// ── Filtered list ─────────────────────────────────────────────────────────────
const filtered = computed(() => {
    const q = search.value.trim().toLowerCase();
    return widgetCatalog.filter(w => {
        if (activeTags.value.size > 0 && ![...activeTags.value].every(t => w.tags.includes(t))) return false;
        if (q && !w.title.toLowerCase().includes(q) && !w.summary.toLowerCase().includes(q)) return false;
        return true;
    });
});

// ── Pagination ────────────────────────────────────────────────────────────────
const visibleCount = ref(PAGE_SIZE);

const visible = computed(() => filtered.value.slice(0, visibleCount.value));
const hasMore  = computed(() => visibleCount.value < filtered.value.length);

function loadMore() {
    visibleCount.value += PAGE_SIZE;
}

// Reset pagination when filters change
watch(filtered, () => { visibleCount.value = PAGE_SIZE; });

// ── Widget mounting ───────────────────────────────────────────────────────────
// Each card gets a ref container; we mount widgets after render.
const mountedSlugs = new Set<string>();

async function mountWidget(slug: string) {
    if (mountedSlugs.has(slug)) return;
    if (!isEduWidget(slug)) return;
    const el = document.getElementById(`theory-widget-${slug}`);
    if (!el) return;
    mountedSlugs.add(slug);
    try {
        const mod = await eduWidgets[slug]();
        const entry = widgetCatalog.find(w => w.slug === slug);
        const props = entry?.defaultProps ?? {};
        createApp(mod.default, props).mount(el);
    } catch (err) {
        console.warn(`[Theory] Failed to mount widget "${slug}":`, err);
    }
}

async function mountVisible() {
    await nextTick();
    for (const w of visible.value) {
        mountWidget(w.slug);
    }
}

onMounted(mountVisible);
watch(visible, mountVisible);
</script>

<template>
    <Head>
        <title>Music Theory for Guitarists | Soul Bossa Nova</title>
        <meta name="description" content="Interactive music theory widgets for guitarists: Triad Builder, Circle of Fifths, Drop-2 Voicings and Voice Leading — visual, hands-on and instantly understandable." />
        <meta property="og:title" content="Music Theory for Guitarists | Soul Bossa Nova" />
        <meta property="og:description" content="Learn music theory visually with interactive widgets — triads, circle of fifths, drop voicings and voice leading for guitar." />
        <meta property="og:type" content="website" />
    </Head>

    <div class="theory-page">
        <div class="theory-main">

        <!-- ── Header ────────────────────────────────────────────────────── -->
        <header class="sbn-library-header">
            <h1 class="sbn-library-title">Music Theory</h1>
            <p class="sbn-library-subtitle">Interactive tools to see, hear, and understand music theory concepts</p>

            <div class="sbn-search-container">
                <div class="sbn-search-box">
                    <svg class="sbn-search-icon" width="18" height="18" viewBox="0 0 20 20" fill="none">
                        <circle cx="8" cy="8" r="6" stroke="currentColor" stroke-width="2"/>
                        <path d="M13 13L18 18" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                    </svg>
                    <input
                        v-model="search"
                        type="search"
                        class="sbn-search-input"
                        placeholder="Search topics…"
                        autocomplete="off"
                    />
                    <button
                        v-if="search"
                        class="sbn-search-clear"
                        @click="search = ''"
                        aria-label="Clear search"
                    >
                        <svg width="14" height="14" viewBox="0 0 16 16" fill="none">
                            <path d="M4 4L12 12M12 4L4 12" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                        </svg>
                    </button>
                </div>

                <button type="button" class="sbn-lib-filter-toggle" @click="filtersOpen = true">
                    <svg width="16" height="16" viewBox="0 0 16 16" fill="none">
                        <path d="M2 4h12M4.5 8h7M7 12h2" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/>
                    </svg>
                    Filters
                    <span v-if="hasFilters" class="sbn-lib-filter-toggle-dot" aria-hidden="true"></span>
                </button>
            </div>
        </header>

        <!-- ── Count bar ─────────────────────────────────────────────────── -->
        <div class="sbn-count-bar">
            <span v-if="hasFilters">
                Showing <strong>{{ filtered.length }}</strong> of {{ widgetCatalog.length }} topics
            </span>
            <span v-else>
                <strong>{{ widgetCatalog.length }}</strong> interactive topics
            </span>
            <button v-if="hasFilters" class="sbn-count-clear" @click="clearFilters">
                Clear filters
            </button>
        </div>

        <!-- ── Content: grid + sidebar ────────────────────────────────────── -->
        <div class="sbn-content-wrapper">

            <!-- Widget grid -->
            <div class="sbn-results-container">

                <div v-if="visible.length" class="theory-grid">
                    <div
                        v-for="widget in visible"
                        :key="widget.slug"
                        class="theory-card"
                    >
                        <!-- Widget mounts here -->
                        <div :id="`theory-widget-${widget.slug}`" />

                        <div class="theory-card-meta">
                            <h3 class="theory-card-title">{{ widget.title }}</h3>
                            <p class="theory-card-summary">{{ widget.summary }}</p>
                            <div class="theory-card-tags">
                                <span
                                    v-for="tag in widget.tags"
                                    :key="tag"
                                    class="theory-tag"
                                    :class="{ active: activeTags.has(tag) }"
                                    @click="toggleTag(tag)"
                                >{{ tag }}</span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- No results -->
                <div v-else class="sbn-no-results">
                    <svg width="48" height="48" viewBox="0 0 48 48" fill="none">
                        <circle cx="20" cy="20" r="12" stroke="currentColor" stroke-width="2"/>
                        <path d="M30 30L42 42" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                        <path d="M16 20H24M20 16V24" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                    </svg>
                    <h3>No topics match your search</h3>
                    <p>Try a different term or clear your filters</p>
                </div>

                <!-- Load more -->
                <div v-if="hasMore" class="theory-load-more">
                    <button class="theory-load-more-btn" @click="loadMore">
                        Load more
                    </button>
                </div>
            </div>

            <button
                type="button"
                class="sbn-lib-filter-overlay"
                v-if="filtersOpen"
                @click="filtersOpen = false"
                aria-label="Close filters"
            />

            <!-- Filter sidebar -->
            <aside class="sbn-filter-sidebar" :class="{ 'sbn-lib-filter-open': filtersOpen }">
                <button type="button" class="sbn-lib-filter-close" @click="filtersOpen = false" aria-label="Close filters">
                    <svg width="14" height="14" viewBox="0 0 14 14" fill="none">
                        <path d="M1 1l12 12M13 1L1 13" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                    </svg>
                </button>
                <div class="sbn-sidebar-header">
                    <h3>Topics</h3>
                </div>

                <div class="sbn-sidebar-section">
                    <span class="sbn-sidebar-label">Filter by tag</span>
                    <div class="sbn-sidebar-options">
                        <button
                            v-for="tag in allWidgetTags"
                            :key="tag"
                            class="sbn-sidebar-option"
                            :class="{ active: activeTags.has(tag) }"
                            @click="toggleTag(tag)"
                        >{{ tag }}</button>
                    </div>
                </div>

                <button v-if="hasFilters" class="sbn-clear-filters-btn" @click="clearFilters">
                    Clear All
                </button>
            </aside>

        </div>
        </div><!-- /.theory-main -->
    </div>
</template>

<style scoped>
/* ── Page container ────────────────────────────────────────────────────────── */
.theory-main {
    max-width: 1400px;
    margin: 0 auto;
    padding: 40px 20px 80px;
}

/* ── Grid: 3 columns, widget dictates row height ───────────────────────────── */
.theory-grid {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 2rem;
    align-items: start;
}

/* ── Card ───────────────────────────────────────────────────────────────────── */
.theory-card {
    border: 1px solid var(--clr-border, #e8edf3);
    border-radius: 0.875rem;
    overflow: hidden;
    transition: border-color 0.15s ease;
}

.theory-card:hover {
    border-color: var(--clr-accent, #f39c12);
}

/* Meta section below the widget */
.theory-card-meta {
    padding: 1rem 1.25rem 1.25rem;
    border-top: 1px solid var(--clr-border, #e8edf3);
    background: var(--clr-surface, #ffffff);
}

.theory-card-title {
    font-size: 0.95rem;
    font-weight: 600;
    color: var(--clr-text, #1a1a2e);
    margin: 0 0 0.35rem;
}

.theory-card-summary {
    font-size: 0.82rem;
    color: var(--clr-text-muted, #64748b);
    line-height: 1.55;
    margin: 0 0 0.8rem;
}

/* ── Tag pills ─────────────────────────────────────────────────────────────── */
.theory-card-tags {
    display: flex;
    flex-wrap: wrap;
    gap: 0.35rem;
}

.theory-tag {
    font-size: 0.72rem;
    padding: 0.18rem 0.55rem;
    border-radius: 999px;
    background: var(--clr-surface-3, #eef1f5);
    color: var(--clr-text-muted, #64748b);
    border: 1px solid transparent;
    cursor: pointer;
    transition: background 120ms, color 120ms, border-color 120ms;
    user-select: none;
}

.theory-tag:hover,
.theory-tag.active {
    background: var(--clr-accent-bg, rgba(243,156,18,0.1));
    color: var(--clr-accent, #f39c12);
    border-color: var(--clr-accent-border, rgba(243,156,18,0.25));
}

/* ── Load more ─────────────────────────────────────────────────────────────── */
.theory-load-more {
    display: flex;
    justify-content: center;
    padding: 1.5rem 0 0.5rem;
    grid-column: 1 / -1;
}

.theory-load-more-btn {
    padding: 0.65rem 2rem;
    border-radius: 2rem;
    border: 1px solid var(--clr-accent, #f39c12);
    background: transparent;
    color: var(--clr-accent, #f39c12);
    font-size: 0.9rem;
    font-weight: 500;
    cursor: pointer;
    transition: background 150ms, color 150ms;
}

.theory-load-more-btn:hover {
    background: var(--clr-accent, #f39c12);
    color: #000;
}

/* ── Responsive ────────────────────────────────────────────────────────────── */
@media (max-width: 1024px) {
    .theory-grid { grid-template-columns: repeat(2, 1fr); }
}

@media (max-width: 640px) {
    .theory-grid { grid-template-columns: 1fr; }
    .theory-main { padding: 24px 16px 60px; }
}
</style>
