<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import type { Category } from '@/types/shop';
import { getCategoryStyle } from '@/composables/useCategoryColors';

interface Props {
    categories: Category[];
    currentCategory?: Category | null;
}

defineProps<Props>();
</script>

<template>
    <div class="sidebar-widget">
        <h3 class="sidebar-widget-title">Categories</h3>

        <ul class="category-list">
            <li class="category-item">
                <Link
                    href="/shop"
                    :class="['category-link', { active: !currentCategory }]"
                >
                    <span>All Products</span>
                </Link>
            </li>

            <li
                v-for="category in categories"
                :key="category.id"
                class="category-item"
                :class="{ 'current-cat': currentCategory?.slug === category.slug }"
                :style="getCategoryStyle(category.slug)"
            >
                <Link
                    :href="`/shop/category/${category.slug}`"
                    :class="['category-link', { active: currentCategory?.slug === category.slug }]"
                >
                    <span>{{ category.name }}</span>
                    <span class="count" v-if="category.products_count">{{ category.products_count }}</span>
                </Link>

                <ul v-if="category.children?.length" class="subcategory-list">
                    <li
                        v-for="child in category.children"
                        :key="child.id"
                        class="subcategory-item"
                        :style="getCategoryStyle(child.slug)"
                    >
                        <Link
                            :href="`/shop/category/${child.slug}`"
                            :class="['subcategory-link', { active: currentCategory?.slug === child.slug }]"
                        >
                            <span>{{ child.name }}</span>
                            <span class="count" v-if="child.products_count">{{ child.products_count }}</span>
                        </Link>
                    </li>
                </ul>
            </li>
        </ul>
    </div>
</template>

<style scoped>
/* Sidebar Widget */
.sidebar-widget {
    background: var(--clr-white);
    border-radius: var(--radius);
    padding: 25px;
    margin-bottom: 25px;
}

.sidebar-widget:last-child {
    margin-bottom: 0;
}

.sidebar-widget-title {
    font-size: 1.1em;
    font-weight: 600;
    color: var(--clr-text);
    margin: 0 0 15px 0;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.category-list {
    list-style: none;
    margin: 0;
    padding: 0;
}

.category-item {
    margin-bottom: 2px;
}

.category-link,
.subcategory-link {
    color: var(--clr-text-dim);
    text-decoration: none;
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 8px 12px;
    border-radius: var(--radius-sm);
    transition: all 0.2s var(--ease);
    font-size: 0.95em;
}

.category-link:hover,
.subcategory-link:hover {
    background: var(--clr-surface-2);
    color: var(--clr-text);
}

/* Active state with gradient background */
.category-item.current-cat > .category-link,
.category-link.active {
    --category-color: var(--clr-style-default);
    --category-gradient: linear-gradient(
        135deg,
        var(--category-color) 0%,
        color-mix(in srgb, var(--category-color) 60%, white) 100%
    );
    background: var(--category-gradient);
    color: var(--clr-white);
    font-weight: 600;
}

.category-item.current-cat .count,
.category-link.active .count {
    color: rgba(255,255,255,0.8);
}

.count {
    font-size: 0.85em;
    color: var(--clr-text-muted);
}

.subcategory-list {
    list-style: none;
    margin: 0 0 4px;
    padding: 0 0 0 16px;
    border-left: 2px solid var(--clr-border);
    margin-left: 12px;
}

.subcategory-item {
    margin: 0;
}

.subcategory-link {
    font-size: 0.9em;
    padding: 6px 12px;
}

.subcategory-link.active {
    color: var(--category-color, var(--clr-style-default));
    background: transparent;
    font-weight: 600;
}
</style>
