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
const { getCategoryStyle } = useCategoryColors();

const containerStyle = computed(() => {
    return getCategoryStyle(props.currentCategory?.slug);
});
</script>

<template>
    <div>
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
    </div>
</template>

<style scoped>
/* Component-local overrides if any */
.shop-main {
    transition: opacity 0.3s var(--ease);
}
</style>
