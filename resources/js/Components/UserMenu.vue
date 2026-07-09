<script setup lang="ts">
import { computed } from 'vue';
import { usePage, Link } from '@inertiajs/vue3';
import { useAuthModal } from '@/composables/useAuthModal';

const page = usePage();
const user = computed(() => page.props.auth?.user);
const { open: openAuthModal } = useAuthModal();
</script>

<template>
  <div class="user-menu">
    <template v-if="user">
      <a
        v-if="user.is_instructor"
        href="/admin"
        class="user-menu-icon-link"
        :aria-label="user.name || 'Admin'"
        :title="user.name || 'Admin'"
      >
        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
          <path stroke-linecap="round" stroke-linejoin="round" d="M9 6.75V15m6-6v8.25m.503 3.498 4.875-2.437c.381-.19.622-.58.622-1.006V4.82c0-.836-.88-1.38-1.628-1.006l-3.869 1.934c-.317.159-.69.159-1.006 0L9.503 3.252a1.125 1.125 0 0 0-1.006 0L3.622 5.689C3.24 5.88 3 6.27 3 6.695V19.18c0 .836.88 1.38 1.628 1.006l3.869-1.934c.317-.159.69-.159 1.006 0l4.994 2.497c.317.158.69.158 1.006 0Z" />
        </svg>
      </a>
      <Link
        v-else
        href="/account"
        class="user-menu-icon-link"
        :aria-label="user.name || 'Account'"
        :title="user.name || 'Account'"
      >
        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
          <path stroke-linecap="round" stroke-linejoin="round" d="M17.982 18.725A7.488 7.488 0 0 0 12 15.75a7.488 7.488 0 0 0-5.982 2.975m11.964 0a9 9 0 1 0-11.964 0m11.964 0A8.966 8.966 0 0 1 12 21a8.966 8.966 0 0 1-5.982-2.275M15 9.75a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z" />
        </svg>
      </Link>
    </template>
    <template v-else>
      <a
        href="/login"
        class="user-menu-icon-link"
        aria-label="Log in"
        title="Log in"
        @click.prevent="openAuthModal('login')"
      >
        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
          <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 9V5.25A2.25 2.25 0 0 0 13.5 3h-6a2.25 2.25 0 0 0-2.25 2.25v13.5A2.25 2.25 0 0 0 7.5 21h6a2.25 2.25 0 0 0 2.25-2.25V15M12 9l3 3m0 0-3 3m3-3H3" />
        </svg>
      </a>
      <a
        href="/register"
        class="user-menu-icon-link"
        aria-label="Sign up"
        title="Sign up"
        @click.prevent="openAuthModal('register')"
      >
        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
          <path stroke-linecap="round" stroke-linejoin="round" d="M18 7.5v6m3-3h-6M6.75 8.25a3 3 0 1 1 6 0 3 3 0 0 1-6 0ZM2.25 20.25a6.75 6.75 0 0 1 13.5 0v.001c0 .052-.001.104-.004.155A.75.75 0 0 1 15 21H3a.75.75 0 0 1-.746-.594 5.99 5.99 0 0 1-.004-.155v-.001Z" />
        </svg>
      </a>
    </template>
  </div>
</template>

<style scoped>
.user-menu {
  display: flex;
  align-items: center;
  gap: 4px;
}

.user-menu-icon-link {
  position: relative;
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 8px 12px;
  color: var(--sbn-dark);
  transition: all 0.2s ease;
  border-radius: var(--sbn-radius-sm);
  min-height: 44px;
  touch-action: manipulation;
}

@media (hover: hover) and (pointer: fine) {
  .user-menu-icon-link:hover {
    background: var(--sbn-gradient);
    color: var(--clr-white);
    transform: translateY(-1px);
  }
}

.user-menu-icon-link svg {
  width: 26px;
  height: 26px;
}
</style>
