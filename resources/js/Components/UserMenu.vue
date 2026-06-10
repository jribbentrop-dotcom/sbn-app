<script setup lang="ts">
import { computed } from 'vue';
import { usePage, Link } from '@inertiajs/vue3';

const page = usePage();
const user = computed(() => page.props.auth?.user);
</script>

<template>
  <div class="user-menu">
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
.user-menu {
  display: flex;
  align-items: center;
  gap: 2px;
  padding: 6px 14px;
  border: 1px solid var(--sbn-border);
  border-radius: 999px;
  font-size: 0.88rem;
  font-weight: 500;
  color: var(--sbn-dark);
  transition: background 0.18s ease, border-color 0.18s ease;
  white-space: nowrap;
}

.user-menu:hover {
  background: var(--sbn-border);
}

.user-menu a {
  color: inherit;
  text-decoration: none;
}

.user-menu-sep {
  opacity: 0.35;
  margin: 0 3px;
}
</style>
