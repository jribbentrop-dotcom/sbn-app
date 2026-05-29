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
    /** Optional: when provided the link appends ?chord=<chordSlug>&highlight=<slot>[&root=<note>] */
    pinnedChordSlug?: string | null;
    pinnedSlot?: number | null;
    /** The effective root note at the time of navigation (may differ from the DB root via ?root= or alias). */
    pinnedChordRoot?: string | null;
}

const props = defineProps<{ progression: ProgressionLinkData }>();

const categoryLabel = computed(() =>
    (props.progression.category ?? 'progression').replace(/-/g, ' ')
);

const href = computed(() => {
    const base = `/library/progressions/${props.progression.slug}`;
    if (props.progression.pinnedChordSlug) {
        let url = `${base}?chord=${encodeURIComponent(props.progression.pinnedChordSlug)}&highlight=${props.progression.pinnedSlot ?? 0}`;
        if (props.progression.pinnedChordRoot) {
            url += `&root=${encodeURIComponent(props.progression.pinnedChordRoot)}`;
        }
        return url;
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
