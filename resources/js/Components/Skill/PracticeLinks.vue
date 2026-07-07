<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';

export interface PracticeLinkItem { slug: string; title: string; url: string }
export interface PracticeLinkGroup { items: PracticeLinkItem[]; more: number }
export interface PracticeLinksData {
    courses: PracticeLinkGroup;
    rhythmPatterns: PracticeLinkGroup;
    chordProgressions: PracticeLinkGroup;
    leadsheets: PracticeLinkGroup;
    chordCategoryLabel: string | null;
    chordLibraryUrl: string | null;
}

const props = defineProps<{ practice: PracticeLinksData }>();

/** Hard cap so one long title can't dominate a card row; full text is still in the title= tooltip. */
const CHIP_TEXT_MAX = 28;
function truncate(text: string): string {
    return text.length > CHIP_TEXT_MAX ? text.slice(0, CHIP_TEXT_MAX - 1).trimEnd() + '…' : text;
}

const hasAny = computed(() =>
    props.practice.courses.items.length > 0
    || props.practice.rhythmPatterns.items.length > 0
    || props.practice.chordProgressions.items.length > 0
    || props.practice.leadsheets.items.length > 0
    || !!props.practice.chordCategoryLabel
);
</script>

<template>
    <div v-if="hasAny" class="sbn-practice-links">
        <div v-if="practice.courses.items.length" class="sbn-practice-group">
            <span class="sbn-practice-group-label">Courses</span>
            <Link v-for="c in practice.courses.items" :key="'course-' + c.slug" :href="c.url" :title="c.title" class="sbn-practice-chip sbn-practice-chip--course">
                {{ truncate(c.title) }}
            </Link>
            <span v-if="practice.courses.more" class="sbn-practice-chip-more">+{{ practice.courses.more }} more</span>
        </div>

        <div v-if="practice.chordCategoryLabel" class="sbn-practice-group">
            <span class="sbn-practice-group-label">Chords</span>
            <Link :href="practice.chordLibraryUrl!" class="sbn-practice-chip sbn-practice-chip--chord">
                {{ truncate(practice.chordCategoryLabel + ' voicings') }}
            </Link>
        </div>

        <div v-if="practice.rhythmPatterns.items.length" class="sbn-practice-group">
            <span class="sbn-practice-group-label">Rhythms</span>
            <Link v-for="r in practice.rhythmPatterns.items" :key="'rhythm-' + r.slug" :href="r.url" :title="r.title" class="sbn-practice-chip sbn-practice-chip--rhythm">
                {{ truncate(r.title) }}
            </Link>
            <span v-if="practice.rhythmPatterns.more" class="sbn-practice-chip-more">+{{ practice.rhythmPatterns.more }} more</span>
        </div>

        <div v-if="practice.chordProgressions.items.length" class="sbn-practice-group">
            <span class="sbn-practice-group-label">Progressions</span>
            <Link v-for="p in practice.chordProgressions.items" :key="'prog-' + p.slug" :href="p.url" :title="p.title" class="sbn-practice-chip sbn-practice-chip--progression">
                {{ truncate(p.title) }}
            </Link>
            <span v-if="practice.chordProgressions.more" class="sbn-practice-chip-more">+{{ practice.chordProgressions.more }} more</span>
        </div>

        <div v-if="practice.leadsheets.items.length" class="sbn-practice-group">
            <span class="sbn-practice-group-label">Songs</span>
            <Link v-for="s in practice.leadsheets.items" :key="'song-' + s.slug" :href="s.url" :title="s.title" class="sbn-practice-chip sbn-practice-chip--song">
                {{ truncate(s.title) }}
            </Link>
            <span v-if="practice.leadsheets.more" class="sbn-practice-chip-more">+{{ practice.leadsheets.more }} more</span>
        </div>
    </div>
</template>
