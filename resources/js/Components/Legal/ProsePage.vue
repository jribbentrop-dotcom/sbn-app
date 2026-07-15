<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import PublicLayout from '@/Layouts/PublicLayout.vue';

defineOptions({ layout: PublicLayout });

/*
 * Shared shell for the long-form legal/marketing text pages
 * (Impressum, Privacy Policy, Terms, About). Content lives in the slot as
 * plain semantic HTML; the .legal-prose styles below handle typography so the
 * individual pages stay content-only.
 *
 * `noindex` defaults to true: these pages currently ship with
 * [BRACKETED PLACEHOLDERS] and should stay out of the index until real
 * business details are filled in. Flip to :noindex="false" per page once done.
 */
withDefaults(defineProps<{
    title: string;
    /** Optional "Last updated: …" line rendered under the title. */
    updated?: string | null;
    noindex?: boolean;
}>(), {
    updated: null,
    noindex: true,
});
</script>

<template>
    <Head>
        <title>{{ title }} — Soul Bossa Nova</title>
        <meta v-if="noindex" name="robots" content="noindex" />
    </Head>

    <main class="sbn-page legal-page">
        <article class="legal-prose">
            <header class="legal-head">
                <h1 class="legal-title">{{ title }}</h1>
                <p v-if="updated" class="legal-updated">{{ updated }}</p>
            </header>
            <slot />
        </article>
    </main>
</template>

<style scoped>
.legal-page {
    max-width: 760px;
    margin: 0 auto;
    padding: 56px 20px 96px;
}

.legal-head {
    margin-bottom: 40px;
    padding-bottom: 24px;
    border-bottom: 1px solid var(--sbn-border);
}
.legal-title {
    font-family: var(--font-display);
    font-size: clamp(2rem, 5vw, 2.9rem);
    font-weight: 500;
    line-height: 1.1;
    letter-spacing: -0.01em;
    margin: 0;
    color: var(--sbn-dark);
}
.legal-updated {
    font-family: var(--font-mono);
    font-size: 0.8rem;
    letter-spacing: 0.02em;
    color: var(--sbn-text);
    margin: 14px 0 0;
}

/* Long-form prose — styles the semantic HTML passed in via the slot. */
.legal-prose :deep(h1) {
    font-family: var(--font-display);
    font-size: 1.7rem;
    font-weight: 500;
    line-height: 1.2;
    color: var(--sbn-dark);
    margin: 48px 0 16px;
}
.legal-prose :deep(h2) {
    font-family: var(--font-display);
    font-size: 1.35rem;
    font-weight: 500;
    line-height: 1.25;
    color: var(--sbn-dark);
    margin: 44px 0 14px;
}
.legal-prose :deep(h3) {
    font-size: 1.02rem;
    font-weight: 600;
    letter-spacing: 0.01em;
    color: var(--sbn-dark);
    margin: 30px 0 10px;
}
.legal-prose :deep(p),
.legal-prose :deep(li) {
    color: var(--sbn-text);
    line-height: 1.7;
    font-size: 1rem;
}
.legal-prose :deep(p) { margin: 0 0 18px; }
.legal-prose :deep(ul) {
    margin: 0 0 18px;
    padding-left: 1.25rem;
}
.legal-prose :deep(li) { margin: 0 0 8px; }
.legal-prose :deep(li::marker) { color: var(--sbn-border); }
.legal-prose :deep(strong) { color: var(--sbn-dark); font-weight: 600; }
.legal-prose :deep(a) { color: var(--sbn-orange); font-weight: 500; }
.legal-prose :deep(a:hover) { color: var(--sbn-red); }
.legal-prose :deep(hr) {
    border: none;
    border-top: 1px solid var(--sbn-border);
    margin: 40px 0;
}

/* Tables (e.g. the Cookie Policy cookie inventory) — scroll on narrow screens
   via the .legal-table-wrap container so the page body never overflows. */
.legal-prose :deep(.legal-table-wrap) {
    overflow-x: auto;
    margin: 0 0 22px;
}
.legal-prose :deep(table) {
    width: 100%;
    border-collapse: collapse;
    font-size: 0.92rem;
}
.legal-prose :deep(th),
.legal-prose :deep(td) {
    text-align: left;
    padding: 10px 14px;
    border-bottom: 1px solid var(--sbn-border);
    color: var(--sbn-text);
    line-height: 1.5;
    vertical-align: top;
}
.legal-prose :deep(th) {
    font-family: var(--font-mono);
    font-size: 0.74rem;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    font-weight: 500;
    color: var(--sbn-dark);
    white-space: nowrap;
}
.legal-prose :deep(tbody tr:last-child td) { border-bottom: none; }
/* Address / contact blocks keep their line breaks without paragraph spacing. */
.legal-prose :deep(.legal-block) {
    margin: 0 0 18px;
    line-height: 1.7;
    color: var(--sbn-text);
}
.legal-prose :deep(.legal-block strong) { color: var(--sbn-dark); }
</style>
