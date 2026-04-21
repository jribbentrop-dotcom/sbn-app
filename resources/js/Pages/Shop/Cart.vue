<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import CartLineItem from '@/Components/Shop/CartLineItem.vue';
import ProductPrice from '@/Components/Shop/ProductPrice.vue';
import { useCart } from '@/composables/useCart';

const { items, count, subtotalCents } = useCart();

interface Props {
    meta: {
        title: string;
        description: string;
    };
}

defineProps<Props>();
</script>

<template>
    <Head>
        <title>{{ meta.title }}</title>
        <meta name="description" :content="meta.description" />
    </Head>

    <div class="cart-page">
            <h1 class="page-title">Shopping Cart ({{ count }})</h1>

            <div v-if="items.length === 0" class="cart-empty">
                <div class="empty-icon">🛒</div>
                <h2>Your cart is empty</h2>
                <p>Looks like you haven't added any products yet.</p>
                <Link href="/shop" class="continue-btn">
                    Continue Shopping
                </Link>
            </div>

            <div v-else class="cart-layout">
                <div class="cart-items">
                    <CartLineItem
                        v-for="item in items"
                        :key="item.product_id"
                        :item="item"
                    />
                </div>

                <aside class="cart-summary">
                    <h2>Order Summary</h2>

                    <div class="summary-row">
                        <span>Subtotal ({{ count }} items)</span>
                        <ProductPrice :eur-cents="subtotalCents" />
                    </div>

                    <div class="summary-row total">
                        <span>Total</span>
                        <ProductPrice :eur-cents="subtotalCents" size="lg" />
                    </div>

                    <Link href="/shop/checkout" class="checkout-btn">
                        Proceed to Checkout
                    </Link>

                    <Link href="/shop" class="continue-link">
                        Continue Shopping
                    </Link>
                </aside>
            </div>
    </div>
</template>

<style scoped>
.cart-page {
    max-width: 1000px;
    margin: 0 auto;
    padding: 40px 20px;
}

.page-title {
    font-size: 28px;
    font-weight: 700;
    margin: 0 0 32px;
    color: var(--clr-text);
}

.cart-empty {
    text-align: center;
    padding: 60px 20px;
}

.empty-icon {
    font-size: 64px;
    margin-bottom: 16px;
}

.cart-empty h2 {
    font-size: 24px;
    font-weight: 700;
    margin: 0 0 8px;
    color: var(--clr-text);
}

.cart-empty p {
    color: var(--clr-text-muted);
    margin: 0 0 24px;
}

.continue-btn {
    display: inline-block;
    background: var(--clr-gradient);
    color: white;
    text-decoration: none;
    padding: 14px 28px;
    border-radius: var(--radius-sm);
    font-weight: 700;
    transition: transform 0.2s ease;
}

.continue-btn:hover {
    transform: scale(1.02);
}

.cart-layout {
    display: grid;
    grid-template-columns: 1fr 340px;
    gap: 40px;
    align-items: start;
}

@media (max-width: 768px) {
    .cart-layout {
        grid-template-columns: 1fr;
        gap: 32px;
    }
}

.cart-items {
    background: white;
    border-radius: var(--radius);
    padding: 24px;
}

.cart-summary {
    background: white;
    border-radius: var(--radius);
    padding: 24px;
    position: sticky;
    top: calc(var(--header-height, 102px) + 20px);
}

.cart-summary h2 {
    font-size: 18px;
    font-weight: 700;
    margin: 0 0 20px;
    color: var(--clr-text);
}

.summary-row {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 12px 0;
    border-bottom: 1px solid var(--clr-border);
    font-size: 15px;
}

.summary-row span:first-child {
    color: var(--clr-text-dim);
}

.summary-row.total {
    border-bottom: none;
    font-weight: 700;
    padding-top: 16px;
    margin-bottom: 20px;
}

.summary-row.total span {
    color: var(--clr-text);
    font-size: 16px;
}

.checkout-btn {
    display: block;
    background: var(--clr-gradient);
    color: white;
    text-decoration: none;
    padding: 16px;
    border-radius: var(--radius-sm);
    font-weight: 700;
    text-align: center;
    margin-bottom: 12px;
    transition: transform 0.2s ease;
}

.checkout-btn:hover {
    transform: scale(1.02);
}

.continue-link {
    display: block;
    text-align: center;
    color: var(--clr-text-muted);
    text-decoration: none;
    font-size: 14px;
}

.continue-link:hover {
    color: var(--clr-accent);
}
</style>
