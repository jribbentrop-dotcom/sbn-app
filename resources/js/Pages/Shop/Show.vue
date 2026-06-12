<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import Breadcrumb from '@/Components/Breadcrumb.vue';
import ProductCard from '@/Components/Shop/ProductCard.vue';
import ProductPrice from '@/Components/Shop/ProductPrice.vue';
import CartDrawer from '@/Components/Shop/CartDrawer.vue';
import { useCart } from '@/composables/useCart';
import { useCategoryColors, getStyleSlug, getCategoryColor } from '@/composables/useCategoryColors';
import type { Product } from '@/types/shop';

interface Props {
    product: Product;
    related: Product[];
    meta: { title: string; description: string };
}

const props = defineProps<Props>();
const { addToCart } = useCart();
const { getCategoryStyle } = useCategoryColors();

const pageStyle = computed(() => {
    const slug = getStyleSlug(props.product?.categories ?? []);
    return getCategoryStyle(slug);
});

const handleAddToCart = () => addToCart(props.product, 1);

const activeSlide = ref<'image' | 'video'>('image');
const videoPlaying = ref(false);

// ── Attribute helper ─────────────────────────────────────────
const attr = (key: string): string => {
    const val = props.product.attributes?.[key];
    if (!val) return '';
    return Array.isArray(val) ? val.join(', ') : String(val);
};

// ── Difficulty from categories ───────────────────────────────
const DIFFICULTY_SLUGS: Record<string, number> = {
    'basic': 1, 'early-intermediate': 2, 'intermediate': 3,
    'late-intermediate': 4, 'advanced': 5,
};

const difficulty = computed(() => {
    for (const cat of props.product.categories) {
        const stars = DIFFICULTY_SLUGS[cat.slug];
        if (stars !== undefined) return { label: cat.name, stars };
    }
    return null;
});

// Style category = first category not in difficulty slugs
const styleCategory = computed(() => {
    const slug = getStyleSlug(props.product.categories);
    return slug ? props.product.categories.find(c => c.slug === slug) ?? null : null;
});

const breadcrumbColor = computed(() => {
    const slug = getStyleSlug(props.product.categories);
    return getCategoryColor(slug);
});

const breadcrumbSegments = computed(() => {
    const segs: { label: string; href?: string }[] = [{ label: 'Shop', href: '/shop' }];
    if (styleCategory.value) segs.push({ label: styleCategory.value.name, href: `/shop/category/${styleCategory.value.slug}` });
    segs.push({ label: props.product.title });
    return segs;
});

// ── Notation types ───────────────────────────────────────────
const notationRaw = computed(() => attr('notation').toLowerCase());
const hasStandard  = computed(() => notationRaw.value.includes('standard') || notationRaw.value.includes('notation'));
const hasTab       = computed(() => notationRaw.value.includes('tab'));
const hasChordGrid = computed(() => notationRaw.value.includes('chord'));

// Only show features row if at least one chip applies
const hasAnyFeature = computed(() =>
    hasStandard.value || hasTab.value || hasChordGrid.value || !!attr('pages')
);
</script>

