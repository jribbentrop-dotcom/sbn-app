<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import type { Product } from '@/types/shop';
import ProductPrice from './ProductPrice.vue';
import { useCart } from '@/composables/useCart';
import { getCategoryGradient, getCategoryStyle } from '@/composables/useCategoryColors';
import { computed } from 'vue';

interface Props {
    product: Product;
}

const props = defineProps<Props>();
const { addToCart } = useCart();

const categoryStyle = computed(() => {
    const firstCategory = props.product.categories?.[0];
    return getCategoryStyle(firstCategory?.slug);
});


const handleAddToCart = () => {
    addToCart(props.product, 1);
};

</script>

<template>
    <div class="sbn-product-card" :style="categoryStyle">
        <Link :href="`/shop/product/${product.slug}`" class="sbn-product-image">
            <!-- Top Badge Row: Style (LEFT) + Difficulty (RIGHT) -->
            <div class="sbn-product-badge-row" v-if="product.attributes?.style || product.attributes?.difficulty">
                <span v-if="product.attributes?.style" class="sbn-product-badge-style">
                    {{ Array.isArray(product.attributes.style) ? product.attributes.style[0] : product.attributes.style }}
                </span>
                <span v-if="product.attributes?.difficulty" class="sbn-product-difficulty">
                    <span class="star-filled" v-for="n in Math.max(1, Math.min(5, Number(Array.isArray(product.attributes.difficulty) ? product.attributes.difficulty[0] : product.attributes.difficulty) || 1))" :key="n">★</span>
                    <span class="star-empty" v-for="n in (5 - Math.max(1, Math.min(5, Number(Array.isArray(product.attributes.difficulty) ? product.attributes.difficulty[0] : product.attributes.difficulty) || 1)))" :key="'e'+n">☆</span>
                </span>
            </div>

            <!-- Subcategory Badges - Bottom Center -->
            <div class="sbn-product-badge-type" v-if="product.categories?.length">
                <span
                    v-for="cat in product.categories.slice(0, 2)"
                    :key="cat.id"
                    class="sbn-product-badge-subcat"
                >
                    {{ cat.name }}
                </span>
            </div>

            <img
                v-if="product.thumbnail_url"
                :src="product.thumbnail_url"
                :alt="product.title"
            />
            <div v-else class="sbn-placeholder-thumbnail">
                <span>No Image</span>
            </div>

            <!-- Hover Overlay -->
            <div class="sbn-product-overlay">
                <Link :href="`/shop/product/${product.slug}`" class="sbn-product-view-btn">
                    View Details
                </Link>
                <button
                    class="sbn-product-quick-add"
                    @click.prevent="handleAddToCart"
                >
                    Add to Cart
                </button>
            </div>
        </Link>

        <div class="sbn-product-info-bottom">
            <Link :href="`/shop/product/${product.slug}`" class="sbn-product-title-link">
                {{ product.title }}
            </Link>
            <div class="sbn-product-price-display">
                €{{ (product.price_cents / 100).toFixed(2) }}
            </div>
        </div>
    </div>
</template>

<style scoped>
/* Card Container - matches original .sbn-product-card */
.sbn-product-card {
    background: white;
    border-radius: 12px;
    overflow: hidden;
    transition: box-shadow 0.3s ease;
    position: relative;
}

.sbn-product-card:hover {
    box-shadow: 0 6px 15px rgba(0,0,0,0.08);
}

/* Product Image - 3:4 aspect ratio per original spec */
.sbn-product-image {
    aspect-ratio: 3/4;
    background: white;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 15px;
    position: relative;
    overflow: hidden;
}

