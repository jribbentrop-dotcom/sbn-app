<script setup lang="ts">
import { ref, onMounted, onUnmounted, watch } from 'vue';
import { Link } from '@inertiajs/vue3';
import UserMenu from './UserMenu.vue';
import { useCart } from '@/composables/useCart';

const { count, openCart } = useCart();
const openMenu = ref<string | null>(null);
const switching = ref(false);
const drawerOpen = ref(false);
const drawerSection = ref<string | null>(null);

watch(openMenu, (newVal, oldVal) => {
    // Switching directly between two open panels: suppress the 8px entrance
    // rise so the incoming panel appears already docked (no gap under the header).
    switching.value = !!newVal && !!oldVal;

    if (newVal) {
        document.body.classList.add('mega-menu-open');
    } else {
        document.body.classList.remove('mega-menu-open');
    }
});

watch(drawerOpen, (newVal) => {
    document.body.classList.toggle('mobile-drawer-open', newVal);
});

const toggleMenu = (menuName: string, event: Event) => {
    if (openMenu.value === menuName) {
        openMenu.value = null;
    } else {
        openMenu.value = menuName;
    }
    event.preventDefault();
    event.stopPropagation();
};

const closeAllMenus = () => {
    openMenu.value = null;
};

/* ---- Hover intent (Stripe-style): open after a short delay, close on a
   grace period so the trigger→panel gap and panel-to-panel moves don't flicker.
   Pointer-only: skipped on touch/coarse pointers, where click drives the menu. */
const OPEN_DELAY = 80;
const CLOSE_DELAY = 150;
let openTimer: ReturnType<typeof setTimeout> | null = null;
let closeTimer: ReturnType<typeof setTimeout> | null = null;

const hasHover = () =>
    typeof window !== 'undefined' &&
    window.matchMedia('(hover: hover) and (pointer: fine)').matches;

const clearTimers = () => {
    if (openTimer) { clearTimeout(openTimer); openTimer = null; }
    if (closeTimer) { clearTimeout(closeTimer); closeTimer = null; }
};

const onMenuEnter = (menuName: string) => {
    if (!hasHover()) return;
    clearTimers();
    // Switch panels instantly if one is already open; otherwise wait out the open delay.
    if (openMenu.value) {
        openMenu.value = menuName;
    } else {
        openTimer = setTimeout(() => { openMenu.value = menuName; }, OPEN_DELAY);
    }
};

const onMenuLeave = () => {
    if (!hasHover()) return;
    if (openTimer) { clearTimeout(openTimer); openTimer = null; }
    closeTimer = setTimeout(() => { openMenu.value = null; }, CLOSE_DELAY);
};

const toggleDrawer = () => {
    drawerOpen.value = !drawerOpen.value;
    if (!drawerOpen.value) drawerSection.value = null;
};

const closeDrawer = () => {
    drawerOpen.value = false;
    drawerSection.value = null;
};

const toggleDrawerSection = (section: string) => {
    drawerSection.value = drawerSection.value === section ? null : section;
};

const handleGlobalClick = (event: MouseEvent) => {
    if (!openMenu.value) return;
    const target = event.target as HTMLElement;
    if (target.closest('.menu-item-has-children > a') || target.closest('.sub-menu')) {
        return;
    }
    closeAllMenus();
};

const handleKeyDown = (event: KeyboardEvent) => {
    if (event.key === 'Escape') {
        closeAllMenus();
        closeDrawer();
    }
};

onMounted(() => {
    document.addEventListener('click', handleGlobalClick);
    document.addEventListener('keydown', handleKeyDown);
});

onUnmounted(() => {
    document.removeEventListener('click', handleGlobalClick);
    document.removeEventListener('keydown', handleKeyDown);
    clearTimers();
});
</script>

