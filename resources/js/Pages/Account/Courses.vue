<script setup lang="ts">
import { Link, Head } from '@inertiajs/vue3';
import PublicLayout from '@/Layouts/PublicLayout.vue';
import AccountLayout from '@/Layouts/AccountLayout.vue';
import CourseShelfCard, { type CourseShelfCardData } from '@/Components/Course/CourseShelfCard.vue';

defineOptions({ layout: [PublicLayout, AccountLayout] });

defineProps<{
    courses: CourseShelfCardData[];
}>();
</script>

<template>
    <Head><title>My Courses | Soul Bossa Nova</title></Head>
    <div class="sbn-page sbn-page-detail">
            <header class="sbn-account-pageheader">
                <h1>My Courses</h1>
                <p class="sbn-account-subtle">Everything you have access to.</p>
            </header>

            <div v-if="courses.length === 0" class="sbn-account-empty">
                <p>You don't own any courses yet.</p>
                <Link href="/shop" class="sbn-account-empty-cta">Browse the shop →</Link>
            </div>

            <div v-else class="sbn-account-shelf">
                <CourseShelfCard v-for="c in courses" :key="c.id" :course="c" />
            </div>
    </div>
</template>
