<script setup lang="ts">
import { computed, nextTick, onMounted, ref } from 'vue';
import { Head } from '@inertiajs/vue3';
import PublicLayout from '@/Layouts/PublicLayout.vue';
import SkillIcon from '@/Components/Skill/SkillIcon.vue';
import PracticeLinks, { type PracticeLinksData } from '@/Components/Skill/PracticeLinks.vue';
import { BRANCH_LABELS } from '@/Constants/skillBranches';

defineOptions({ layout: PublicLayout });

// Slug of the entry to highlight (from the URL hash on landing).
const highlighted = ref<string | null>(null);

// Inertia renders the page after navigation, so a native #hash scroll from
// another page has usually already fired against nothing. Re-run it once the
// list is in the DOM, and flash the target entry so it's findable.
onMounted(() => {
    const slug = decodeURIComponent(window.location.hash.replace(/^#/, ''));
    if (!slug) return;
    nextTick(() => {
        const el = document.getElementById(slug);
        if (!el) return;
        el.scrollIntoView({ behavior: 'smooth', block: 'center' });
        highlighted.value = slug;
        window.setTimeout(() => { highlighted.value = null; }, 2000);
    });
});

interface SkillEntry {
    slug: string;
    title: string;
    branch: string;
    subBranch: string | null;
    grade: number | null;
    gradeLabel: string | null;
    description: string | null;
    practice: PracticeLinksData;
    iconKey: string | null;
    iconPath: string | null;
}

const props = defineProps<{ skills: SkillEntry[] }>();

// Group alphabetically by first letter of the title (already sorted server-side).
const groups = computed(() => {
    const map = new Map<string, SkillEntry[]>();
    for (const s of props.skills) {
        const letter = (s.title[0] ?? '#').toUpperCase();
        const key = /[A-Z]/.test(letter) ? letter : '#';
        if (!map.has(key)) map.set(key, []);
        map.get(key)!.push(s);
    }
    return [...map.entries()];
});

const letters = computed(() => groups.value.map(([l]) => l));
</script>

<template>
    <Head title="Skills Glossary">
        <meta name="description" content="Every guitar skill in the Soul Bossa Nova curriculum, plainly defined — from your first open chord to rootless jazz voicings. A glossary of what there is to learn." />
        <meta property="og:title" content="Skills Glossary | Soul Bossa Nova" />
        <meta property="og:description" content="Every guitar skill in the curriculum, plainly defined and organised A–Z." />
        <meta property="og:type" content="website" />
    </Head>

    <div class="sbn-page skill-glossary">
        <header class="sbn-library-header">
            <h1 class="sbn-library-title">Skills Glossary</h1>
            <p class="sbn-library-subtitle">
                Every skill in the curriculum, plainly defined — from your first open chord
                to rootless jazz voicings. Browse the map of what there is to learn.
            </p>
        </header>

        <!-- A–Z quick jump -->
        <nav class="skgl-az" aria-label="Jump to letter">
            <a v-for="l in letters" :key="l" :href="`#letter-${l}`" class="skgl-az-link">{{ l }}</a>
        </nav>

        <div class="skgl-groups">
                <section v-for="[letter, entries] in groups" :key="letter" class="skgl-group">
                    <h2 :id="`letter-${letter}`" class="skgl-letter">{{ letter }}</h2>
                    <ul class="skgl-list">
                        <li v-for="entry in entries" :key="entry.slug" :id="entry.slug" class="skgl-entry" :class="{ 'is-highlighted': highlighted === entry.slug }">
                            <div class="skgl-entry-icon" :data-branch="entry.branch">
                                <SkillIcon
                                    :icon-path="entry.iconPath"
                                    :icon-key="entry.iconKey"
                                    :branch="entry.branch"
                                    :size="40"
                                />
                            </div>
                            <div class="skgl-entry-body">
                                <div class="skgl-entry-head">
                                    <h3 class="skgl-entry-title">{{ entry.title }}</h3>
                                    <span v-if="entry.grade" class="skgl-entry-grade" :title="entry.gradeLabel ?? ''">
                                        G{{ entry.grade }}
                                    </span>
                                    <span class="skgl-entry-branch">{{ BRANCH_LABELS[entry.branch] ?? entry.branch }}</span>
                                </div>
                                <p v-if="entry.description" class="skgl-entry-desc">{{ entry.description }}</p>
                                <PracticeLinks :practice="entry.practice" />
                            </div>
                        </li>
                    </ul>
                </section>
            </div>
        </div>
</template>

<style scoped>
/* Header uses the shared .sbn-library-header/-title/-subtitle chrome (same as
   the Theory / Chords / Rhythms index pages) — defined globally, no local
   overrides needed. Only the subtitle's reading width is constrained here. */
.skill-glossary :deep(.sbn-library-subtitle) {
    max-width: 560px;
    margin-left: auto;
    margin-right: auto;
}

/* A–Z jump bar */
.skgl-az {
    position: sticky;
    top: 0;
    z-index: 5;
    display: flex;
    flex-wrap: wrap;
    gap: 4px;
    justify-content: center;
    padding: 12px 0;
    margin-bottom: 24px;
    background: color-mix(in srgb, var(--clr-surface) 92%, transparent);
    backdrop-filter: blur(6px);
    border-bottom: 1px solid var(--clr-border);
}
.skgl-az-link {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 28px;
    height: 28px;
    border-radius: 6px;
    font-size: .82rem;
    font-weight: 700;
    text-decoration: none;
    color: var(--clr-text-muted);
    transition: background .12s, color .12s;
}
.skgl-az-link:hover {
    background: color-mix(in srgb, var(--clr-accent) 12%, transparent);
    color: var(--clr-accent);
}

.skgl-groups {
    max-width: 760px;
    margin: 0 auto;
    padding-bottom: 64px;
}
.skgl-group + .skgl-group { margin-top: 32px; }

.skgl-letter {
    font-family: var(--font-heading);
    font-size: 1.4rem;
    color: var(--clr-accent);
    border-bottom: 2px solid color-mix(in srgb, var(--clr-accent) 25%, transparent);
    padding-bottom: 4px;
    margin: 0 0 16px;
    scroll-margin-top: 64px;
}

.skgl-list {
    list-style: none;
    margin: 0;
    padding: 0;
    display: flex;
    flex-direction: column;
    gap: 18px;
}

.skgl-entry {
    display: flex;
    align-items: flex-start;
    gap: 14px;
    scroll-margin-top: 72px;
    border-radius: 10px;
    transition: background .3s;
}
.skgl-entry.is-highlighted {
    /* padded tint block (no shadow) so the deep-link target flashes without
       a glow ring; negative margin keeps the row from shifting when it lands */
    background: color-mix(in srgb, var(--clr-accent) 14%, transparent);
    padding: 8px;
    margin: -8px;
}
.skgl-entry-icon {
    --_branch-clr: var(--clr-text-muted);
    flex: 0 0 auto;
    display: grid;
    place-items: center;
    color: var(--_branch-clr);
}
.skgl-entry-icon[data-branch="harmony"]        { --_branch-clr: var(--clr-branch-harmony); }
.skgl-entry-icon[data-branch="rhythm"]         { --_branch-clr: var(--clr-branch-rhythm); }
.skgl-entry-icon[data-branch="melody"]         { --_branch-clr: var(--clr-branch-melody); }
.skgl-entry-icon[data-branch="technique"]      { --_branch-clr: var(--clr-branch-technique); }
.skgl-entry-icon[data-branch="ear-training"]   { --_branch-clr: var(--clr-branch-ear-training); }
.skgl-entry-icon[data-branch="reading-theory"] { --_branch-clr: var(--clr-branch-reading-theory); }

.skgl-entry-body { flex: 1 1 auto; min-width: 0; }
.skgl-entry-head {
    display: flex;
    align-items: baseline;
    flex-wrap: wrap;
    gap: 8px;
    margin-bottom: 2px;
}
.skgl-entry-title {
    font-size: 1.05rem;
    font-weight: 700;
    margin: 0;
    line-height: 1.25;
}
.skgl-entry-grade {
    font-size: .72rem;
    font-weight: 700;
    color: var(--clr-accent);
    background: color-mix(in srgb, var(--clr-accent) 14%, transparent);
    padding: 1px 6px;
    border-radius: 999px;
}
.skgl-entry-branch {
    font-size: .72rem;
    text-transform: uppercase;
    letter-spacing: .05em;
    color: var(--clr-text-muted);
}
.skgl-entry-desc {
    margin: 0;
    font-size: .92rem;
    line-height: 1.5;
    color: var(--clr-text);
}

</style>
