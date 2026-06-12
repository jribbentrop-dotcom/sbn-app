<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import { useCart } from '@/composables/useCart';
import { getCategoryStyle, getStyleSlug, getDifficultyFromCategories, difficultyLabel } from '@/composables/useCategoryColors';
import ProductPrice from './ProductPrice.vue';
import type { Product } from '@/types/shop';

const props = defineProps<{ product: Product }>();
const { addToCart } = useCart();

const styleSlug = computed(() => getStyleSlug(props.product?.categories ?? []));
const cardStyle = computed(() => getCategoryStyle(styleSlug.value));

const genreLabel = computed(() =>
    styleSlug.value ? styleSlug.value.replace(/-/g, ' ') : ''
);

const stars = computed(() => getDifficultyFromCategories(props.product?.categories ?? []));
const levelLabel = computed(() => difficultyLabel(stars.value));

const typeCategories = computed(() =>
    (props.product?.categories ?? []).filter(c =>
        ['solo-guitar','chords','transcriptions','bundles'].includes(c.slug)
    )
);
</script>

<template>
    <article class="sbn-product-card" :style="cardStyle">

        <!-- Image area -->
        <Link :href="`/shop/product/${product.slug}`" class="sbn-product-card-image-wrap">

            <img
                v-if="product.thumbnail_url"
                :src="product.thumbnail_url"
                :alt="product.title"
                class="sbn-product-card-image"
            >
            <div v-else class="sbn-product-card-fallback"></div>

            <!-- Genre badge top-left -->
            <div v-if="genreLabel" class="sbn-product-card-badge-row">
                <span class="sbn-product-badge-genre">{{ genreLabel }}</span>
            </div>

            <!-- Hover overlay -->
            <div class="sbn-product-card-overlay">
                <span class="sbn-product-view-btn">View Details <span class="sbn-view-btn-arrow">→</span></span>
                <div v-if="typeCategories.length" class="sbn-product-overlay-types">
                    <span v-for="cat in typeCategories" :key="cat.id" class="sbn-product-overlay-type">{{ cat.name }}</span>
                </div>
            </div>

        </Link>

        <!-- Card body -->
        <div class="sbn-product-card-body">

            <!-- Level + type badges -->
            <div v-if="stars" class="sbn-product-card-meta">
                <span class="sbn-badge sbn-badge-muted">
                    <span class="sbn-product-card-stars">
                        <span v-for="i in 5" :key="i" :class="i <= stars ? 'star-filled' : 'star-empty'">★</span>
                    </span>
                    {{ levelLabel }}
                </span>
            </div>

            <h3 class="sbn-product-card-title">
                <Link :href="`/shop/product/${product.slug}`">{{ product.title }}</Link>
            </h3>

            <p v-if="product.excerpt" class="sbn-product-card-excerpt" v-html="product.excerpt"></p>

            <div class="sbn-product-card-footer">
                <ProductPrice :eur-cents="product.price_cents" />
                <button class="sbn-btn sbn-btn-outlined sbn-btn-sm sbn-product-add-btn" @click.prevent="addToCart(product, 1)">
                    Add to Cart
                </button>
            </div>

        </div>
    </article>
</template>

<style scoped>
.sbn-product-card {
    background: var(--clr-white);
    border: 1px solid var(--clr-border);
    border-radius: var(--radius);
    overflow: hidden;
    transition: border-color 0.2s var(--ease);
    position: relative;
    --category-color: var(--clr-style-default);
    --category-gradient: linear-gradient(
        135deg,
        var(--category-color) 0%,
        color-mix(in srgb, var(--category-color) 60%, white) 100%
    );
}

.sbn-product-card:hover {
    border-color: var(--clr-text-muted);
}

/* ── Image area — 3:4 (sheet music proportions) ── */

.sbn-product-card-image-wrap {
    display: block;
    position: relative;
    aspect-ratio: 4 / 3;
    overflow: hidden;
    text-decoration: none;
    background: var(--clr-white);
}

.sbn-product-card-image {
    width: 100%;
    height: 100%;
    object-fit: contain;
    padding: 12px;
    transition: transform 0.4s var(--ease);
}

.sbn-product-card:hover .sbn-product-card-image {
    transform: scale(1.03);
}