/* Gradient Overlay on Hover */
.sbn-product-image::before {
    content: '';
    position: absolute;
    inset: 0;
    background: var(--category-gradient, var(--sbn-gradient, linear-gradient(135deg, #f39c12, #e74c3c)));
    opacity: 0;
    transition: opacity 0.3s ease;
    z-index: 1;
}

.sbn-product-card:hover .sbn-product-image::before {
    opacity: 0.75;
}

.sbn-product-image img {
    width: 100%;
    height: 100%;
    object-fit: contain;
    position: relative;
    z-index: 0;
}

.sbn-placeholder-thumbnail {
    width: 100%;
    height: 100%;
    display: flex;
    align-items: center;
    justify-content: center;
    color: var(--clr-text-muted);
    font-size: 14px;
}

/* Top Badge Row - Style (LEFT) + Difficulty (RIGHT) per original */
.sbn-product-badge-row {
    position: absolute;
    top: 12px;
    left: 12px;
    right: 12px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    z-index: 10;
}

/* Style Badge (Bossa Nova, Jazz, etc) - uses category gradient per original */
.sbn-product-badge-style {
    background: var(--category-gradient, var(--sbn-gradient, linear-gradient(135deg, #f39c12, #e74c3c)));
    color: white;
    padding: 5px 12px;
    border-radius: 6px;
    font-size: 0.7em;
    font-weight: 600;
    text-transform: uppercase;
    transition: all 0.3s ease;
}

.sbn-product-card:hover .sbn-product-badge-style {
    background: white;
    color: #333;
}

/* Difficulty Stars - per original */
.sbn-product-difficulty {
    color: #ffc107;
    padding: 5px 12px;
    border-radius: 6px;
    font-size: 0.7em;
    display: flex;
    gap: 2px;
    background: transparent;
    transition: all 0.3s ease;
    font-weight: 600;
}

.sbn-product-difficulty .star-filled {
    color: #ffc107;
}

.sbn-product-difficulty .star-empty {
    color: rgba(0,0,0,0.25);
}

.sbn-product-card:hover .sbn-product-difficulty {
    background: white;
}

/* Subcategory Badges (Bottom Center) - per original */
.sbn-product-badge-type {
    position: absolute;
    bottom: 50px;
    left: 50%;
    transform: translateX(-50%);
    z-index: 10;
    display: flex;
    gap: 5px;
}

.sbn-product-badge-subcat {
    background: rgba(45, 55, 72, 0.75);
    color: white;
    padding: 5px 12px;
    border-radius: 4px;
    font-size: 0.7em;
    font-weight: 600;
    text-transform: uppercase;
    transition: all 0.3s ease;
}

.sbn-product-card:hover .sbn-product-badge-subcat {
    background: rgba(255, 255, 255, 0.75);
    color: #2d3748;
}

/* Hover Overlay with Buttons */
.sbn-product-overlay {
    position: absolute;
    top: 50%;
    left: 50%;
    transform: translate(-50%, -50%);
    z-index: 10;
    opacity: 0;
    transition: opacity 0.3s ease;
    pointer-events: none;
    display: flex;
    flex-direction: column;
    gap: 10px;
    align-items: center;
    width: 90%;
    text-align: center;
}

.sbn-product-card:hover .sbn-product-overlay {
    opacity: 1;
    pointer-events: auto;
}

.sbn-product-view-btn {
    background: white;
    color: #2d3748 !important;
    padding: 10px 24px;
    border-radius: 6px;
    text-decoration: none;
    font-weight: 600;
    font-size: 0.85em;
    transition: all 0.3s ease;
    display: inline-block;
}

.sbn-product-view-btn:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(0,0,0,0.2);
}

.sbn-product-quick-add {
    background: white;
    color: #2d3748 !important;
    padding: 10px 24px;
    border-radius: 6px;
    text-decoration: none;
    font-weight: 600;
    font-size: 0.85em;
    border: none;
    cursor: pointer;
    transition: all 0.3s ease;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 6px;
    min-width: 140px;
}

.sbn-product-quick-add:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(0,0,0,0.2);
}

/* Product Info Below Image */
.sbn-product-info-bottom {
    padding: 5px 15px 8px;
    text-align: center;
    background: white;
}

.sbn-product-title-link {
    font-size: 0.9em;
    font-weight: 500;
    color: #2d3748;
    margin-bottom: 4px;
    display: block;
    text-decoration: none;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.sbn-product-title-link:hover {
    color: var(--sbn-orange, #f39c12);
}

.sbn-product-price-display {
    font-size: 1em;
    font-weight: 700;
    color: #e74c3c;
}
</style>
