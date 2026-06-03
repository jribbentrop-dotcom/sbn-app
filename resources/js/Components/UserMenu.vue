<script setup lang="ts">
import { computed } from 'vue';
import { usePage, Link } from '@inertiajs/vue3';

const page = usePage();
const user = computed(() => page.props.auth?.user);
</script>

<template>
  <div class="user-menu sbn-btn sbn-btn-secondary sbn-btn-sm">
    <template v-if="user">
      <!-- Standard anchor tag to drop out of Inertia and load the Blade backend -->
      <a href="/admin" class="text-sm font-medium">{{ user.name || 'Dashboard' }}</a>
    </template>
    <template v-else>
      <Link href="/login" class="text-sm font-medium">Log in</Link>
      <span class="user-menu-sep" aria-hidden="true">·</span>
      <Link href="/register" class="text-sm font-medium">Sign up</Link>
    </template>
  </div>
</template>

<style scoped>
.user-menu-sep {
  opacity: 0.5;
  margin: 0 2px;
}
</style>
