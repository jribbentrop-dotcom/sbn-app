<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import ProductCard from '@/Components/Shop/ProductCard.vue';
import ProductPrice from '@/Components/Shop/ProductPrice.vue';
import CartDrawer from '@/Components/Shop/CartDrawer.vue';
import { useCart } from '@/composables/useCart';
import { useCategoryColors } from '@/composables/useCategoryColors';
import type { Product } from '@/types/shop';

interface Props {
    product: Product;
    related: Product[];
    meta: {
        title: string;
        description: string;
    };
}

const props = defineProps<Props>();
const { addToCart } = useCart();
const { getStyle } = useCategoryColors();

const pageStyle = computed(() => {
    const firstCategory = props.product.categories?.[0];
    return getStyle(firstCategory?.slug);
});

const handleAddToCart = () => {
    addToCart(props.product, 1);
};

const formatAttribute = (key: string, value: string | string[]): string => {
    if (Array.isArray(value)) {
        return value.join(', ');
    }
    return value;
};
</script>

<template>
    <Head>
        <title>{{ meta.title }}</title>
        <meta name="description" :content="meta.description" />
        <meta property="og:title" :content="product.title" />
        <meta property="og:description" :content="product.excerpt || meta.description" />
        <meta property="og:image" :content="product.thumbnail_url || ''" />
    </Head>

    <div class="product-show" :style="pageStyle">
            <!-- Breadcrumb -->
            <nav class="sbn-breadcrumb">
                <ul>
                    <li><Link href="/shop">Shop</Link></li>
                    <li v-if="product.categories.length > 0">
                        <Link :href="`/shop/category/${product.categories[0].slug}`">
                            {{ product.categories[0].name }}
                        </Link>
                    </li>
                    <li>{{ product.title }}</li>
                </ul>
            </nav>

            <div class="product-layout">
                <!-- Product Image -->
                <div class="product-image">
                    <img
                        v-if="product.thumbnail_url"
                        :src="product.thumbnail_url"
                        :alt="product.title"
                    />
                    <div v-else class="placeholder-image">
                        <span>No Image Available</span>
                    </div>
                </div>

                <!-- Product Info -->
                <div class="product-info">
                    <h1 class="product-title">{{ product.title }}</h1>

                    <div v-if="product.excerpt" class="product-excerpt" v-html="product.excerpt" />

                    <div class="product-price-box">
                        <ProductPrice :eur-cents="product.price_cents" size="lg" show-toggle />
                    </div>

                    <div class="product-actions">
                        <button class="add-to-cart-btn" @click="handleAddToCart">
                            Add to Cart
                        </button>
                    </div>

                    <!-- Attributes -->
                    <div v-if="product.attributes" class="product-attributes">
                        <h3>Details</h3>
                        <dl class="attributes-list">
                            <div
                                v-for="(value, key) in product.attributes"
                                :key="key"
                                class="attribute-item"
                            >
                                <dt>{{ key.charAt(0).toUpperCase() + key.slice(1) }}</dt>
                                <dd>{{ formatAttribute(key, value) }}</dd>
                            </div>
                        </dl>
                    </div>

                    <!-- Categories -->
                    <div v-if="product.categories.length > 0" class="product-categories">
                        <span class="label">Categories:</span>
                        <span
                            v-for="category in product.categories"
                            :key="category.id"
                            class="category-tag"
                        >
                            {{ category.name }}
                        </span>
                    </div>
                </div>
            </div>

            <!-- Description -->
            <div v-if="product.description" class="product-description">
                <h2>Description</h2>
                <div class="description-content" v-html="product.description" />
            </div>

            <!-- Related Products -->
            <div v-if="related.length > 0" class="related-products">
                <h2>You May Also Like</h2>
                <div class="related-grid">
                    <ProductCard
                        v-for="item in related"
                        :key="item.id"
                        :product="item"
                    />
                </div>
            </div>
        </div>

    <CartDrawer />
</template>

<style scoped>
.product-show {
    max-width: 1200px;
    margin: 0 auto;
    padding: 40px 20px;
}

