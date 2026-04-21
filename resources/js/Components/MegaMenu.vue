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
           
              <li class="menu-item">
                <Link href="/home" @click="closeAllMenus">Home</Link>
            </li>
            <li class="menu-item">
                <Link href="/courses" @click="closeAllMenus">Courses</Link>
            </li>
            <li class="menu-item">
                <Link href="/top-10" @click="closeAllMenus">Top 10</Link>
            </li>

            <li class="menu-item menu-item-has-children" 
                :class="{ 'manual-hover': openMenu === 'explore' }">
                <a href="#" @click="toggleMenu('explore', $event)">
                    Explore
                </a>
                <ul class="sub-menu">
                    <!-- Column 1: Standard Nav -->
                    <li class="menu-item-has-children mega-col-nav">
                        <a href="#">Resources</a>
                        <ul class="sub-menu">
                            <li><Link href="/library/chords" @click="closeAllMenus">Chords</Link></li>
                            <li><Link href="/library/rhythms" @click="closeAllMenus">Rhythms</Link></li>
                            <li><Link href="/library/progressions" @click="closeAllMenus">Chord Progressions</Link></li>
                            <li><Link href="/library/songs" @click="closeAllMenus">Songs</Link></li>
                        </ul>
                    </li>

                    <!-- Column 2: Featured -->
                    <li class="mega-col-featured">
                        <a href="#">Featured Collection</a>
                        <p class="mega-featured-desc">Master the authentic Brazilian feel</p>
                        <div class="mega-featured-image">
                            <Link href="/courses/bossa-nova-rhythms" @click="closeAllMenus">
                                <img src="/images/mega-menu/featured-collection.webp" alt="Bossa Nova Rhythms">
                                <span class="mega-featured-button">Start Learning</span>
                            </Link>
                        </div>
                    </li>

                    <!-- Column 3: CTA Box -->
                    <li class="menu-item-has-children mega-col-cta">
                        <a href="#">Join SBN Premium</a>
                        <ul class="sub-menu">
                            <li><Link href="#" @click="closeAllMenus">Full Tablatures</Link></li>
                            <li><Link href="#" @click="closeAllMenus">Interactive Audio Engine</Link></li>
                            <li><Link href="#" @click="closeAllMenus">Advanced Voicings</Link></li>
                            <li><Link href="/join" @click="closeAllMenus" class="has-button-last">Become a Member</Link></li>
                        </ul>
                    </li>
                </ul>
            </li>

            
             <li class="menu-item">
                <Link href="/shop" @click="closeAllMenus">Shop</Link>
            </li>
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
