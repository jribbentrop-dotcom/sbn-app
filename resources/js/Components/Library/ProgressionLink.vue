<script setup lang="ts">
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import { getCategoryColor } from '@/composables/useCategoryColors';

/** Compact progression reference — mirrors ProgressionRef on the backend. */
export interface ProgressionLinkData {
    id: number;
    slug: string;
    name: string;
    category: string;
    numeralsDisplay: string;
    /** Optional: when provided the link appends ?chord=<chordSlug>&highlight=<slot> */
    pinnedChordSlug?: string | null;
    pinnedSlot?: number | null;
}

const props = defineProps<{ progression: ProgressionLinkData }>();

const categoryLabel = computed(() =>
    (props.progression.category ?? 'progression').replace(/-/g, ' ')
);

const href = computed(() => {
    const base = `/library/progressions/${props.progression.slug}`;
    if (props.progression.pinnedChordSlug) {
        return `${base}?chord=${encodeURIComponent(props.progression.pinnedChordSlug)}&highlight=${props.progression.pinnedSlot ?? 0}`;
    }
    return base;
});

const numerals = computed(() =>
    props.progression.numeralsDisplay
        .split('–')
        .map((s) => s.trim())
        .filter(Boolean)
);

const color = computed(() => getCategoryColor(props.progression.category));
</script>

<template>
    <Link :href="href" class="sbn-prog-link" :style="{ '--prog-clr': color }">
        <span class="sbn-prog-link__name">{{ progression.name }}</span>
        <div class="sbn-numeral-chip-row">
            <span
                v-for="n in numerals"
                :key="n"
                class="sbn-numeral-chip"
            >{{ n }}</span>
        </div>
        <span class="sbn-cat-badge sbn-cat-badge-filled" :style="{ '--cat-clr': color }">
            {{ categoryLabel }}
        </span>
    </Link>
</template>
