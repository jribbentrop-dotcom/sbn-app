<script setup lang="ts">
import { ref, onMounted, onUnmounted, watch } from 'vue';
import { Link } from '@inertiajs/vue3';
import UserMenu from './UserMenu.vue';
import { useCart } from '@/composables/useCart';

const { count, openCart } = useCart();
const openMenu = ref<string | null>(null);

watch(openMenu, (newVal) => {
    if (newVal) {
        document.body.classList.add('mega-menu-open');
    } else {
        document.body.classList.remove('mega-menu-open');
    }
});

const toggleMenu = (menuName: string, event: Event) => {
    // Rely solely on click for an intentional toggle (avoids diagonal tracking/hover gap drops)
    if (openMenu.value === menuName) {
        openMenu.value = null; // Close
    } else {
        openMenu.value = menuName; // Open this one
    }
    
    event.preventDefault();
    event.stopPropagation();
};

const closeAllMenus = () => {
    openMenu.value = null;
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
    }
};

onMounted(() => {
    document.addEventListener('click', handleGlobalClick);
    document.addEventListener('keydown', handleKeyDown);
});

onUnmounted(() => {
    document.removeEventListener('click', handleGlobalClick);
    document.removeEventListener('keydown', handleKeyDown);
});
</script>

<template>
    <nav class="main-navigation" role="navigation" aria-label="Primary Menu">
        <ul class="main-menu">

            <!-- Home -->
            <li class="menu-item">
                <Link href="/" @click="closeAllMenus">Home</Link>
            </li>

            <!-- Courses mega panel -->
            <li class="menu-item menu-item-has-children"
                :class="{ 'manual-hover': openMenu === 'courses' }">
                <a href="#" @click="toggleMenu('courses', $event)">Courses</a>
                <ul class="sub-menu">
                    <!-- Col 1: Browse by Level -->
                    <li class="menu-item-has-children mega-col-nav">
                        <a href="#">Browse by Level</a>
                        <ul class="sub-menu">
                            <li><Link href="/learn?level=basic" @click="closeAllMenus">Basic</Link></li>
                            <li><Link href="/learn?level=early-intermediate" @click="closeAllMenus">Early Intermediate</Link></li>
                            <li><Link href="/learn?level=intermediate" @click="closeAllMenus">Intermediate</Link></li>
                            <li><Link href="/learn?level=late-intermediate" @click="closeAllMenus">Late Intermediate</Link></li>
                            <li><Link href="/learn?level=advanced" @click="closeAllMenus">Advanced</Link></li>
                        </ul>
                    </li>
                    <!-- Col 1b: Browse by Style -->
                    <li class="menu-item-has-children mega-col-nav">
                        <a href="#">Browse by Style</a>
                        <ul class="sub-menu">
                            <li><Link href="/learn?genre=bossa-nova" @click="closeAllMenus">Bossa Nova</Link></li>
                            <li><Link href="/learn?genre=classical" @click="closeAllMenus">Classical</Link></li>
                            <li><Link href="/learn?genre=jazz" @click="closeAllMenus">Jazz</Link></li>
                            <li><Link href="/learn" @click="closeAllMenus">All Courses</Link></li>
                        </ul>
                    </li>
                    <!-- Col 2: Featured Course thumbnail -->
                    <li class="mega-col-featured">
                        <a href="#">Featured Course</a>
                        <p class="mega-featured-desc">Bossa Nova Basics — the foundation</p>
                        <div class="mega-featured-image">
                            <Link href="/learn/bossa-nova-basics" @click="closeAllMenus">
                                <img src="/images/mega-menu/courses-featured.webp" alt="Bossa Nova Basics">
                                <span class="mega-featured-button">Start Course</span>
                            </Link>
                        </div>
                    </li>
                    <!-- Col 3: New Course -->
                    <li class="menu-item-has-children mega-col-cta">
                        <a href="#">New Course</a>
                        <ul class="sub-menu">
                            <li><Link href="/learn/the-clave" @click="closeAllMenus">Clave 101</Link></li>
                            <li><Link href="/learn/the-clave/play/the-son-clave-the-square-beat" @click="closeAllMenus">Son Clave</Link></li>
                            <li><Link href="/learn/the-clave/play/the-rumba-clave-the-swung-beat" @click="closeAllMenus">Rumba Clave</Link></li>
                            <li><Link href="/learn/the-clave/play/the-bossa-nova-clave-in-jazz" @click="closeAllMenus">Bossa Nova Clave</Link></li>
                            <li><Link href="/learn/the-clave/play" @click="closeAllMenus">All Lessons</Link></li>
                        </ul>
                    </li>
                </ul>
            </li>

            <!-- Explore mega panel -->
            <li class="menu-item menu-item-has-children"
                :class="{ 'manual-hover': openMenu === 'explore' }">
                <a href="#" @click="toggleMenu('explore', $event)">Explore</a>
                <ul class="sub-menu">
                    <!-- Col 1: TOP10 Lists -->
                    <li class="menu-item-has-children mega-col-nav">
                        <a href="#">TOP10 Lists</a>
                        <ul class="sub-menu">
                            <li><Link href="/top10/bossa-nova-chords" @click="closeAllMenus">Bossa Nova Chords</Link></li>
                            <li><Link href="/top10/latin-jazz-standards" @click="closeAllMenus">Latin Jazz Standards</Link></li>
                            <li><Link href="/top10/bossa-nova-songs" @click="closeAllMenus">Bossa Nova Songs</Link></li>
                            <li><Link href="#" @click="closeAllMenus">All TOP10 Lists</Link></li>
                        </ul>
                    </li>
                    <!-- Col 1b: Resources -->
                    <li class="menu-item-has-children mega-col-nav">
                        <a href="#">Resources</a>
                        <ul class="sub-menu">
                            <li><Link href="/library/chords" @click="closeAllMenus">Chord Library</Link></li>
                            <li><Link href="/library/rhythms" @click="closeAllMenus">Rhythm Library</Link></li>
                            <li><Link href="/library/songs" @click="closeAllMenus">Song Library</Link></li>
                            <li><Link href="/library/progressions" @click="closeAllMenus">Chord Progressions</Link></li>
                        </ul>
                    </li>
                    <!-- Col 2: Featured Chord thumbnail -->
                    <li class="mega-col-featured">
                        <a href="#">Featured Content</a>
                        <p class="mega-featured-desc">Free Music Theory Course</p>
                        <div class="mega-featured-image">
                            <Link href="/learn/music-theory-basics" @click="closeAllMenus">
                                <img src="/images/mega-menu/explore-featured.webp" alt="Featured Chord">
                                <span class="mega-featured-button">Explore</span>
                            </Link>
                        </div>
                    </li>
                    <!-- Col 3: Featured content list -->
                    <li class="menu-item-has-children mega-col-cta">
                        <a href="#">Free Resources</a>
                        <ul class="sub-menu">
                            <li><Link href="/learn/music-theory-basics" @click="closeAllMenus">Music Theory Course</Link></li>
                            <li><Link href="/learn/music-theory-basics/play/basics-of-notation" @click="closeAllMenus">Music Notation &amp; Tablature</Link></li>
                            <li><Link href="/learn/bossa-nova-rhythm" @click="closeAllMenus">Rhythm Grids and Durations</Link></li>
                            <li><Link href="/learn/melody-playing-nylon-guitar" @click="closeAllMenus">Scales and Fingerings</Link></li>
                        </ul>
                    </li>
                </ul>
            </li>

            <!-- Shop mega panel -->
            <li class="menu-item menu-item-has-children"
                :class="{ 'manual-hover': openMenu === 'shop' }">
                <a href="#" @click="toggleMenu('shop', $event)">Shop</a>
                <ul class="sub-menu">
                    <!-- Col 1: Product Categories -->
                    <li class="menu-item-has-children mega-col-nav">
                        <a href="#">Product Categories</a>
                        <ul class="sub-menu">
                            <li><Link href="/shop/category/bossa-nova" @click="closeAllMenus">Bossa Nova</Link></li>
                            <li><Link href="/shop/category/jazz" @click="closeAllMenus">Jazz</Link></li>
                            <li><Link href="/shop/category/classical" @click="closeAllMenus">Classical</Link></li>
                        </ul>
                    </li>
                    <!-- Col 1b: Browse by Category -->
                    <li class="menu-item-has-children mega-col-nav">
                        <a href="#">Browse by Category</a>
                        <ul class="sub-menu">
                            <li><Link href="/shop/category/solo-guitar" @click="closeAllMenus">Solo Guitar</Link></li>
                            <li><Link href="/shop/category/chords" @click="closeAllMenus">Chords</Link></li>
                            <li><Link href="/shop" @click="closeAllMenus">All Products</Link></li>
                        </ul>
                    </li>
                    <!-- Col 2: Featured Product thumbnail -->
                    <li class="mega-col-featured">
                        <a href="#">Featured Product</a>
                        <p class="mega-featured-desc">Intermediate Bossa Nova Collection</p>
                        <div class="mega-featured-image">
                            <Link href="/shop" @click="closeAllMenus">
                                <img src="/images/mega-menu/shop-featured.webp" alt="Featured Product">
                                <span class="mega-featured-button">View Product</span>
                            </Link>
                        </div>
                    </li>
                    <!-- Col 3: Custom Services -->
                    <li class="menu-item-has-children mega-col-cta">
                        <a href="#">Custom Services</a>
                        <ul class="sub-menu">
                            <li><a href="#">Bespoke arrangements</a></li>
                            <li><Link href="#" @click="closeAllMenus">Jazz arrangements</Link></li><!-- TODO: contact/services route -->
                            <li><Link href="#" @click="closeAllMenus">Bossa Nova arrangements</Link></li>
                            <li><Link href="#" @click="closeAllMenus">Fingerstyle transcriptions</Link></li>
                        </ul>
                    </li>
                </ul>
            </li>

            <!-- Cart -->
            <li class="menu-item menu-item-cart">
                <button class="cart-icon-link" @click="openCart" aria-label="Shopping cart">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 10.5V6a3.75 3.75 0 10-7.5 0v4.5m11.356-1.993l1.263 12c.07.665-.45 1.243-1.119 1.243H4.25a1.125 1.125 0 01-1.12-1.243l1.264-12A1.125 1.125 0 015.513 7.5h12.974c.576 0 1.059.435 1.119 1.007zM8.625 10.5a.375.375 0 11-.75 0 .375.375 0 01.75 0zm7.5 0a.375.375 0 11-.75 0 .375.375 0 01.75 0z" />
                    </svg>
                    <span v-if="count > 0" class="cart-count">{{ count }}</span>
                </button>
            </li>

            <li class="menu-item">
                <UserMenu />
            </li>
        </ul>
    </nav>
    <Teleport to="body">
        <div class="mega-menu-backdrop" :class="{ 'opacity-100 visible': openMenu }"></div>
    </Teleport>
</template>
