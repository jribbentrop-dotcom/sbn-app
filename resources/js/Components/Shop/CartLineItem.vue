<script setup lang="ts">
import type { CartItem } from '@/types/shop';
import { useCart } from '@/composables/useCart';
import { useCurrency } from '@/composables/useCurrency';

interface Props {
    item: CartItem;
}

const props = defineProps<Props>();
const { setQuantity, removeFromCart } = useCart();
const { formatEurCents } = useCurrency();

const increaseQty = () => setQuantity(props.item.product_id, props.item.quantity + 1);
const decreaseQty = () => setQuantity(props.item.product_id, props.item.quantity - 1);
</script>

<template>
    <div class="cart-line-item">
        <div class="item-image">
            <img
                v-if="item.thumbnail_path"
                :src="item.thumbnail_path"
                :alt="item.title"
            />
            <div v-else class="placeholder-image">📄</div>
        </div>

        <div class="item-details">
            <h4 class="item-title">{{ item.title }}</h4>
            <p class="item-price">{{ formatEurCents(item.price_cents) }}</p>

            <div class="item-controls">
                <div class="quantity-control">
                    <button
                        class="qty-btn"
                        @click="decreaseQty"
                        :disabled="item.quantity <= 1"
                        aria-label="Decrease quantity"
                    >
                        −
                    </button>
                    <span class="qty-value">{{ item.quantity }}</span>
                    <button
                        class="qty-btn"
                        @click="increaseQty"
                        aria-label="Increase quantity"
                    >
                        +
                    </button>
                </div>

                <button
                    class="remove-btn"
                    @click="removeFromCart(item.product_id)"
                    aria-label="Remove item"
                >
                    Remove
                </button>
            </div>
        </div>
    </div>
</template>

<style scoped>
.cart-line-item {
    display: flex;
    gap: 16px;
    padding: 16px 0;
    border-bottom: 1px solid var(--clr-border);
}

.cart-line-item:last-child {
    border-bottom: none;
}

.item-image {
    width: 80px;
    height: 80px;
    flex-shrink: 0;
    border-radius: var(--radius-sm);
    overflow: hidden;
    background: var(--clr-surface-2);
}

.item-image img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}

.placeholder-image {
    width: 100%;
    height: 100%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 24px;
}

.item-details {
    flex: 1;
    min-width: 0;
}

.item-title {
    font-size: 14px;
    font-weight: 600;
    margin: 0 0 4px;
    color: var(--clr-text);
    line-height: 1.3;
    display: -webkit-box;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
    overflow: hidden;
}

.item-price {
    font-size: 14px;
    font-weight: 700;
    color: var(--clr-accent-dim);
    margin: 0 0 8px;
}

.item-controls {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
}

.quantity-control {
    display: flex;
    align-items: center;
    gap: 4px;
}

.qty-btn {
    width: 28px;
    height: 28px;
    border: 1px solid var(--clr-border);
    background: white;
    border-radius: var(--radius-sm);
    cursor: pointer;
    font-size: 16px;
    font-weight: 600;
    color: var(--clr-text);
    display: flex;
    align-items: center;
    justify-content: center;
    transition: all 0.2s ease;
}

.qty-btn:hover:not(:disabled) {
    border-color: var(--clr-accent);
    color: var(--clr-accent);
}

.qty-btn:disabled {
    opacity: 0.5;
    cursor: not-allowed;
}

.qty-value {
    min-width: 32px;
    text-align: center;
    font-size: 14px;
    font-weight: 600;
    color: var(--clr-text);
}

.remove-btn {
    background: none;
    border: none;
    color: var(--clr-text-muted);
    font-size: 13px;
    cursor: pointer;
    text-decoration: underline;
    padding: 4px;
}

.remove-btn:hover {
    color: var(--clr-red);
}
</style>