<template>
    <div>
        <Head>
            <title>{{ meta.title }}</title>
            <meta name="description" :content="meta.description" />
            <meta property="og:title" :content="product.title" />
            <meta property="og:description" :content="product.excerpt || meta.description" />
            <meta property="og:image" :content="product.thumbnail_url || ''" />
        </Head>

        <div class="sbn-single-product" :style="pageStyle">

            <Breadcrumb :segments="breadcrumbSegments" :color="breadcrumbColor" />

            <!-- Two-column main -->
            <div class="sbn-product-main sbn-detail-hero">

                <!-- Left: sticky gallery -->
                <div class="sbn-product-gallery">

                    <div class="sbn-gallery-main">
                        <!-- Image slide -->
                        <div v-show="activeSlide === 'image'" class="sbn-main-image">
                            <img
                                v-if="product.thumbnail_url"
                                :src="product.thumbnail_url"
                                :alt="product.title"
                            />
                            <div v-else class="sbn-no-image">No Image Available</div>
                        </div>

                        <!-- Video slide -->
                        <div v-if="product.video_id" v-show="activeSlide === 'video'" class="sbn-main-video">
                            <!-- Poster: click to play -->
                            <div v-if="!videoPlaying" class="sbn-video-poster" @click="videoPlaying = true">
                                <img
                                    :src="`https://img.youtube.com/vi/${product.video_id}/maxresdefault.jpg`"
                                    :alt="product.title"
                                />
                                <div class="sbn-video-play-btn">
                                    <svg viewBox="0 0 24 24" fill="currentColor"><path d="M8 5v14l11-7z"/></svg>
                                </div>
                            </div>
                            <!-- Iframe: only mounted after click -->
                            <iframe
                                v-if="videoPlaying"
                                :src="`https://www.youtube.com/embed/${product.video_id}?autoplay=1`"
                                :title="product.title"
                                allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture"
                                allowfullscreen
                            ></iframe>
                        </div>
                    </div>

                    <!-- Slide tabs — centered below -->
                    <div v-if="product.video_id" class="sbn-gallery-tabs">
                        <button
                            :class="['sbn-gallery-tab', { active: activeSlide === 'image' }]"
                            @click="activeSlide = 'image'; videoPlaying = false"
                        >
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="currentColor"><path d="M21 19V5c0-1.1-.9-2-2-2H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2zM8.5 13.5l2.5 3.01L14.5 12l4.5 6H5l3.5-4.5z"/></svg>
                            Image
                        </button>
                        <button
                            :class="['sbn-gallery-tab', { active: activeSlide === 'video' }]"
                            @click="activeSlide = 'video'"
                        >
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="currentColor"><path d="M8 5v14l11-7z"/></svg>
                            Video
                        </button>
                    </div>
                </div>

                <!-- Right: product info -->
                <div class="sbn-product-info">

                    <!-- Difficulty badge -->
                    <div v-if="difficulty" class="sbn-difficulty-badge">
                        <span>{{ difficulty.label }}</span>
                        <span class="stars">
                            <span v-for="i in 5" :key="i">{{ i <= difficulty.stars ? '★' : '☆' }}</span>
                        </span>
                    </div>

                    <h1 class="sbn-product-title">{{ product.title }}</h1>

                    <!-- Style + subcategory links -->
                    <div v-if="product.categories.length" class="sbn-product-subtitle">
                        <Link
                            v-if="styleCategory"
                            :href="`/shop/category/${styleCategory.slug}`"
                            class="sbn-style-link"
                        >{{ styleCategory.name }}</Link>
                        <template v-for="cat in product.categories" :key="cat.id">
                            <Link
                                v-if="DIFFICULTY_SLUGS[cat.slug] === undefined && cat.id !== styleCategory?.id"
                                :href="`/shop/category/${cat.slug}`"
                                class="sbn-subcat-link"
                            >{{ cat.name }}</Link>
                        </template>
                    </div>

                    <!-- Price -->
                    <div class="sbn-price-wrapper">
                        <ProductPrice :eur-cents="product.price_cents" size="lg" show-toggle />
                    </div>

                    <!-- Short description / excerpt -->
                    <div v-if="product.excerpt" class="sbn-short-description" v-html="product.excerpt" />

                    <!-- Feature chips grid (PDF pages, notation types) -->
                    <div v-if="hasAnyFeature" class="sbn-features-grid">
                        <div v-if="attr('pages')" class="sbn-feature-item">
                            <span class="sbn-feature-icon">
                                <svg viewBox="0 0 24 24"><path d="M14 2H6c-1.1 0-2 .9-2 2v16c0 1.1.9 2 2 2h12c1.1 0 2-.9 2-2V8l-6-6zm4 18H6V4h7v5h5v11z"/><path d="M8 16h8v2H8zm0-4h8v2H8zm0-4h5v2H8z"/></svg>
                            </span>
                            <span>PDF, {{ attr('pages') }} pages</span>
                        </div>
                        <div v-if="hasChordGrid" class="sbn-feature-item">
                            <span class="sbn-feature-icon">
                                <svg viewBox="0 0 24 24"><path d="M4 6h16v2H4zm0 5h16v2H4zm0 5h16v2H4z"/><circle cx="7" cy="9" r="1.5"/><circle cx="12" cy="14" r="1.5"/><circle cx="17" cy="9" r="1.5"/></svg>
                            </span>
                            <span>Chord Grids</span>
                        </div>
                        <div v-if="hasStandard" class="sbn-feature-item">
                            <span class="sbn-feature-icon">
                                <svg viewBox="0 0 24 24"><path d="M12 3v10.55c-.59-.34-1.27-.55-2-.55-2.21 0-4 1.79-4 4s1.79 4 4 4 4-1.79 4-4V7h4V3h-6z"/></svg>
                            </span>
                            <span>Standard Notation</span>
                        </div>
                        <div v-if="hasTab" class="sbn-feature-item">
                            <span class="sbn-feature-icon">
                                <svg viewBox="0 0 24 24"><path d="M20 4H4c-1.1 0-2 .9-2 2v12c0 1.1.9 2 2 2h16c1.1 0 2-.9 2-2V6c0-1.1-.9-2-2-2zM4 18V6h16v12H4z"/><path d="M6 8h12v1.5H6zm0 3h12v1.5H6zm0 3h12v1.5H6z"/></svg>
                            </span>
                            <span>Tablature</span>
                        </div>
                    </div>

                    <!-- Meta bar: PAGES / FORMAT / LEVEL -->
                    <div class="sbn-product-meta">
                        <div v-if="attr('pages')" class="sbn-meta-item">
                            <span class="sbn-meta-label">Pages:</span>
                            <span class="sbn-meta-value">{{ attr('pages') }}</span>
                        </div>
                        <div class="sbn-meta-item">
                            <span class="sbn-meta-label">Format:</span>
                            <span class="sbn-meta-value">{{ attr('format') || 'PDF' }}</span>
                        </div>
                        <div v-if="attr('composer')" class="sbn-meta-item">
                            <span class="sbn-meta-label">Composer:</span>
                            <span class="sbn-meta-value">{{ attr('composer') }}</span>
                        </div>
                        <div v-if="attr('performer')" class="sbn-meta-item">
                            <span class="sbn-meta-label">Performer:</span>
                            <span class="sbn-meta-value">{{ attr('performer') }}</span>
                        </div>
                        <div v-if="difficulty" class="sbn-meta-item">
                            <span class="sbn-meta-label">Level:</span>
                            <span class="sbn-meta-value">{{ difficulty.label }}</span>
                        </div>
                    </div>

                    <!-- Add to Cart -->
                    <button class="sbn-add-to-cart-btn" @click="handleAddToCart">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                            <circle cx="9" cy="21" r="1"/><circle cx="20" cy="21" r="1"/>
                            <path d="m1 1 4 0 2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"/>
                        </svg>
                        Add to Cart
                    </button>

                </div>
            </div>

            <!-- Long description -->
            <div v-if="product.description" class="sbn-product-description-section">
                <h2>Description</h2>
                <div class="sbn-description-content" v-html="product.description" />
            </div>

            <!-- Related products -->
            <div v-if="related.length" class="sbn-related-section">
                <div class="sbn-related-header">
                    <h2>Related Products</h2>
                    <Link
                        v-if="styleCategory"
                        :href="`/shop/category/${styleCategory.slug}`"
                    >View All →</Link>
                </div>
                <div class="sbn-related-grid">
                    <ProductCard
                        v-for="item in related"
                        :key="item.id"
                        :product="item"
                    />
                </div>
            </div>

        </div>

        <CartDrawer />
    </div>
