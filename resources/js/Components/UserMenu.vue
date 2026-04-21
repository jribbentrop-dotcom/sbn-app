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
      <a href="/login" class="text-sm font-medium">Log in</a>
    </template>
  </div>
</template>
