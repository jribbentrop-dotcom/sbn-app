<script setup lang="ts">
import { defineAsyncComponent } from 'vue';
import { Head } from '@inertiajs/vue3';
import PublicLayout from '@/Layouts/PublicLayout.vue';

const GradesSlider = defineAsyncComponent(() => import('@/Components/Home/GradesSlider.vue'));

defineOptions({ layout: PublicLayout });
</script>

<template>
    <Head>
        <title>Grades Slider — Soul Bossa Nova</title>
        <meta name="description" content="A drag/keyboard grade slider — parked here while it awaits a permanent home." />
    </Head>

    <div class="home-page">
        <GradesSlider />
    </div>
</template>