</template>

<style scoped>
/* ── Page shell ─────────────────────────────────────────────── */
.sbn-single-product {
    max-width: 1200px;
    margin: 0 auto;
    padding: 20px;
    --category-color: var(--clr-style-default);
    --category-gradient: linear-gradient(
        135deg,
        var(--category-color) 0%,
        color-mix(in srgb, var(--category-color) 60%, white) 100%
    );
}

/* Breadcrumb sits naturally inside shell, flush above the detail hero frame */
.sbn-single-product > .sbn-breadcrumb {
    border-radius: var(--radius) var(--radius) 0 0;
    margin-bottom: 0;
}

/* ── Two-column main ────────────────────────────────────────── */
.sbn-product-main {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 50px;
    padding: 28px 0;
    margin-bottom: 50px;
    align-items: start;
}

/* ── Gallery (left) ─────────────────────────────────────────── */
.sbn-product-gallery {
    position: sticky;
    top: 100px;
    height: fit-content;
}

.sbn-gallery-tabs {
    display: flex;
    justify-content: center;
    gap: 6px;
    margin-top: 10px;
}

.sbn-gallery-tab {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    padding: 6px 14px;
    border-radius: var(--radius-sm);
    border: 1px solid var(--clr-border);
    background: var(--clr-white);
    color: var(--clr-text-muted);
    font-size: 13px;
    font-weight: 500;
    cursor: pointer;
    transition: all 0.15s;
    font-family: var(--font-body);
}

