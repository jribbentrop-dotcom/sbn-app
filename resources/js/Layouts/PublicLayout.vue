<script setup lang="ts">
import { onMounted, onUnmounted, ref } from 'vue';
import { Link } from '@inertiajs/vue3';
import MegaMenu from './../Components/MegaMenu.vue';
import Footer from './../Components/Footer.vue';
import AudioPlayerSlot from './../Components/AudioPlayerSlot.vue';
import TheoryWidgetModal from './../Components/TheoryWidgetModal.vue';
import AuthModal from './../Components/Auth/AuthModal.vue';

const headerRef = ref<HTMLElement | null>(null);
let resizeObserver: ResizeObserver | null = null;

onMounted(() => {
    console.log('[PublicLayout] mounted - persistent layout initialized.');

    if (headerRef.value) {
        resizeObserver = new ResizeObserver(() => {
            const height = headerRef.value?.getBoundingClientRect().height || 102;
            document.documentElement.style.setProperty('--header-height', `${height}px`);
        });
        resizeObserver.observe(headerRef.value);
    }
});

onUnmounted(() => {
    resizeObserver?.disconnect();
});
</script>

<template>
    <div class="min-h-screen flex flex-col items-stretch">
        <header class="site-header" ref="headerRef">
            <div class="header-inner">
                <div class="site-branding">
                    <Link href="/" aria-label="Soul Bossa Nova — home">
                        <img src="/images/soulbossanova.png" alt="Soul Bossa Nova">
                    </Link>
                </div>
                
                <MegaMenu />
            </div>
        </header>

        <main id="main-content" class="site-main">
            <slot />
        </main>

        <Footer />
        <AudioPlayerSlot />
        <TheoryWidgetModal />
        <AuthModal />
    </div>
</template>

<style>
/* 
 * We rely on the global CSS from tailwind & legacy CSS.
 * For the layout, min-height screen ensures footer at bottom. 
 */
</style>
