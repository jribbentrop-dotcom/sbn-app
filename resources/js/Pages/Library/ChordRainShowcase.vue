<script setup lang="ts">
import { defineAsyncComponent } from 'vue';
import { Head } from '@inertiajs/vue3';
import PublicLayout from '@/Layouts/PublicLayout.vue';
import type { ChordShape } from '@/Components/Home/ChordRain.vue';

const ChordRain = defineAsyncComponent(() => import('@/Components/Home/ChordRain.vue'));

defineOptions({ layout: PublicLayout });

defineProps<{
    rainChords: ChordShape[];
}>();
</script>

<template>
    <Head>
        <title>Chord Rain — Soul Bossa Nova</title>
        <meta name="description" content="A visual showcase of the chord voicing library — parked here while it awaits a permanent home." />
    </Head>

    <div class="home-page">
        <ChordRain v-if="rainChords?.length" :chords="rainChords" />
    </div>
</template>
