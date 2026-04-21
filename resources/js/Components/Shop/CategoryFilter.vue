<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import type { Category } from '@/types/shop';

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
/* Sidebar Widget - matches original .sidebar-widget */
.sidebar-widget {
    background: white;
    border-radius: 12px;
    padding: 25px;
    margin-bottom: 25px;
}

.sidebar-widget:last-child {
    margin-bottom: 0;
}

.sidebar-widget-title {
    font-size: 1.1em;
    font-weight: 600;
    color: var(--sbn-dark, #2d3748);
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
    color: #555;
    text-decoration: none;
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 8px 12px;
    border-radius: 6px;
    transition: all 0.2s ease;
    font-size: 0.95em;
}

.category-link:hover,
.subcategory-link:hover {
    background: #f8f9fa;
    color: var(--sbn-dark, #2d3748);
}

/* Active state with gradient background per original spec */
.category-item.current-cat .category-link,
.category-link.active {
    background: var(--category-gradient, var(--sbn-gradient, linear-gradient(135deg, #f39c12, #e74c3c)));
    color: white;
    font-weight: 600;
}

.category-item.current-cat .count,
.category-link.active .count {
    color: rgba(255,255,255,0.8);
}

.count {
    font-size: 0.85em;
    color: #999;
}

.subcategory-list {
    list-style: none;
    margin: 0 0 4px;
    padding: 0 0 0 16px;
    border-left: 2px solid var(--clr-border, #e2e8f0);
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
    color: var(--sbn-orange, #f39c12);
    font-weight: 600;
}
</style>