.sbn-product-card-fallback {
    width: 100%;
    height: 100%;
    background: var(--category-gradient);
}

/* ── Gradient hover overlay ── */

.sbn-product-card-image-wrap::before {
    content: '';
    position: absolute;
    inset: 0;
    background: var(--category-gradient);
    opacity: 0;
    transition: opacity 0.3s var(--ease);
    z-index: 1;
}

.sbn-product-card:hover .sbn-product-card-image-wrap::before {
    opacity: 0.78;
}

/* ── Genre badge top-left ── */

.sbn-product-card-badge-row {
    position: absolute;
    top: 10px;
    left: 10px;
    z-index: 10;
}

.sbn-product-badge-genre {
    background: var(--category-gradient);
    color: var(--clr-white);
    padding: 4px 10px;
    border-radius: var(--radius-sm);
    font-size: 0.7em;
    font-weight: 600;
    text-transform: capitalize;
    transition: background 0.3s var(--ease), color 0.3s var(--ease);
}

.sbn-product-card:hover .sbn-product-badge-genre {
    background: var(--clr-white);
    color: var(--clr-text);
}

/* ── Hover overlay button ── */

.sbn-product-card-overlay {
    position: absolute;
    inset: 0;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    pointer-events: none;
    z-index: 5;
}

.sbn-product-view-btn {
    background: var(--clr-white);
    color: var(--clr-text);
    padding: 8px 20px;
    border-radius: var(--radius-sm);
    font-weight: 600;
    font-size: 0.82em;
    letter-spacing: 0.02em;
    opacity: 0;
    transform: translateY(6px) scale(0.94);
    transition: opacity 0.25s var(--ease), transform 0.25s var(--ease), box-shadow 0.2s var(--ease);
}

.sbn-product-card:hover .sbn-product-view-btn {
    opacity: 1;
    transform: translateY(0) scale(1);
}

.sbn-product-overlay-types {
    position: absolute;
    bottom: 12px;
    left: 0;
    right: 0;
    display: flex;
    justify-content: center;
    gap: 6px;
    opacity: 0;
    transform: translateY(4px);
    transition: opacity 0.25s var(--ease) 0.05s, transform 0.25s var(--ease) 0.05s;
}

.sbn-product-card:hover .sbn-product-overlay-types {
    opacity: 1;
    transform: translateY(0);
}

.sbn-product-overlay-type {
    background: rgba(255,255,255,0.65);
    border: 1px solid rgba(255,255,255,0.9);
    color: var(--clr-text);
    padding: 3px 10px;
    border-radius: var(--radius-sm);
    font-size: 0.72em;
    font-weight: 600;
    text-transform: capitalize;
    backdrop-filter: blur(4px);
}

.sbn-view-btn-arrow {
    display: inline-block;
    transition: transform 0.2s var(--ease);
}

.sbn-product-view-btn:hover .sbn-view-btn-arrow {
    transform: translateX(4px);
}

/* ── Card body ── */

.sbn-product-card-body {
    padding: 12px 14px 14px;
}

.sbn-product-card-meta {
    display: flex;
    flex-wrap: wrap;
    gap: 5px;
    margin-bottom: 8px;
}

.sbn-product-card-stars {
    display: inline-flex;
    gap: 1px;
    margin-right: 4px;
}

.sbn-product-card-stars .star-filled { color: var(--clr-star); }
.sbn-product-card-stars .star-empty  { color: var(--clr-border); }

.sbn-product-card-title {
    margin: 0 0 6px;
    font-size: 1em;
    font-weight: 600;
}

.sbn-product-card-title a {
    color: var(--clr-text);
    text-decoration: none;
}

.sbn-product-card-title a:hover {
    color: var(--clr-accent);
}

.sbn-product-add-btn:hover {
    background: var(--clr-accent) !important;
    border-color: var(--clr-accent) !important;
    color: #fff !important;
}

.sbn-product-card-excerpt {
    margin: 0 0 10px;
    color: var(--clr-text-muted);
    font-size: 0.875em;
    line-height: 1.45;
    display: -webkit-box;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
    overflow: hidden;
}

.sbn-product-card-footer {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-top: 10px;
    gap: 8px;
}

</style>
