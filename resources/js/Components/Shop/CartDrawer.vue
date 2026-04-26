<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { useCart } from '@/composables/useCart';
import { useCurrency } from '@/composables/useCurrency';
import CartLineItem from './CartLineItem.vue';

const { items, isOpen, count, subtotalCents, closeCart, clearCart } = useCart();
const { formatEurCents } = useCurrency();
</script>

<template>
    <Teleport to="body">
        <div class="sbn-cart-drawer-wrapper" :class="{ 'is-active': isOpen }">
            <!-- Backdrop -->
            <Transition name="fade">
                <div
                    v-if="isOpen"
                    class="cart-drawer-overlay"
                    @click="closeCart"
                />
            </Transition>

            <!-- Drawer -->
            <Transition name="slide">
                <aside
                    v-if="isOpen"
                    class="cart-drawer is-open"
                    role="dialog"
                    aria-label="Shopping Cart"
                >
                    <div class="cart-header">
                        <h2 class="cart-title">
                            Shopping Cart ({{ count }})
                        </h2>
                        <button
                            class="close-btn"
                            @click="closeCart"
                            aria-label="Close cart"
                        >
                            ×
                        </button>
                    </div>

                    <div v-if="items.length === 0" class="cart-empty">
                        <p>Your cart is empty</p>
                        <Link href="/shop" class="continue-shopping" @click="closeCart">
                            Continue Shopping
                        </Link>
                    </div>

                    <div v-else class="cart-content">
                        <div class="cart-items">
                            <CartLineItem
                                v-for="item in items"
                                :key="item.product_id"
                                :item="item"
                            />
                        </div>

                        <div class="cart-footer">
                            <div class="cart-subtotal">
                                <span>Subtotal</span>
                                <span class="subtotal-amount">{{ formatEurCents(subtotalCents) }}</span>
                            </div>

                            <Link
                                href="/shop/checkout"
                                class="checkout-btn"
                                @click="closeCart"
                            >
                                Proceed to Checkout
                            </Link>

                            <button
                                class="clear-cart-btn"
                                @click="clearCart"
                            >
                                Clear Cart
                            </button>
                        </div>
                    </div>
                </aside>
            </Transition>
        </div>
    </Teleport>
</template>

<style scoped>
.sbn-cart-drawer-wrapper {
    position: fixed;
    inset: 0;
    pointer-events: none;
    z-index: 10000;
}

.sbn-cart-drawer-wrapper.is-active {
    pointer-events: auto;
}

.cart-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 20px;
    border-bottom: 1px solid var(--clr-border);
}

.cart-title {
    font-size: 18px;
    font-weight: 700;
    margin: 0;
    color: var(--clr-text);
}

.close-btn {
    background: none;
    border: none;
    font-size: 28px;
    color: var(--clr-text-muted);
    cursor: pointer;
    line-height: 1;
    padding: 0;
    width: 32px;
    height: 32px;
    display: flex;
    align-items: center;
    justify-content: center;
    border-radius: var(--radius-sm);
    transition: all 0.2s var(--ease);
}

.close-btn:hover {
    background: var(--clr-surface-2);
    color: var(--clr-text);
}

.cart-empty {
    flex: 1;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: 16px;
    padding: 40px;
    text-align: center;
}

.cart-empty p {
    color: var(--clr-text-muted);
    font-size: 16px;
}

.continue-shopping {
    color: var(--clr-accent);
    text-decoration: none;
    font-weight: 600;
}

.continue-shopping:hover {
    text-decoration: underline;
}

.cart-content {
    flex: 1;
    display: flex;
    flex-direction: column;
    overflow: hidden;
}

.cart-items {
    flex: 1;
    overflow-y: auto;
    padding: 16px;
}

.cart-footer {
    padding: 20px;
    border-top: 1px solid var(--clr-border);
    background: var(--clr-white);
    display: flex;
    flex-direction: column;
    gap: 12px;
}

.cart-subtotal {
    display: flex;
    justify-content: space-between;
    align-items: center;
    font-size: 16px;
    font-weight: 600;
    color: var(--clr-text);
}

.subtotal-amount {
    color: var(--clr-accent-dim);
    font-size: 18px;
}

.checkout-btn {
    background: var(--clr-gradient);
    color: var(--clr-white);
    text-decoration: none;
    padding: 14px 24px;
    border-radius: var(--radius-sm);
    font-weight: 700;
    text-align: center;
    transition: transform 0.2s var(--ease);
}

.checkout-btn:hover {
    transform: scale(1.02);
}

.clear-cart-btn {
    background: none;
    border: 1px solid var(--clr-border);
    color: var(--clr-text-dim);
    padding: 10px 24px;
    border-radius: var(--radius-sm);
    font-weight: 600;
    cursor: pointer;
    transition: all 0.2s var(--ease);
}

.clear-cart-btn:hover {
    border-color: var(--clr-red);
    color: var(--clr-red);
}

/* Transitions */
.fade-enter-active,
.fade-leave-active {
    transition: opacity 0.3s var(--ease);
}

.fade-enter-from,
.fade-leave-to {
    opacity: 0;
}

.slide-enter-active,
.slide-leave-active {
    transition: transform 0.3s var(--ease);
}

.slide-enter-from,
.slide-leave-to {
    /* transform: translateX(100%); handled by shop.css class .cart-drawer */
}
</style>