/* Breadcrumb - matches original .sbn-breadcrumb */
.sbn-breadcrumb {
    background: var(--category-gradient, var(--sbn-gradient, linear-gradient(135deg, #f39c12, #e74c3c)));
    padding: 15px 25px;
    border-radius: 10px;
    margin-bottom: 25px;
    color: white;
}

.sbn-breadcrumb ul {
    list-style: none;
    padding: 0;
    margin: 0;
    display: flex;
    align-items: center;
    gap: 10px;
    flex-wrap: wrap;
}

.sbn-breadcrumb li {
    font-size: 0.9em;
    color: rgba(255,255,255,0.9);
}

.sbn-breadcrumb li a {
    color: white;
    text-decoration: none;
    font-weight: 500;
}

.sbn-breadcrumb li a:hover {
    opacity: 0.8;
}

.sbn-breadcrumb li:last-child {
    color: white;
    font-weight: 600;
}

.sbn-breadcrumb li::after {
    content: "›";
    margin-left: 10px;
    opacity: 0.7;
}

.sbn-breadcrumb li:last-child::after {
    display: none;
}

.product-layout {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 48px;
    margin-bottom: 48px;
}

@media (max-width: 768px) {
    .product-layout {
        grid-template-columns: 1fr;
        gap: 32px;
    }
}

.product-image {
    border-radius: var(--radius);
    overflow: hidden;
    background: var(--clr-surface-2);
}

.product-image img {
    width: 100%;
    height: auto;
    display: block;
}

.placeholder-image {
    aspect-ratio: 3 / 4;
    display: flex;
    align-items: center;
    justify-content: center;
    color: var(--clr-text-muted);
}

.product-info {
    display: flex;
    flex-direction: column;
    gap: 20px;
}

.product-title {
    font-size: 32px;
    font-weight: 700;
    margin: 0;
    color: var(--clr-text);
    line-height: 1.2;
}

.product-excerpt {
    font-size: 16px;
    color: var(--clr-text-dim);
    line-height: 1.6;
    margin: 0;
}

.product-price-box {
    background: linear-gradient(135deg, rgba(243, 156, 18, 0.08) 0%, rgba(231, 76, 60, 0.08) 100%);
    padding: 20px;
    border-radius: var(--radius);
    border: 1px solid var(--clr-accent-border);
}

.add-to-cart-btn {
    background: var(--clr-gradient);
    color: white;
    border: none;
    padding: 16px 32px;
    border-radius: var(--radius-sm);
    font-size: 16px;
    font-weight: 700;
    cursor: pointer;
    transition: transform 0.2s ease;
    width: 100%;
}

.add-to-cart-btn:hover {
    transform: scale(1.02);
}

.product-attributes h3 {
    font-size: 16px;
    font-weight: 700;
    margin: 0 0 12px;
    color: var(--clr-text);
}

.attributes-list {
    display: grid;
    gap: 8px;
}

.attribute-item {
    display: flex;
    gap: 16px;
    font-size: 14px;
}

.attribute-item dt {
    font-weight: 600;
    color: var(--clr-text-dim);
    min-width: 100px;
}

.attribute-item dd {
    margin: 0;
    color: var(--clr-text);
}

.product-categories {
    display: flex;
    align-items: center;
    gap: 8px;
    flex-wrap: wrap;
    font-size: 14px;
}

.product-categories .label {
    color: var(--clr-text-muted);
}

.category-tag {
    background: var(--clr-surface-3);
    padding: 4px 12px;
    border-radius: 20px;
    color: var(--clr-text-dim);
}

.product-description {
    margin-bottom: 48px;
}

.product-description h2 {
    font-size: 24px;
    font-weight: 700;
    margin: 0 0 20px;
    color: var(--clr-text);
}

.description-content {
    font-size: 16px;
    line-height: 1.7;
    color: var(--clr-text-dim);
}

.description-content :deep(p) {
    margin: 0 0 16px;
}

.related-products h2 {
    font-size: 24px;
    font-weight: 700;
    margin: 0 0 24px;
    color: var(--clr-text);
}

/* Related Products Grid - 4 columns per original spec */
.related-grid {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 20px;
}

@media (max-width: 1200px) {
    .related-grid {
        grid-template-columns: repeat(3, 1fr);
    }
}

@media (max-width: 900px) {
    .related-grid {
        grid-template-columns: repeat(2, 1fr);
    }
}

@media (max-width: 600px) {
    .related-grid {
        grid-template-columns: 1fr;
    }
}
</style>