.sbn-gallery-tab:hover {
    border-color: var(--clr-text-muted);
    color: var(--clr-text);
}

.sbn-gallery-tab.active {
    background: var(--clr-text);
    border-color: var(--clr-text);
    color: #fff;
}

.sbn-gallery-main {
    position: relative;
    aspect-ratio: 4 / 3;
    background: var(--clr-white);
    border-radius: var(--radius-lg);
    overflow: hidden;
}

.sbn-main-image {
    position: absolute;
    inset: 0;
    display: flex;
    align-items: center;
    justify-content: center;
    background: var(--clr-white);
    padding: 16px;
}

.sbn-main-image img {
    max-width: 100%;
    max-height: 100%;
    object-fit: contain;
    image-rendering: auto;
}

.sbn-no-image {
    color: var(--clr-text-muted);
    font-size: 14px;
}

.sbn-main-video {
    position: absolute;
    inset: 0;
}

.sbn-main-video iframe {
    width: 100%;
    height: 100%;
    border: none;
    display: block;
}

.sbn-video-poster {
    position: absolute;
    inset: 0;
    cursor: pointer;
}

.sbn-video-poster img {
    width: 100%;
    height: 100%;
    object-fit: contain;
    display: block;
    background: #000;
}

.sbn-video-play-btn {
    position: absolute;
    inset: 0;
    display: flex;
    align-items: center;
    justify-content: center;
}

.sbn-video-play-btn svg {
    width: 64px;
    height: 64px;
    color: #fff;
    filter: drop-shadow(0 2px 8px rgba(0,0,0,0.5));
    transition: transform 0.15s;
}

.sbn-video-poster:hover .sbn-video-play-btn svg {
    transform: scale(1.12);
}

/* ── Product info (right) ───────────────────────────────────── */
.sbn-product-info { padding-top: 10px; }

/* Difficulty pill */
.sbn-difficulty-badge {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    background: var(--clr-surface-2);
    color: var(--clr-text-dim);
    padding: 8px 16px;
    border-radius: 20px;
    font-size: 13px;
    font-weight: 500;
    margin-bottom: 15px;
    border: 1px solid var(--clr-border);
}
.sbn-difficulty-badge .stars { color: var(--clr-star); }

/* Title */
.sbn-product-title {
    font-size: 2.2em;
    font-weight: 700;
    color: var(--clr-text);
    margin: 0 0 8px;
    line-height: 1.2;
}

/* Subtitle: style + subcat links */
.sbn-product-subtitle {
    display: flex;
    align-items: center;
    gap: 8px;
    margin-bottom: 20px;
    flex-wrap: wrap;
}

.sbn-style-link {
    color: var(--clr-white);
    background: var(--category-color);
    padding: 4px 12px;
    border-radius: 15px;
    text-decoration: none;
    font-size: 13px;
    font-weight: 600;
}

.sbn-subcat-link {
    color: var(--clr-text-dim);
    background: var(--clr-surface-3);
    padding: 4px 10px;
    border-radius: 12px;
    text-decoration: none;
    font-size: 12px;
    font-weight: 500;
}
.sbn-subcat-link:hover { background: var(--clr-border); }

/* Price */
.sbn-price-wrapper {
    margin-bottom: 20px;
}

/* Short description */
.sbn-short-description {
    color: var(--clr-text-dim);
    line-height: 1.8;
    margin-bottom: 25px;
    font-size: 15px;
}

