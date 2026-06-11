<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { ref, computed } from 'vue';
import ProductCard from '@/Components/Shop/ProductCard.vue';
import CartDrawer from '@/Components/Shop/CartDrawer.vue';
import { getCategoryStyle } from '@/composables/useCategoryColors';
import type { Product, Category } from '@/types/shop';

interface Props {
    products: {
        data: Product[];
        links: Array<{ url: string | null; label: string; active: boolean }>;
        total: number;
    };
    categories: Category[];
    currentCategory?: Category | null;
    filters: {
        category?: string;
        search?: string;
        sort?: string;
        direction?: string;
    };
    meta: {
        title: string;
        description: string;
    };
}

const props = defineProps<Props>();

const search = ref(props.filters.search ?? '');
const sort   = ref(props.filters.sort ?? 'title');

function submitSearch() {
    const base = props.currentCategory ? `/shop/category/${props.currentCategory.slug}` : '/shop';
    router.get(base, { search: search.value || undefined, sort: sort.value }, { preserveScroll: true });
}

function setSort(val: string) {
    sort.value = val;
    submitSearch();
}

const totalShown = computed(() => props.products.data.length);
const totalAll   = computed(() => props.products.total ?? totalShown.value);
</script>

<template>
    <div>
        <Head>
            <title>{{ meta.title }}</title>
            <meta name="description" :content="meta.description" />
        </Head>

        <div class="sbn-page sbn-shop-main">

            <!-- ── Header ── -->
            <div class="sbn-lib-page-header">
                <h1 class="sbn-lib-page-title">
                    {{ currentCategory ? currentCategory.name : 'Shop' }}
                </h1>
                <p class="sbn-lib-page-subtitle">
                    Bossa nova guitar tablature, exercises, and resources
                </p>

                <div class="sbn-lib-search-wrap">
                    <form @submit.prevent="submitSearch">
                        <div class="sbn-lib-search-box">
                            <svg class="sbn-lib-search-icon" width="20" height="20" viewBox="0 0 20 20" fill="none">
                                <circle cx="8" cy="8" r="6" stroke="currentColor" stroke-width="2"/>
                                <path d="M13 13L18 18" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                            </svg>
                            <input
                                v-model="search"
                                type="text"
                                class="sbn-lib-search-input"
                                placeholder="Search products..."
                                autocomplete="off"
                            >
                            <button
                                v-if="search"
                                type="button"
                                class="sbn-lib-search-clear"
                                aria-label="Clear search"
                                @click="search = ''; submitSearch()"
                            >
                                <svg width="14" height="14" viewBox="0 0 14 14" fill="none">
                                    <path d="M2 2L12 12M12 2L2 12" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                                </svg>
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- ── Content: grid + sidebar ── -->
            <div class="sbn-lib-content-wrapper">

                <!-- Products grid -->
                <div class="sbn-lib-list-container">

                    <!-- Status bar -->
                    <div class="sbn-lib-list-status">
                        <span>
                            <strong>{{ totalShown }}</strong>
                            <template v-if="totalAll > totalShown"> of {{ totalAll }}</template>
                            product{{ totalAll !== 1 ? 's' : '' }}
                        </span>
                        <select
                            class="sbn-shop-sort-select"
                            :value="sort"
                            @change="setSort(($event.target as HTMLSelectElement).value)"
                        >
                            <option value="title">Name A–Z</option>
                            <option value="price">Price ↑</option>
                            <option value="date">Newest</option>
                        </select>
                    </div>

                    <div v-if="products.data.length > 0" class="sbn-shop-products-grid">
                        <ProductCard
                            v-for="product in products.data"
                            :key="product.id"
                            :product="product"
                        />
                    </div>

                    <div v-else class="sbn-lib-no-results">
                        <svg width="48" height="48" viewBox="0 0 48 48" fill="none">
                            <circle cx="20" cy="20" r="12" stroke="currentColor" stroke-width="2"/>
                            <path d="M30 30L42 42" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                        </svg>
                        <h3>No products found</h3>
                        <p>Try adjusting your search or browse all categories</p>
                    </div>

                    <!-- Pagination -->
                    <div v-if="products.links.length > 3" class="sbn-shop-pagination">
                        <Link
                            v-for="link in products.links"
                            :key="link.label"
                            :href="link.url || '#'"
                            :class="['sbn-shop-page-link', { active: link.active, disabled: !link.url }]"
                            v-html="link.label"
                        />
                    </div>
                </div>

                <!-- Filter sidebar -->
                <aside class="sbn-lib-filter-sidebar">
                    <div class="sbn-lib-sidebar-header">
                        <h3>Categories</h3>
                    </div>

                    <div class="sbn-lib-sidebar-section">
                        <div class="sbn-lib-sidebar-options">
                            <!-- All products -->
                            <Link
                                href="/shop"
                                :class="['sbn-lib-sidebar-option', { 'sbn-filter-active': !currentCategory }]"
                            >
                                All Products
                            </Link>

                            <!-- Top-level categories (no children: flat chip) -->
                            <template v-for="cat in categories" :key="cat.id">
                                <Link
                                    v-if="!cat.children?.length"
                                    :href="`/shop/category/${cat.slug}`"
                                    :class="['sbn-lib-sidebar-option', { 'sbn-filter-active': currentCategory?.slug === cat.slug }]"
                                >
                                    <span>{{ cat.name }}</span>
                                    <span v-if="cat.products_count" class="sbn-shop-cat-count">{{ cat.products_count }}</span>
                                </Link>
                            </template>
                        </div>
                    </div>

                    <!-- Groups with children get a labelled section each -->
                    <template v-for="cat in categories" :key="`grp-${cat.id}`">
                        <div v-if="cat.children?.length" class="sbn-lib-sidebar-section">
                            <span class="sbn-lib-sidebar-label">{{ cat.name }}</span>
                            <div class="sbn-lib-sidebar-options">
                                <Link
                                    :href="`/shop/category/${cat.slug}`"
                                    :class="['sbn-lib-sidebar-option', { 'sbn-filter-active': currentCategory?.slug === cat.slug }]"
                                >
                                    <span>All {{ cat.name }}</span>
                                    <span v-if="cat.products_count" class="sbn-shop-cat-count">{{ cat.products_count }}</span>
                                </Link>
                                <Link
                                    v-for="child in cat.children"
                                    :key="child.id"
                                    :href="`/shop/category/${child.slug}`"
                                    :class="['sbn-lib-sidebar-option', { 'sbn-filter-active': currentCategory?.slug === child.slug }]"
                                >
                                    <span>{{ child.name }}</span>
                                    <span v-if="child.products_count" class="sbn-shop-cat-count">{{ child.products_count }}</span>
                                </Link>
                            </div>
                        </div>
                    </template>
                </aside>

            </div>
        </div>

        <CartDrawer />
    </div>
</template>
