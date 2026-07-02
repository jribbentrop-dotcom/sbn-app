<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import SkillIcon from '@/Components/Skill/SkillIcon.vue';

interface LibraryItem {
    id: number;
    slug: string;
    name?: string;
    title?: string;
    url: string;
    category?: string;
    numerals?: string;
    composer?: string;
    key?: string;
    image?: string | null;
}

interface SkillItem {
    id: number;
    slug: string;
    title: string;
    branch: string;
    iconKey: string | null;
    iconPath: string | null;
    url: string;
}

interface GradePanelData {
    skills: SkillItem[];
    chords: LibraryItem[];
    rhythms: LibraryItem[];
    progressions: LibraryItem[];
    songs: LibraryItem[];
    courses: LibraryItem[];
}

const props = defineProps<{
    gradeN: number;
    gradeClr: string;
    gradeLabel: string;
    data: GradePanelData;
    allUrl: string;
}>();

const sections = [
    { key: 'chords',       label: 'Chords',       icon: '♩', nameKey: 'name'  as const },
    { key: 'rhythms',      label: 'Rhythms',      icon: '♪', nameKey: 'name'  as const },
    { key: 'progressions', label: 'Progressions', icon: '≡', nameKey: 'name'  as const },
    { key: 'songs',        label: 'Songs',        icon: '♫', nameKey: 'title' as const },
    { key: 'courses',      label: 'Courses',      icon: '▶', nameKey: 'title' as const },
] as const;

function itemLabel(item: LibraryItem, nameKey: 'name' | 'title'): string {
    return (item[nameKey] as string | undefined) ?? item.name ?? item.title ?? '';
}

function itemSub(item: LibraryItem, key: string): string | null {
    if (key === 'progressions') return item.numerals ?? null;
    if (key === 'songs') return [item.composer, item.key].filter(Boolean).join(' · ') || null;
    if (key === 'rhythms') return item.category ?? null;
    return null;
}
</script>

<template>
    <div class="grade-panel" :style="`--panel-clr: ${gradeClr}`">
        <!-- Skills at this grade — the actual abilities you build here -->
        <div v-if="data.skills && data.skills.length" class="grade-panel-skills">
            <div class="gps-header">
                <span class="gps-icon">◆</span>
                <span class="gps-label">Skills you build at this grade</span>
            </div>
            <ul class="gp-skill-list">
                <li v-for="skill in data.skills" :key="skill.id">
                    <Link :href="skill.url" class="gp-skill" :data-branch="skill.branch">
                        <span class="gp-skill-icon">
                            <SkillIcon
                                :icon-path="skill.iconPath"
                                :icon-key="skill.iconKey"
                                :branch="skill.branch"
                                :size="22"
                            />
                        </span>
                        <span class="gp-skill-title">{{ skill.title }}</span>
                    </Link>
                </li>
            </ul>
        </div>

        <div class="grade-panel-inner">
            <div
                v-for="sec in sections"
                :key="sec.key"
                class="grade-panel-section"
            >
                <div class="gps-header">
                    <span class="gps-icon">{{ sec.icon }}</span>
                    <span class="gps-label">{{ sec.label }}</span>
                </div>

                <template v-if="data[sec.key].length">
                    <ul class="gps-list">
                        <li
                            v-for="item in data[sec.key]"
                            :key="item.id"
                            class="gps-item"
                        >
                            <a :href="item.url" class="gps-link">
                                <span class="gps-name">{{ itemLabel(item, sec.nameKey) }}</span>
                                <span v-if="itemSub(item, sec.key)" class="gps-sub">{{ itemSub(item, sec.key) }}</span>
                            </a>
                        </li>
                    </ul>
                </template>
                <p v-else class="gps-empty">None tagged yet</p>
            </div>
        </div>
    </div>
</template>

<style scoped>
.grade-panel {
    overflow: hidden;
    background: var(--clr-surface-1, #fff);
}

/* ── Skills strip (full-width, above the library grid) ─────────────── */
.grade-panel-skills {
    padding: 20px 20px 4px;
}
.gp-skill-list {
    list-style: none;
    margin: 10px 0 0;
    padding: 0;
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
}
.gp-skill {
    --_branch-clr: var(--panel-clr);
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 6px 12px 6px 8px;
    border-radius: 999px;
    border: 1px solid color-mix(in srgb, var(--_branch-clr) 40%, transparent);
    background: var(--clr-surface-1, #fff);
    text-decoration: none;
    transition: background .12s, border-color .12s;
}
.gp-skill:hover {
    background: color-mix(in srgb, var(--_branch-clr) 10%, transparent);
    border-color: color-mix(in srgb, var(--_branch-clr) 65%, transparent);
}
.gp-skill-icon {
    display: flex;
    color: var(--_branch-clr);
}
.gp-skill-title {
    font-size: .8rem;
    font-weight: 600;
    color: var(--clr-text, #1d2127);
    line-height: 1.25;
}
.gp-skill[data-branch="harmony"]        { --_branch-clr: #f39c12; }
.gp-skill[data-branch="rhythm"]         { --_branch-clr: #3b82f6; }
.gp-skill[data-branch="melody"]         { --_branch-clr: #ec4899; }
.gp-skill[data-branch="technique"]      { --_branch-clr: #10b981; }
.gp-skill[data-branch="ear-training"]   { --_branch-clr: #8b5cf6; }
.gp-skill[data-branch="reading-theory"] { --_branch-clr: #64748b; }

.grade-panel-inner {
    display: grid;
    grid-template-columns: repeat(5, 1fr);
    gap: 0;
    padding: 8px 0 32px;
}

.grade-panel-section {
    padding: 0 20px;
    border-right: 1px solid var(--clr-border, #e5e5e5);
}
.grade-panel-section:last-child {
    border-right: none;
}

.gps-header {
    display: flex;
    align-items: center;
    gap: 6px;
    margin-bottom: 12px;
}
.gps-icon {
    font-size: .95rem;
    color: var(--panel-clr);
}
.gps-label {
    font-size: .7rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: .08em;
    color: var(--clr-text-muted, #888);
}

.gps-list {
    list-style: none;
    margin: 0;
    padding: 0;
    display: flex;
    flex-direction: column;
    gap: 2px;
}

.gps-item {}

.gps-link {
    display: flex;
    flex-direction: column;
    padding: 5px 8px;
    border-radius: 6px;
    text-decoration: none;
    transition: background .12s;
}
.gps-link:hover {
    background: color-mix(in srgb, var(--panel-clr) 10%, transparent);
}

.gps-name {
    font-size: .82rem;
    font-weight: 600;
    color: var(--clr-text, #1d2127);
    line-height: 1.3;
}

.gps-sub {
    font-size: .72rem;
    color: var(--clr-text-muted, #888);
    line-height: 1.3;
    margin-top: 1px;
}

.gps-empty {
    font-size: .75rem;
    color: var(--clr-text-muted, #888);
    font-style: italic;
    padding: 5px 8px;
    margin: 0;
}

@media (max-width: 900px) {
    .grade-panel-inner {
        grid-template-columns: repeat(2, 1fr);
    }
    .grade-panel-section {
        border-right: none;
        border-bottom: 1px solid var(--clr-border, #e5e5e5);
        padding: 16px;
    }
    .grade-panel-section:last-child {
        border-bottom: none;
    }
}

@media (max-width: 540px) {
    .grade-panel-inner {
        grid-template-columns: 1fr;
    }
}
</style>
