<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import PublicLayout from '@/Layouts/PublicLayout.vue';
import AccountLayout from '@/Layouts/AccountLayout.vue';
import CourseShelfCard, { type CourseShelfCardData } from '@/Components/Course/CourseShelfCard.vue';

defineOptions({ layout: [PublicLayout, AccountLayout] });

defineProps<{
    recentCourses: CourseShelfCardData[];
    orderCount: number;
}>();
</script>

<template>
    <div class="sbn-page sbn-page-detail">
            <header class="sbn-account-pageheader">
                <h1>Dashboard</h1>
                <p class="sbn-account-subtle">Pick up where you left off.</p>
            </header>

            <section class="sbn-account-section">
                <div class="sbn-account-section-header">
                    <h2>Continue learning</h2>
                    <Link href="/account/courses" class="sbn-account-section-link">All courses →</Link>
                </div>
                <div v-if="recentCourses.length === 0" class="sbn-account-empty">
                    <p>You don't have any courses yet.</p>
                    <Link href="/shop" class="sbn-account-empty-cta">Browse the shop →</Link>
                </div>
                <div v-else class="sbn-account-shelf">
                    <CourseShelfCard v-for="c in recentCourses" :key="c.id" :course="c" />
                </div>
            </section>

            <section class="sbn-account-section">
                <div class="sbn-account-section-header">
                    <h2>Orders</h2>
                    <Link href="/account/orders" class="sbn-account-section-link">View all →</Link>
                </div>
                <p class="sbn-account-subtle">{{ orderCount === 0 ? 'No orders yet.' : `${orderCount} order${orderCount === 1 ? '' : 's'} on file.` }}</p>
            </section>
    </div>
</template>
