<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import type { Product } from '@/types/shop';
import ProductPrice from './ProductPrice.vue';
import { useCart } from '@/composables/useCart';
import { useCategoryColors, getStyleSlug } from '@/composables/useCategoryColors';
import { computed } from 'vue';

interface Props {
    product: Product;
}

const props = defineProps<Props>();
const { addToCart } = useCart();
const { getCategoryStyle, getDifficultyLabel } = useCategoryColors();

const categoryStyle = computed(() => {
    const slug = getStyleSlug(props.product?.categories ?? []);
    return getCategoryStyle(slug);
});

const difficultyValue = computed(() => {
    if (!props.product?.attributes?.difficulty) return 1;
    const val = Array.isArray(props.product.attributes.difficulty) 
        ? props.product.attributes.difficulty[0] 
        : props.product.attributes.difficulty;
    const n = Number(val);
    return isNaN(n) ? 1 : Math.max(1, Math.min(5, n));
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
                <span 
                    v-if="product.attributes?.difficulty" 
                    class="sbn-product-difficulty"
                    :aria-label="`Difficulty: ${getDifficultyLabel(difficultyValue)}`"
                    :title="getDifficultyLabel(difficultyValue)"
                >
                    <span 
                        v-for="i in 5" 
                        :key="i" 
                        :class="i <= difficultyValue ? 'star-filled' : 'star-empty'"
                    >
                        {{ i <= difficultyValue ? '★' : '☆' }}
                    </span>
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
/* Card Container */
.sbn-product-card {
    background: var(--clr-white);
    border-radius: var(--radius);
    overflow: hidden;
    transition: box-shadow 0.3s var(--ease);
    position: relative;
    --category-color: var(--clr-style-default);
    --category-gradient: linear-gradient(
        135deg,
        var(--category-color) 0%,
        color-mix(in srgb, var(--category-color) 60%, white) 100%
    );
}

.sbn-product-card:hover {
    box-shadow: var(--clr-shadow);
}

/* Product Image - 3:4 aspect ratio */
.sbn-product-image {
    aspect-ratio: 3/4;
    background: var(--clr-white);
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
    background: var(--category-gradient);
    opacity: 0;
    transition: opacity 0.3s var(--ease);
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

/* Top Badge Row - Style (LEFT) + Difficulty (RIGHT) */
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

/* Style Badge (Bossa Nova, Jazz, etc) */
.sbn-product-badge-style {
    background: var(--category-gradient);
    color: var(--clr-white);
    padding: 5px 12px;
    border-radius: var(--radius-sm);
    font-size: 0.7em;
    font-weight: 600;
    text-transform: uppercase;
    transition: all 0.3s var(--ease);
}

.sbn-product-card:hover .sbn-product-badge-style {
    background: var(--clr-white);
    color: var(--clr-text);
}

/* Difficulty Stars */
.sbn-product-difficulty {
    color: var(--clr-star);
    padding: 5px 12px;
    border-radius: var(--radius-sm);
    font-size: 0.7em;
    display: flex;
    gap: 2px;
    background: transparent;
    transition: all 0.3s var(--ease);
    font-weight: 600;
}

.sbn-product-difficulty .star-filled {
    color: var(--clr-star);
}

.sbn-product-difficulty .star-empty {
    color: var(--clr-border);
}

.sbn-product-card:hover .sbn-product-difficulty {
    background: var(--clr-white);
}

/* Subcategory Badges (Bottom Center) */
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
    background: var(--clr-overlay-dark);
    color: var(--clr-white);
    padding: 5px 12px;
    border-radius: 4px;
    font-size: 0.7em;
    font-weight: 600;
    text-transform: uppercase;
    transition: all 0.3s var(--ease);
}

.sbn-product-card:hover .sbn-product-badge-subcat {
    background: rgba(255, 255, 255, 0.75);
    color: var(--clr-text);
}

/* Hover Overlay with Buttons */
.sbn-product-overlay {
    position: absolute;
    top: 50%;
    left: 50%;
    transform: translate(-50%, -50%);
    z-index: 10;
    opacity: 0;
    transition: opacity 0.3s var(--ease);
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
    background: var(--clr-white);
    color: var(--clr-text) !important;
    padding: 10px 24px;
    border-radius: var(--radius-sm);
    text-decoration: none;
    font-weight: 600;
    font-size: 0.85em;
    transition: all 0.3s var(--ease);
    display: inline-block;
}

.sbn-product-view-btn:hover {
    transform: translateY(-2px);
    box-shadow: var(--clr-shadow);
}

.sbn-product-quick-add {
    background: var(--clr-white);
    color: var(--clr-text) !important;
    padding: 10px 24px;
    border-radius: var(--radius-sm);
    text-decoration: none;
    font-weight: 600;
    font-size: 0.85em;
    border: none;
    cursor: pointer;
    transition: all 0.3s var(--ease);
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 6px;
    min-width: 140px;
}

.sbn-product-quick-add:hover {
    transform: translateY(-2px);
    box-shadow: var(--clr-shadow);
}

/* Product Info Below Image */
.sbn-product-info-bottom {
    padding: 5px 15px 8px;
    text-align: center;
    background: var(--clr-white);
}

.sbn-product-title-link {
    font-size: 0.9em;
    font-weight: 500;
    color: var(--clr-text);
    margin-bottom: 4px;
    display: block;
    text-decoration: none;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.sbn-product-title-link:hover {
    color: var(--clr-style-bossa);
}

.sbn-product-price-display {
    font-size: 1em;
    font-weight: 700;
    color: var(--clr-red);
}
</style>
