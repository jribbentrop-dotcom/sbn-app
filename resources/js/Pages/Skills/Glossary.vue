<script setup lang="ts">
import { computed, nextTick, onMounted, ref } from 'vue';
import { Head } from '@inertiajs/vue3';
import PublicLayout from '@/Layouts/PublicLayout.vue';
import SkillIcon from '@/Components/Skill/SkillIcon.vue';
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

    <div class="skill-glossary">
        <header class="skgl-hero">
            <div class="home-wrap">
                <div class="eyebrow">Reference</div>
                <h1>Skills Glossary</h1>
                <p class="skgl-lead">
                    Every skill in the curriculum, plainly defined — from your first open chord
                    to rootless jazz voicings. Browse the map of what there is to learn.
                </p>
            </div>
        </header>

        <div class="home-wrap">
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
                                    :size="26"
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
                            </div>
                        </li>
                    </ul>
                </section>
            </div>
        </div>
    </div>
</template>

<style scoped>
.skgl-hero {
    padding: 56px 0 24px;
    text-align: center;
}
.skgl-hero .eyebrow {
    font-size: .78rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: .1em;
    color: var(--clr-accent, #b8860b);
    margin-bottom: 10px;
}
.skgl-hero h1 {
    font-size: 2.6rem;
    margin: 0 0 12px;
}
.skgl-lead {
    max-width: 560px;
    margin: 0 auto;
    color: var(--clr-text-muted, #666);
    line-height: 1.6;
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
    background: color-mix(in srgb, var(--clr-surface-1, #fff) 92%, transparent);
    backdrop-filter: blur(6px);
    border-bottom: 1px solid var(--clr-border, #e5e5e5);
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
    color: var(--clr-text-muted, #888);
    transition: background .12s, color .12s;
}
.skgl-az-link:hover {
    background: color-mix(in srgb, var(--clr-accent, #b8860b) 12%, transparent);
    color: var(--clr-accent, #b8860b);
}

.skgl-groups {
    max-width: 760px;
    margin: 0 auto;
    padding-bottom: 64px;
}
.skgl-group + .skgl-group { margin-top: 32px; }

.skgl-letter {
    font-size: 1.4rem;
    color: var(--clr-accent, #b8860b);
    border-bottom: 2px solid color-mix(in srgb, var(--clr-accent, #b8860b) 25%, transparent);
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
    gap: 14px;
    scroll-margin-top: 72px;
    border-radius: 10px;
    transition: background .3s;
}
.skgl-entry.is-highlighted {
    background: color-mix(in srgb, var(--clr-accent, #b8860b) 14%, transparent);
    box-shadow: 0 0 0 8px color-mix(in srgb, var(--clr-accent, #b8860b) 14%, transparent);
}
.skgl-entry-icon {
    --_branch-clr: var(--clr-text-muted, #888);
    flex: 0 0 auto;
    width: 44px;
    height: 44px;
    border-radius: 10px;
    display: grid;
    place-items: center;
    color: var(--_branch-clr);
    background: color-mix(in srgb, var(--_branch-clr) 10%, transparent);
}
.skgl-entry-icon[data-branch="harmony"]        { --_branch-clr: #f39c12; }
.skgl-entry-icon[data-branch="rhythm"]         { --_branch-clr: #3b82f6; }
.skgl-entry-icon[data-branch="melody"]         { --_branch-clr: #ec4899; }
.skgl-entry-icon[data-branch="technique"]      { --_branch-clr: #10b981; }
.skgl-entry-icon[data-branch="ear-training"]   { --_branch-clr: #8b5cf6; }
.skgl-entry-icon[data-branch="reading-theory"] { --_branch-clr: #64748b; }

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
    margin: 0;
    line-height: 1.25;
}
.skgl-entry-grade {
    font-size: .72rem;
    font-weight: 700;
    color: var(--clr-accent, #b8860b);
    background: color-mix(in srgb, var(--clr-accent, #b8860b) 14%, transparent);
    padding: 1px 6px;
    border-radius: 999px;
}
.skgl-entry-branch {
    font-size: .72rem;
    text-transform: uppercase;
    letter-spacing: .05em;
    color: var(--clr-text-muted, #999);
}
.skgl-entry-desc {
    margin: 0;
    font-size: .92rem;
    line-height: 1.5;
    color: var(--clr-text, #333);
}

@media (max-width: 600px) {
    .skgl-hero h1 { font-size: 2rem; }
}
</style>
