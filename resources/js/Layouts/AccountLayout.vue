<script setup lang="ts">
import AccountSidebar from '../Components/Account/AccountSidebar.vue';
</script>

<template>
    <div class="sbn-account-shell">
        <AccountSidebar />
        <div class="sbn-account-main">
            <slot />
        </div>
    </div>
</template>
