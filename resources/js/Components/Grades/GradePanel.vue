<script setup lang="ts">
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

interface GradePanelData {
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