<template>
    <nav class="main-navigation" :class="{ 'is-switching': switching }" role="navigation" aria-label="Primary Menu">
        <ul class="main-menu">

            <!-- Home -->
            <li class="menu-item">
                <Link href="/" @click="closeAllMenus">Home</Link>
            </li>

            <!-- Courses mega panel -->
            <li class="menu-item menu-item-has-children"
                :class="{ 'manual-hover': openMenu === 'courses' }"
                @mouseenter="onMenuEnter('courses')" @mouseleave="onMenuLeave">
                <a href="#" role="button" aria-haspopup="true"
                   :aria-expanded="openMenu === 'courses'" aria-controls="mega-panel-courses"
                   @click="toggleMenu('courses', $event)">Courses</a>
                <ul class="sub-menu" id="mega-panel-courses">
                    <!-- Card grid -->
                    <li class="mega-col-cards">
                        <Link href="/learn?level=basic" class="mega-card" @click="closeAllMenus">
                            <div class="mega-ic">
                                <!-- bars-3-bottom-left / levels -->
                                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M3 4.5h14.25M3 9h9.75M3 13.5h9.75m4.5-4.5v12m0 0-3.75-3.75M17.25 21 21 17.25"/></svg>
                            </div>
                            <div><h4>By Level</h4><p>Basic through advanced — pick your entry point.</p></div>
                        </Link>
                        <Link href="/learn?genre=bossa-nova" class="mega-card" @click="closeAllMenus">
                            <div class="mega-ic">
                                <!-- musical-note -->
                                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M9 9V4.5M9 9l6-2.25M9 9a4.5 4.5 0 1 0 4.5 4.5V6.75M15 6.75V4.5m0 2.25a4.5 4.5 0 1 1-4.5 4.5"/></svg>
                            </div>
                            <div><h4>By Style</h4><p>Start with Bossa Nova — Jazz &amp; Classical too.</p></div>
                        </Link>
                        <Link href="/learn" class="mega-card" @click="closeAllMenus">
                            <div class="mega-ic">
                                <!-- squares-2x2 / catalogue -->
                                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6A2.25 2.25 0 0 1 6 3.75h2.25A2.25 2.25 0 0 1 10.5 6v2.25a2.25 2.25 0 0 1-2.25 2.25H6a2.25 2.25 0 0 1-2.25-2.25V6ZM3.75 15.75A2.25 2.25 0 0 1 6 13.5h2.25a2.25 2.25 0 0 1 2.25 2.25V18a2.25 2.25 0 0 1-2.25 2.25H6A2.25 2.25 0 0 1 3.75 18v-2.25ZM13.5 6a2.25 2.25 0 0 1 2.25-2.25H18A2.25 2.25 0 0 1 20.25 6v2.25A2.25 2.25 0 0 1 18 10.5h-2.25a2.25 2.25 0 0 1-2.25-2.25V6ZM13.5 15.75a2.25 2.25 0 0 1 2.25-2.25H18a2.25 2.25 0 0 1 2.25 2.25V18A2.25 2.25 0 0 1 18 20.25h-2.25A2.25 2.25 0 0 1 13.5 18v-2.25Z"/></svg>
                            </div>
                            <div><h4>All Courses</h4><p>Browse the full catalogue.</p></div>
                        </Link>
                        <Link href="/grades" class="mega-card" @click="closeAllMenus">
                            <div class="mega-ic">
                                <!-- academic-cap / find your level -->
                                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M4.26 10.147a60.438 60.438 0 0 0-.491 6.347A48.62 48.62 0 0 1 12 20.904a48.62 48.62 0 0 1 8.232-4.41 60.46 60.46 0 0 0-.491-6.347m-15.482 0a50.636 50.636 0 0 0-2.658-.813A59.906 59.906 0 0 1 12 3.493a59.903 59.903 0 0 1 10.399 5.84c-.896.248-1.783.52-2.658.814m-15.482 0A50.717 50.717 0 0 1 12 13.489a50.702 50.702 0 0 1 7.74-3.342M6.75 15a.75.75 0 1 0 0-1.5.75.75 0 0 0 0 1.5Zm0 0v-3.675A55.378 55.378 0 0 1 12 8.443m-7.007 11.55A5.981 5.981 0 0 0 6.75 15.75v-1.5"/></svg>
                            </div>
                            <div><h4>Find Your Level</h4><p>Take the grade test &amp; get matched.</p></div>
                        </Link>
                    </li>
                    <!-- Featured: Gilberto -->
                    <li class="mega-col-featured">
                        <div class="mega-featured-image">
                            <Link href="/learn/bossa-nova-basics" @click="closeAllMenus">
                                <img src="/images/courses/bossanovachords1.webp" alt="Bossa Nova Basics">
                                <div class="mega-featured-overlay">
                                    <span class="mega-featured-button">Start Course</span>
                                </div>
                            </Link>
                        </div>
                    </li>
                    <!-- Featured: Music Theory -->
                    <li class="mega-col-featured" style="margin-right: 0">
                        <div class="mega-featured-image">
                            <Link href="/learn/music-theory-basics" @click="closeAllMenus">
                                <img src="/images/courses/musictheorybasics.webp" alt="Music Theory Basics">
                                <div class="mega-featured-overlay">
                                    <span class="mega-featured-button">Start Free</span>
                                </div>
                            </Link>
                        </div>
                    </li>
                </ul>
            </li>

            <!-- Explore mega panel -->
            <li class="menu-item menu-item-has-children"
                :class="{ 'manual-hover': openMenu === 'explore' }"
                @mouseenter="onMenuEnter('explore')" @mouseleave="onMenuLeave">
                <a href="#" role="button" aria-haspopup="true"
                   :aria-expanded="openMenu === 'explore'" aria-controls="mega-panel-explore"
                   @click="toggleMenu('explore', $event)">Explore</a>
                <ul class="sub-menu" id="mega-panel-explore">
                    <!-- Card grid (4 cards, 2×2) -->
                    <li class="mega-col-cards">
                        <Link href="/library/chords" class="mega-card" @click="closeAllMenus">
                            <div class="mega-ic">
                                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M3.75 5.25h16.5m-16.5 4.5h16.5m-16.5 4.5h16.5m-16.5 4.5h16.5"/></svg>
                            </div>
                            <div><h4>Chord Library</h4><p>Voicings with role-coded diagrams.</p></div>
                        </Link>
                        <Link href="/library/rhythms" class="mega-card" @click="closeAllMenus">
                            <div class="mega-ic">
                                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6.75h16.5M3.75 12h16.5m-16.5 5.25H12"/></svg>
                            </div>
                            <div><h4>Rhythm Library</h4><p>Patterns, grooves &amp; clave feels.</p></div>
                        </Link>
                        <Link href="/library/songs" class="mega-card" @click="closeAllMenus">
                            <div class="mega-ic">
                                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6.042A8.967 8.967 0 0 0 6 3.75c-1.052 0-2.062.18-3 .512v14.25A8.987 8.987 0 0 1 6 18c2.305 0 4.408.867 6 2.292m0-14.25a8.966 8.966 0 0 1 6-2.292c1.052 0 2.062.18 3 .512v14.25A8.987 8.987 0 0 0 18 18a8.967 8.967 0 0 0-6 2.292m0-14.25v14.25"/></svg>
                            </div>
                            <div><h4>Song Library</h4><p>Annotated standards &amp; charts.</p></div>
                        </Link>
                        <Link href="/library/progressions" class="mega-card" @click="closeAllMenus">
                            <div class="mega-ic">
                                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M7.5 21 3 16.5m0 0L7.5 12M3 16.5h13.5m0-13.5L21 7.5m0 0L16.5 12M21 7.5H7.5"/></svg>
                            </div>
                            <div><h4>Progression Library</h4><p>Chord sequences &amp; voice leading.</p></div>
                        </Link>
                        <Link href="/theory" class="mega-card" @click="closeAllMenus">
                            <div class="mega-ic">
                                <!-- academic-cap / theory -->
                                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6.042A8.967 8.967 0 0 0 6 3.75c-1.052 0-2.062.18-3 .512v14.25A8.987 8.987 0 0 1 6 18c2.305 0 4.408.867 6 2.292m0-14.25a8.966 8.966 0 0 1 6-2.292c1.052 0 2.062.18 3 .512v14.25A8.987 8.987 0 0 0 18 18a8.967 8.967 0 0 0-6 2.292m0-14.25v14.25"/></svg>
                            </div>
                            <div><h4>Theory Library</h4><p>Concepts &amp; interactive widgets.</p></div>
                        </Link>
                    </li>
                    <!-- TOP10 CTA -->
                    <li class="mega-col-cta mega-col-cta--top10">
                        <a href="#" style="pointer-events:none;display:none;margin:0;padding:0"></a>
                        <ul class="sub-menu">
                            <li class="mega-top10-cta-body">
                                <svg class="mega-top10-cta__bg-icon" aria-hidden="true" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M16.5 18.75h-9m9 0a3 3 0 0 1 3 3h-15a3 3 0 0 1 3-3m9 0v-3.375c0-.621-.503-1.125-1.125-1.125h-.871M7.5 18.75v-3.375c0-.621.504-1.125 1.125-1.125h.872m5.007 0H9.497m5.007 0a7.454 7.454 0 0 1-.982-3.172M9.497 14.25a7.454 7.454 0 0 0 .981-3.172M5.25 4.236c-.982.143-1.954.317-2.916.52A6.003 6.003 0 0 0 7.73 9.728M5.25 4.236V4.5c0 2.108.966 3.99 2.48 5.228M5.25 4.236V2.721C7.456 2.41 9.71 2.25 12 2.25c2.291 0 4.545.16 6.75.47v1.516M7.73 9.728a6.726 6.726 0 0 0 2.748 1.35m8.272-6.842V4.5c0 2.108-.966 3.99-2.48 5.228m2.48-5.492a46.32 46.32 0 0 1 2.916.52 6.003 6.003 0 0 1-5.395 4.972m0 0a6.726 6.726 0 0 1-2.749 1.35m0 0a6.772 6.772 0 0 1-3.044 0"/></svg>
                                <h3 class="mega-top10-cta__title">TOP10</h3>
                                <p class="mega-top10-cta__blurb">Curated, annotated essentials every guitarist should know.</p>
                                <Link href="/top10/bossa-nova-songs" class="mega-top10-cta__btn" @click="closeAllMenus">Explore</Link>
                            </li>
                        </ul>
                    </li>
                </ul>
            </li>

            <!-- Shop mega panel -->
            <li class="menu-item menu-item-has-children"
                :class="{ 'manual-hover': openMenu === 'shop' }"
                @mouseenter="onMenuEnter('shop')" @mouseleave="onMenuLeave">
                <a href="#" role="button" aria-haspopup="true"
                   :aria-expanded="openMenu === 'shop'" aria-controls="mega-panel-shop"
                   @click="toggleMenu('shop', $event)">Shop</a>
                <ul class="sub-menu" id="mega-panel-shop">
                    <!-- Card grid -->
                    <li class="mega-col-cards">
                        <Link href="/shop/category/bossa-nova" class="mega-card" @click="closeAllMenus">
                            <div class="mega-ic">
                                <!-- sun / bossa -->
                                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 3v2.25m6.364.386-1.591 1.591M21 12h-2.25m-.386 6.364-1.591-1.591M12 18.75V21m-4.773-4.227-1.591 1.591M5.25 12H3m4.227-4.773L5.636 5.636M15.75 12a3.75 3.75 0 1 1-7.5 0 3.75 3.75 0 0 1 7.5 0Z"/></svg>
                            </div>
                            <div><h4>Bossa Nova</h4><p>Rhythm, comping &amp; the João feel.</p></div>
                        </Link>
                        <Link href="/shop/category/jazz" class="mega-card" @click="closeAllMenus">
                            <div class="mega-ic">
                                <!-- adjustments / jazz -->
                                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M10.5 6h9.75M10.5 6a1.5 1.5 0 1 1-3 0m3 0a1.5 1.5 0 1 0-3 0M3.75 6H7.5m3 12h9.75m-9.75 0a1.5 1.5 0 0 1-3 0m3 0a1.5 1.5 0 0 0-3 0m-3.75 0H7.5m9-6h3.75m-3.75 0a1.5 1.5 0 0 1-3 0m3 0a1.5 1.5 0 0 0-3 0m-9.75 0h9.75"/></svg>
                            </div>
                            <div><h4>Jazz</h4><p>Standards, arrangements &amp; charts.</p></div>
                        </Link>
                        <Link href="/shop/category/classical" class="mega-card" @click="closeAllMenus">
                            <div class="mega-ic">
                                <!-- queue-list / classical -->
                                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M3.75 12h16.5m-16.5 3.75h16.5M3.75 19.5h16.5M5.625 4.5h12.75a1.875 1.875 0 0 1 0 3.75H5.625a1.875 1.875 0 0 1 0-3.75Z"/></svg>
                            </div>
                            <div><h4>Classical</h4><p>Fingerstyle &amp; solo guitar pieces.</p></div>
                        </Link>
                        <Link href="/shop" class="mega-card" @click="closeAllMenus">
                            <div class="mega-ic">
                                <!-- shopping-bag -->
                                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 10.5V6a3.75 3.75 0 1 0-7.5 0v4.5m11.356-1.993 1.263 12c.07.665-.45 1.243-1.119 1.243H4.25a1.125 1.125 0 0 1-1.12-1.243l1.264-12A1.125 1.125 0 0 1 5.513 7.5h12.974c.576 0 1.059.435 1.119 1.007Z"/></svg>
                            </div>
                            <div><h4>All Products</h4><p>Browse the full shop.</p></div>
                        </Link>
                    </li>
                    <!-- Featured -->
                    <li class="mega-col-featured">
                        <div class="mega-featured-image">
                            <Link href="/shop/product/top10-bossa-nova-chords" @click="closeAllMenus">
                                <img src="/storage/products/thumbnails/top10-bossa-nova-chords.webp" alt="TOP10 Bossa Nova Chords">
                                <div class="mega-featured-overlay">
                                    <span class="mega-featured-button">View Product</span>
                                </div>
                            </Link>
                        </div>
                    </li>
                    <!-- CTA -->
                    <li class="mega-col-cta mega-col-cta--top10">
                        <a href="#" style="pointer-events:none;display:none;margin:0;padding:0"></a>
                        <ul class="sub-menu">
                            <li class="mega-top10-cta-body">
                                <svg class="mega-top10-cta__bg-icon" aria-hidden="true" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M11.42 15.17 17.25 21A2.652 2.652 0 0 0 21 17.25l-5.877-5.877M11.42 15.17l2.496-3.03c.317-.384.74-.626 1.208-.766M11.42 15.17l-4.655 5.653a2.548 2.548 0 1 1-3.586-3.586l6.837-5.63m5.108-.233c.55-.164 1.163-.188 1.743-.14a4.5 4.5 0 0 0 4.486-6.336l-3.276 3.277a3.004 3.004 0 0 1-2.25-2.25l3.276-3.276a4.5 4.5 0 0 0-6.336 4.486c.091 1.076-.071 2.264-.904 2.95l-.102.085m-1.745 1.437L5.909 7.5H4.5L2.25 3.75l1.5-1.5L7.5 4.5v1.409l4.26 4.26m-1.745 1.437 1.745-1.437m6.615 8.206L15.75 15.75M4.867 19.125h.008v.008h-.008v-.008Z"/></svg>
                                <h3 class="mega-top10-cta__title">Custom Services</h3>
                                <p class="mega-top10-cta__blurb">Bespoke arrangements &amp; transcriptions, made to order.</p>
                                <Link href="/contact" class="mega-top10-cta__btn" @click="closeAllMenus">Get in touch</Link>
                            </li>
                        </ul>
                    </li>
                </ul>
            </li>

        </ul>
    </nav>

    <div class="header-actions">
        <button class="cart-icon-link" @click="openCart" aria-label="Shopping cart">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 10.5V6a3.75 3.75 0 10-7.5 0v4.5m11.356-1.993l1.263 12c.07.665-.45 1.243-1.119 1.243H4.25a1.125 1.125 0 01-1.12-1.243l1.264-12A1.125 1.125 0 015.513 7.5h12.974c.576 0 1.059.435 1.119 1.007zM8.625 10.5a.375.375 0 11-.75 0 .375.375 0 01.75 0zm7.5 0a.375.375 0 11-.75 0 .375.375 0 01.75 0z" />
            </svg>
            <span v-if="count > 0" class="cart-count">{{ count }}</span>
        </button>
        <UserMenu class="user-menu-desktop" />
        <button class="hamburger" :class="{ 'is-open': drawerOpen }" @click="toggleDrawer" aria-label="Open menu">
            <span></span><span></span><span></span>
        </button>
    </div>

    <Teleport to="body">
        <div class="mega-menu-backdrop" :class="{ 'opacity-100 visible': openMenu }"></div>

        <!-- Mobile drawer -->
        <div class="mobile-drawer" :class="{ 'is-open': drawerOpen }" aria-label="Mobile navigation">
            <div class="mobile-drawer-header">
                <span class="mobile-drawer-title">Menu</span>
                <button class="mobile-drawer-close" @click="closeDrawer" aria-label="Close menu">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12"/></svg>
                </button>
            </div>

            <nav class="mobile-drawer-nav">
                <Link href="/" class="mobile-nav-item" @click="closeDrawer">Home</Link>

                <!-- Courses accordion -->
                <div class="mobile-nav-section" :class="{ 'is-open': drawerSection === 'courses' }">
                    <button class="mobile-nav-item mobile-nav-trigger" :aria-expanded="drawerSection === 'courses'" @click="toggleDrawerSection('courses')">
                        Courses
                        <svg class="mobile-nav-chev" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m19 9-7 7-7-7"/></svg>
                    </button>
                    <div class="mobile-nav-cards"><div>
                        <Link href="/learn?level=basic" class="mobile-card" @click="closeDrawer">
                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M3 4.5h14.25M3 9h9.75M3 13.5h9.75m4.5-4.5v12m0 0-3.75-3.75M17.25 21 21 17.25"/></svg>
                            By Level
                        </Link>
                        <Link href="/learn?genre=bossa-nova" class="mobile-card" @click="closeDrawer">
                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M9 9V4.5M9 9l6-2.25M9 9a4.5 4.5 0 1 0 4.5 4.5V6.75M15 6.75V4.5m0 2.25a4.5 4.5 0 1 1-4.5 4.5"/></svg>
                            By Style
                        </Link>
                        <Link href="/learn" class="mobile-card" @click="closeDrawer">
                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6A2.25 2.25 0 0 1 6 3.75h2.25A2.25 2.25 0 0 1 10.5 6v2.25a2.25 2.25 0 0 1-2.25 2.25H6a2.25 2.25 0 0 1-2.25-2.25V6ZM3.75 15.75A2.25 2.25 0 0 1 6 13.5h2.25a2.25 2.25 0 0 1 2.25 2.25V18a2.25 2.25 0 0 1-2.25 2.25H6A2.25 2.25 0 0 1 3.75 18v-2.25ZM13.5 6a2.25 2.25 0 0 1 2.25-2.25H18A2.25 2.25 0 0 1 20.25 6v2.25A2.25 2.25 0 0 1 18 10.5h-2.25a2.25 2.25 0 0 1-2.25-2.25V6ZM13.5 15.75a2.25 2.25 0 0 1 2.25-2.25H18a2.25 2.25 0 0 1 2.25 2.25V18A2.25 2.25 0 0 1 18 20.25h-2.25A2.25 2.25 0 0 1 13.5 18v-2.25Z"/></svg>
                            All Courses
                        </Link>
                        <Link href="/grades" class="mobile-card" @click="closeDrawer">
                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M4.26 10.147a60.438 60.438 0 0 0-.491 6.347A48.62 48.62 0 0 1 12 20.904a48.62 48.62 0 0 1 8.232-4.41 60.46 60.46 0 0 0-.491-6.347m-15.482 0a50.636 50.636 0 0 0-2.658-.813A59.906 59.906 0 0 1 12 3.493a59.903 59.903 0 0 1 10.399 5.84c-.896.248-1.783.52-2.658.814m-15.482 0A50.717 50.717 0 0 1 12 13.489a50.702 50.702 0 0 1 7.74-3.342M6.75 15a.75.75 0 1 0 0-1.5.75.75 0 0 0 0 1.5Zm0 0v-3.675A55.378 55.378 0 0 1 12 8.443m-7.007 11.55A5.981 5.981 0 0 0 6.75 15.75v-1.5"/></svg>
                            Find Your Level
                        </Link>
                    </div></div>
                </div>

                <!-- Explore accordion -->
                <div class="mobile-nav-section" :class="{ 'is-open': drawerSection === 'explore' }">
                    <button class="mobile-nav-item mobile-nav-trigger" :aria-expanded="drawerSection === 'explore'" @click="toggleDrawerSection('explore')">
                        Explore
                        <svg class="mobile-nav-chev" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m19 9-7 7-7-7"/></svg>
                    </button>
                    <div class="mobile-nav-cards"><div>
                        <Link href="/library/chords" class="mobile-card" @click="closeDrawer">
                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M3.75 5.25h16.5m-16.5 4.5h16.5m-16.5 4.5h16.5m-16.5 4.5h16.5"/></svg>
                            Chord Library
                        </Link>
                        <Link href="/library/rhythms" class="mobile-card" @click="closeDrawer">
                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6.75h16.5M3.75 12h16.5m-16.5 5.25H12"/></svg>
                            Rhythm Library
                        </Link>
                        <Link href="/library/songs" class="mobile-card" @click="closeDrawer">
                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6.042A8.967 8.967 0 0 0 6 3.75c-1.052 0-2.062.18-3 .512v14.25A8.987 8.987 0 0 1 6 18c2.305 0 4.408.867 6 2.292m0-14.25a8.966 8.966 0 0 1 6-2.292c1.052 0 2.062.18 3 .512v14.25A8.987 8.987 0 0 0 18 18a8.967 8.967 0 0 0-6 2.292m0-14.25v14.25"/></svg>
                            Song Library
                        </Link>
                        <Link href="/library/progressions" class="mobile-card" @click="closeDrawer">
                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M7.5 21 3 16.5m0 0L7.5 12M3 16.5h13.5m0-13.5L21 7.5m0 0L16.5 12M21 7.5H7.5"/></svg>
                            Progression Library
                        </Link>
                        <Link href="/theory" class="mobile-card" @click="closeDrawer">
                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6.042A8.967 8.967 0 0 0 6 3.75c-1.052 0-2.062.18-3 .512v14.25A8.987 8.987 0 0 1 6 18c2.305 0 4.408.867 6 2.292m0-14.25a8.966 8.966 0 0 1 6-2.292c1.052 0 2.062.18 3 .512v14.25A8.987 8.987 0 0 0 18 18a8.967 8.967 0 0 0-6 2.292m0-14.25v14.25"/></svg>
                            Theory Library
                        </Link>
                        <Link href="/top10/bossa-nova-chords" class="mobile-card" @click="closeDrawer">
                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M16.5 18.75h-9m9 0a3 3 0 0 1 3 3h-15a3 3 0 0 1 3-3m9 0v-3.375c0-.621-.503-1.125-1.125-1.125h-.871M7.5 18.75v-3.375c0-.621.504-1.125 1.125-1.125h.872m5.007 0H9.497m5.007 0a7.454 7.454 0 0 1-.982-3.172M9.497 14.25a7.454 7.454 0 0 0 .981-3.172M5.25 4.236c-.982.143-1.954.317-2.916.52A6.003 6.003 0 0 0 7.73 9.728M5.25 4.236V4.5c0 2.108.966 3.99 2.48 5.228M5.25 4.236V2.721C7.456 2.41 9.71 2.25 12 2.25c2.291 0 4.545.16 6.75.47v1.516M7.73 9.728a6.726 6.726 0 0 0 2.748 1.35m8.272-6.842V4.5c0 2.108-.966 3.99-2.48 5.228m2.48-5.492a46.32 46.32 0 0 1 2.916.52 6.003 6.003 0 0 1-5.395 4.972m0 0a6.726 6.726 0 0 1-2.749 1.35m0 0a6.772 6.772 0 0 1-3.044 0"/></svg>
                            TOP10 Lists
                        </Link>
                    </div></div>
                </div>

                <!-- Shop accordion -->
                <div class="mobile-nav-section" :class="{ 'is-open': drawerSection === 'shop' }">
                    <button class="mobile-nav-item mobile-nav-trigger" :aria-expanded="drawerSection === 'shop'" @click="toggleDrawerSection('shop')">
                        Shop
                        <svg class="mobile-nav-chev" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m19 9-7 7-7-7"/></svg>
                    </button>
                    <div class="mobile-nav-cards"><div>
                        <Link href="/shop/category/bossa-nova" class="mobile-card" @click="closeDrawer">
                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 3v2.25m6.364.386-1.591 1.591M21 12h-2.25m-.386 6.364-1.591-1.591M12 18.75V21m-4.773-4.227-1.591 1.591M5.25 12H3m4.227-4.773L5.636 5.636M15.75 12a3.75 3.75 0 1 1-7.5 0 3.75 3.75 0 0 1 7.5 0Z"/></svg>
                            Bossa Nova
                        </Link>
                        <Link href="/shop/category/jazz" class="mobile-card" @click="closeDrawer">
                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M10.5 6h9.75M10.5 6a1.5 1.5 0 1 1-3 0m3 0a1.5 1.5 0 1 0-3 0M3.75 6H7.5m3 12h9.75m-9.75 0a1.5 1.5 0 0 1-3 0m3 0a1.5 1.5 0 0 0-3 0m-3.75 0H7.5m9-6h3.75m-3.75 0a1.5 1.5 0 0 1-3 0m3 0a1.5 1.5 0 0 0-3 0m-9.75 0h9.75"/></svg>
                            Jazz
                        </Link>
                        <Link href="/shop/category/classical" class="mobile-card" @click="closeDrawer">
                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M3.75 12h16.5m-16.5 3.75h16.5M3.75 19.5h16.5M5.625 4.5h12.75a1.875 1.875 0 0 1 0 3.75H5.625a1.875 1.875 0 0 1 0-3.75Z"/></svg>
                            Classical
                        </Link>
                        <Link href="/shop" class="mobile-card" @click="closeDrawer">
                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 10.5V6a3.75 3.75 0 1 0-7.5 0v4.5m11.356-1.993 1.263 12c.07.665-.45 1.243-1.119 1.243H4.25a1.125 1.125 0 0 1-1.12-1.243l1.264-12A1.125 1.125 0 0 1 5.513 7.5h12.974c.576 0 1.059.435 1.119 1.007Z"/></svg>
                            All Products
                        </Link>
                    </div></div>
                </div>

                <div class="mobile-drawer-footer">
                    <UserMenu />
                </div>
            </nav>
        </div>
        <div class="mobile-drawer-overlay" :class="{ 'is-open': drawerOpen }" @click="closeDrawer"></div>
    </Teleport>
</template>
