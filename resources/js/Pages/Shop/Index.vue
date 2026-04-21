<script setup lang="ts">
import { Head, Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import ProductCard from '@/Components/Shop/ProductCard.vue';
import CategoryFilter from '@/Components/Shop/CategoryFilter.vue';
import CartDrawer from '@/Components/Shop/CartDrawer.vue';
import { useCategoryColors } from '@/composables/useCategoryColors';
import type { Product, Category } from '@/types/shop';

interface Props {
    products: {
        data: Product[];
        links: Array<{ url: string | null; label: string; active: boolean }>;
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
const page = usePage();
const { getStyle } = useCategoryColors();

const containerStyle = computed(() => {
    return getStyle(props.currentCategory?.slug);
});
</script>

<template>
    <Head>
        <title>{{ meta.title }}</title>
        <meta name="description" :content="meta.description" />
    </Head>

    <div class="shop-container" :style="containerStyle">
            <aside class="shop-sidebar">
                <CategoryFilter
                    :categories="categories"
                    :current-category="currentCategory"
                />
            </aside>

            <main class="shop-main">
                <div class="shop-header">
                    <h1 class="shop-title">
                        {{ currentCategory ? currentCategory.name : 'Shop' }}
                    </h1>
                    <p class="shop-count">
                        {{ products.data.length }} products
                    </p>
                </div>

                <div v-if="products.data.length > 0" class="products-grid">
                    <ProductCard
                        v-for="product in products.data"
                        :key="product.id"
                        :product="product"
                    />
                </div>

                <div v-else class="no-products">
                    <p>No products found.</p>
                    <Link href="/shop" class="clear-filters">
                        View all products
                    </Link>
                </div>

                <!-- Pagination -->
                <div v-if="products.links.length > 3" class="pagination">
                    <Link
                        v-for="link in products.links"
                        :key="link.label"
                        :href="link.url || '#'"
                        :class="['page-link', { active: link.active, disabled: !link.url }]"
                        v-html="link.label"
                    />
                </div>
            </main>
        </div>

    <CartDrawer />
</template>

<style scoped>
.shop-container {
    max-width: 1400px;
    margin: 0 auto;
    padding: 20px;
    display: grid;
    grid-template-columns: 250px 1fr;
    gap: 30px;
}

@media (max-width: 900px) {
    .shop-container {
        grid-template-columns: 1fr;
        gap: 24px;
    }

    .shop-sidebar {
        order: -1;
    }
}

.shop-sidebar {
    position: sticky;
    top: 100px;
    height: fit-content;
}

.shop-main {
    min-height: 400px;
}

.shop-header {
    margin-bottom: 24px;
}

.shop-title {
    font-size: 28px;
    font-weight: 700;
    margin: 0 0 8px;
    color: var(--clr-text);
}

.shop-count {
    font-size: 14px;
    color: var(--clr-text-muted);
    margin: 0;
}

.products-grid {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 20px;
    list-style: none;
    padding: 0;
    margin: 0;
}

@media (max-width: 1200px) {
    .products-grid {
        grid-template-columns: repeat(3, 1fr);
    }
}

@media (max-width: 900px) {
    .products-grid {
        grid-template-columns: repeat(2, 1fr);
    }
}

@media (max-width: 600px) {
    .products-grid {
        grid-template-columns: 1fr;
    }
}

.no-products {
    text-align: center;
    padding: 60px 20px;
    background: white;
    border-radius: var(--radius);
}

.no-products p {
    color: var(--clr-text-muted);
    margin: 0 0 16px;
}

.clear-filters {
    color: var(--clr-accent);
    text-decoration: none;
    font-weight: 600;
}

.clear-filters:hover {
    text-decoration: underline;
}

.pagination {
    display: flex;
    justify-content: center;
    gap: 8px;
    margin-top: 40px;
    flex-wrap: wrap;
}

.page-link {
    padding: 8px 14px;
    background: white;
    border: 1px solid var(--clr-border);
    border-radius: var(--radius-sm);
    color: var(--clr-text);
    text-decoration: none;
    font-size: 14px;
    transition: all 0.2s ease;
}

.page-link:hover:not(.disabled) {
    border-color: var(--clr-accent);
    color: var(--clr-accent);
}

.page-link.active {
    background: var(--clr-accent);
    border-color: var(--clr-accent);
    color: white;
}

.page-link.disabled {
    opacity: 0.5;
    cursor: not-allowed;
}
</style>
