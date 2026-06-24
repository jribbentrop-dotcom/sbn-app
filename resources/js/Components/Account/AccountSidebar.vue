<script setup lang="ts">
import { computed } from 'vue';
import { Link, usePage, router } from '@inertiajs/vue3';
import { useFaviconDot } from '@/composables/useFaviconDot';

const page = usePage();

const unread = computed<number>(() => {
    const a = page.props.account as { unread_count?: number } | null;
    return a?.unread_count ?? 0;
});

useFaviconDot(unread);

const user = computed(() => page.props.auth?.user ?? null);
const isInstructor = computed<boolean>(() => !!user.value?.is_instructor);

const current = computed(() => page.url);
const isActive = (prefix: string) => current.value === prefix || current.value.startsWith(prefix + '/') || current.value.startsWith(prefix + '?');

function logout(e: Event) {
    e.preventDefault();
    router.post('/logout');
}
</script>

<template>
    <aside class="sbn-account-sidebar">
        <div class="sbn-account-sidebar-header">
            <div class="sbn-account-greeting">Hi, {{ user?.name ?? 'there' }}</div>
            <div class="sbn-account-email">{{ user?.email }}</div>
        </div>
        <nav class="sbn-account-nav">
            <Link href="/account" class="sbn-account-nav-link" :class="{ 'is-active': current === '/account' }">Dashboard</Link>
            <Link href="/account/courses" class="sbn-account-nav-link" :class="{ 'is-active': isActive('/account/courses') }">My Courses</Link>
            <Link href="/account/skills" class="sbn-account-nav-link" :class="{ 'is-active': isActive('/account/skills') }">My Skills</Link>
            <Link href="/account/orders" class="sbn-account-nav-link" :class="{ 'is-active': isActive('/account/orders') }">Orders</Link>
            <Link href="/account/profile" class="sbn-account-nav-link" :class="{ 'is-active': isActive('/account/profile') }">Profile</Link>
            <Link v-if="!isInstructor" href="/account/messages" class="sbn-account-nav-link" :class="{ 'is-active': isActive('/account/messages') }">
                Messages
                <span v-if="unread > 0" class="sbn-account-badge">{{ unread }}</span>
            </Link>
            <Link v-if="!isInstructor" href="/community" class="sbn-account-nav-link" :class="{ 'is-active': isActive('/community') }">Community</Link>
            <Link v-if="isInstructor" href="/admin" class="sbn-account-nav-link">Admin →</Link>
        </nav>
        <div class="sbn-account-sidebar-footer">
            <a href="/logout" class="sbn-account-nav-link sbn-account-nav-link--muted" @click="logout">Sign out</a>
        </div>
    </aside>
</template>