/* ── Feature chips: 2-column grid ───────────────────────────── */
.sbn-features-grid {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 12px;
    margin-bottom: 20px;
}

.sbn-feature-item {
    display: flex;
    align-items: center;
    gap: 10px;
    background: var(--clr-surface-2);
    padding: 12px 15px;
    border-radius: var(--radius);
    font-size: 13px;
    color: var(--clr-text-dim);
    border: 1px solid var(--clr-border);
}

.sbn-feature-icon {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 28px;
    height: 28px;
    flex-shrink: 0;
    background: var(--category-color);
    border-radius: 6px;
    color: var(--clr-white);
}

.sbn-feature-icon svg {
    width: 16px;
    height: 16px;
    fill: currentColor;
}

/* ── Meta bar: PAGES / FORMAT / LEVEL ───────────────────────── */
.sbn-product-meta {
    display: flex;
    gap: 20px;
    margin-bottom: 25px;
    padding: 15px;
    background: var(--clr-surface-2);
    border-radius: var(--radius);
    flex-wrap: wrap;
}

.sbn-meta-item {
    display: flex;
    flex-direction: column;
    gap: 2px;
}

.sbn-meta-label {
    font-size: 11px;
    text-transform: uppercase;
    color: var(--clr-text-muted);
    letter-spacing: 0.05em;
    font-weight: 600;
}

.sbn-meta-value {
    font-weight: 600;
    color: var(--clr-text);
    font-size: 14px;
}

/* ── Add to cart button ─────────────────────────────────────── */
.sbn-add-to-cart-btn {
    width: 100%;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    background: var(--clr-gradient);
    color: var(--clr-white);
    padding: 16px 30px;
    border-radius: var(--radius);
    border: none;
    font-size: 16px;
    font-weight: 600;
    cursor: pointer;
    transition: transform 0.2s var(--ease), box-shadow 0.2s var(--ease);
    font-family: var(--font-body);
    margin-bottom: 20px;
}
.sbn-add-to-cart-btn:hover {
    transform: translateY(-2px);
    box-shadow: var(--clr-shadow);
}

/* ── Long description ───────────────────────────────────────── */
.sbn-product-description-section {
    margin-bottom: 50px;
    padding-top: 32px;
    border-top: 1px solid var(--clr-border);
}
.sbn-product-description-section h2 {
    font-size: 1.4em;
    font-weight: 700;
    color: var(--clr-text);
    margin: 0 0 20px;
}
.sbn-description-content {
    font-size: 15px;
    line-height: 1.75;
    color: var(--clr-text-dim);
}

/* ── Related products ───────────────────────────────────────── */

/* Header: gradient banner matching legacy */
.sbn-related-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 20px 30px;
    background: var(--clr-gradient);
    border-radius: var(--radius);
    margin-bottom: 25px;
}
.sbn-related-header h2 {
    font-size: 1.5em;
    color: var(--clr-white);
    margin: 0;
}
.sbn-related-header a {
    color: var(--clr-white);
    background: rgba(255, 255, 255, 0.2);
    padding: 8px 20px;
    border-radius: var(--radius-sm);
    text-decoration: none;
    font-weight: 600;
    font-size: 14px;
    transition: background 0.2s, color 0.2s;
}
.sbn-related-header a:hover {
    background: var(--clr-white);
    color: var(--clr-text);
}

/* Grid: 4-up, matches shop index */
.sbn-related-grid {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 20px;
}

/* ── Responsive ─────────────────────────────────────────────── */
@media (max-width: 1024px) {
    .sbn-product-main { grid-template-columns: 1fr 1fr; gap: 30px; }
    .sbn-product-gallery { position: static; }
    .sbn-related-grid { grid-template-columns: repeat(3, 1fr); }
}

@media (max-width: 768px) {
    .sbn-product-main { grid-template-columns: 1fr; gap: 24px; }
    .sbn-related-grid { grid-template-columns: repeat(2, 1fr); }
}

@media (max-width: 480px) {
    .sbn-features-grid { grid-template-columns: 1fr; }
    .sbn-related-grid { grid-template-columns: 1fr; }
}
</style>
