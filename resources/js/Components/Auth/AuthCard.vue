<script setup lang="ts">
defineProps<{
    title: string;
    subtitle?: string;
}>();
</script>

<template>
    <div class="sbn-auth-wrap">
        <div class="sbn-auth-card">
            <div class="sbn-auth-logo">
                <svg viewBox="0 0 32 32" fill="none" aria-hidden="true">
                    <defs>
                        <linearGradient id="sbn-auth-lg" x1="0" y1="0" x2="32" y2="32" gradientUnits="userSpaceOnUse">
                            <stop offset="0%" stop-color="#f39c12" />
                            <stop offset="100%" stop-color="#e74c3c" />
                        </linearGradient>
                    </defs>
                    <rect width="32" height="32" rx="8" fill="url(#sbn-auth-lg)" />
                    <path d="M8 22V10l8 6-8 6z" fill="#fff" opacity="0.9" />
                    <path d="M16 22V10l8 6-8 6z" fill="#fff" opacity="0.6" />
                </svg>
                <span>SBN <span class="sbn-auth-accent">Hub</span></span>
            </div>

            <h1 class="sbn-auth-title">{{ title }}</h1>
            <p v-if="subtitle" class="sbn-auth-sub">{{ subtitle }}</p>

            <slot />
        </div>
    </div>
</template>

<style scoped>
.sbn-auth-wrap {
    display: flex;
    align-items: flex-start;
    justify-content: center;
    padding: clamp(2rem, 6vw, 5rem) 1rem;
}

.sbn-auth-card {
    width: 100%;
    max-width: 400px;
    background: var(--clr-surface, #fff);
    border: 1px solid var(--clr-border, #e2e8f0);
    border-radius: var(--radius-lg, 16px);
    padding: 40px 36px;
    box-shadow: 0 4px 24px rgba(0, 0, 0, 0.06);
}

.sbn-auth-logo {
    display: flex;
    align-items: center;
    gap: 12px;
    margin-bottom: 28px;
}

.sbn-auth-logo svg {
    width: 36px;
    height: 36px;
}

.sbn-auth-logo span {
    font-size: 22px;
    font-weight: 700;
    letter-spacing: -0.02em;
    color: var(--clr-text);
}

.sbn-auth-accent {
    color: var(--clr-accent, #f39c12);
}

.sbn-auth-title {
    font-size: 18px;
    font-weight: 600;
    margin: 0 0 4px;
    color: var(--clr-text);
}

.sbn-auth-sub {
    color: var(--clr-text-muted, #5a5a5a);
    font-size: 13px;
    margin: 0 0 24px;
}
</style>

<!-- Unscoped: reaches slotted form content in the auth pages -->
<style>
.sbn-auth-field {
    margin-bottom: 18px;
}

.sbn-auth-remember {
    display: flex;
    align-items: center;
    gap: 8px;
    margin-bottom: 22px;
    font-size: 13px;
    color: var(--clr-text-muted, #5a5a5a);
    cursor: pointer;
}

.sbn-auth-submit {
    width: 100%;
    justify-content: center;
}

.sbn-auth-status {
    background: rgba(5, 150, 105, 0.08);
    border: 1px solid rgba(5, 150, 105, 0.2);
    color: #047857;
    padding: 10px 14px;
    border-radius: var(--radius, 8px);
    font-size: 13px;
    margin-bottom: 18px;
}

.sbn-auth-links {
    display: flex;
    justify-content: space-between;
    gap: 12px;
    margin-top: 20px;
    font-size: 13px;
}

.sbn-auth-links a {
    color: var(--clr-accent-dim, #e67e22);
    text-decoration: none;
    font-weight: 600;
}

.sbn-auth-links a:hover {
    text-decoration: underline;
}
</style>
