<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { computed, watch } from 'vue';
import ProductPrice from '@/Components/Shop/ProductPrice.vue';
import { useCart } from '@/composables/useCart';
import { useCurrency } from '@/composables/useCurrency';

interface FormData {
    guest_email: string;
    items: Array<{ product_id: number; quantity: number }>;
    display_currency: string;
}

interface Props {
    meta: {
        title: string;
        description: string;
    };
}

const props = defineProps<Props>();
const { items, subtotalCents, clearCart } = useCart();
const { displayCurrency } = useCurrency();

const form = useForm<FormData>({
    guest_email: '',
    items: [],
    display_currency: displayCurrency.value,
});

// Sync items to form when cart changes
watch(
    items,
    (newItems) => {
        form.items = newItems.map(item => ({
            product_id: item.product_id,
            quantity: item.quantity,
        }));
    },
    { deep: true, immediate: true }
);

const submit = () => {
    form.post('/shop/checkout', {
        onSuccess: () => {
            clearCart();
        },
    });
};
</script>

<template>
    <Head>
        <title>{{ meta.title }}</title>
        <meta name="description" :content="meta.description" />
    </Head>

    <div class="checkout-page">
            <h1 class="page-title">Checkout</h1>

            <div v-if="items.length === 0" class="checkout-empty">
                <p>Your cart is empty.</p>
                <Link href="/shop" class="continue-btn">
                    Continue Shopping
                </Link>
            </div>

            <div v-else class="checkout-layout">
                <form class="checkout-form" @submit.prevent="submit">
                    <section class="form-section">
                        <h2>Contact Information</h2>

                        <div class="form-group">
                            <label for="email">Email Address</label>
                            <input
                                id="email"
                                v-model="form.guest_email"
                                type="email"
                                required
                                placeholder="your@email.com"
                                :class="{ error: form.errors.guest_email }"
                            />
                            <span v-if="form.errors.guest_email" class="error-message">
                                {{ form.errors.guest_email }}
                            </span>
                        </div>
                    </section>

                    <section class="form-section">
                        <h2>Order Items</h2>

                        <ul class="item-list">
                            <li
                                v-for="item in items"
                                :key="item.product_id"
                                class="item-row"
                            >
                                <img
                                    v-if="item.thumbnail_path"
                                    :src="`/storage${item.thumbnail_path}`"
                                    :alt="item.title"
                                    class="item-thumb"
                                />
                                <div v-else class="item-thumb placeholder">📄</div>

                                <div class="item-info">
                                    <h3>{{ item.title }}</h3>
                                    <span class="item-qty">Qty: {{ item.quantity }}</span>
                                </div>

                                <ProductPrice
                                    :eur-cents="item.price_cents * item.quantity"
                                />
                            </li>
                        </ul>
                    </section>

                    <div v-if="form.errors.error" class="form-error">
                        {{ form.errors.error }}
                    </div>

                    <button
                        type="submit"
                        class="pay-btn"
                        :disabled="form.processing"
                    >
                        <span v-if="form.processing">Processing...</span>
                        <span v-else>
                            Pay (Stub) {{ displayCurrency === 'EUR' ? '€' : '$' }}
                            {{ (subtotalCents / 100).toFixed(2) }}
                        </span>
                    </button>

                    <p class="pay-note">
                        This is a test checkout. No real payment will be processed.
                    </p>
                </form>

                <aside class="order-summary">
                    <h2>Order Summary</h2>

                    <div class="summary-row">
                        <span>Subtotal</span>
                        <ProductPrice :eur-cents="subtotalCents" />
                    </div>

                    <div class="summary-row">
                        <span>Tax</span>
                        <span>€0.00</span>
                    </div>

                    <div class="summary-row total">
                        <span>Total</span>
                        <ProductPrice :eur-cents="subtotalCents" size="lg" />
                    </div>
                </aside>
            </div>
    </div>
</template>

<style scoped>
.checkout-page {
    max-width: 900px;
    margin: 0 auto;
    padding: 40px 20px;
}

.page-title {
    font-size: 28px;
    font-weight: 700;
    margin: 0 0 32px;
    color: var(--clr-text);
}

.checkout-empty {
    text-align: center;
    padding: 60px 20px;
}

.checkout-empty p {
    color: var(--clr-text-muted);
    margin: 0 0 16px;
}

.continue-btn {
    display: inline-block;
    background: var(--clr-gradient);
    color: var(--clr-white);
    text-decoration: none;
    padding: 14px 28px;
    border-radius: var(--radius-sm);
    font-weight: 700;
}

.checkout-layout {
    display: grid;
    grid-template-columns: 1fr 320px;
    gap: 40px;
    align-items: start;
}

@media (max-width: 768px) {
    .checkout-layout {
        grid-template-columns: 1fr;
        gap: 32px;
    }
}

.form-section {
    margin-bottom: 32px;
}

.form-section h2 {
    font-size: 18px;
    font-weight: 700;
    margin: 0 0 20px;
    color: var(--clr-text);
}

.form-group {
    margin-bottom: 20px;
}

.form-group label {
    display: block;
    font-size: 14px;
    font-weight: 600;
    margin-bottom: 8px;
    color: var(--clr-text);
}

.form-group input {
    width: 100%;
    padding: 12px 16px;
    border: 1px solid var(--clr-border);
    border-radius: var(--radius-sm);
    font-size: 15px;
    transition: border-color 0.2s var(--ease);
}

.form-group input:focus {
    outline: none;
    border-color: var(--clr-accent);
}

.form-group input.error {
    border-color: var(--clr-error);
}

.error-message {
    display: block;
    font-size: 13px;
    color: var(--clr-error);
    margin-top: 4px;
}

.item-list {
    list-style: none;
    margin: 0;
    padding: 0;
}

.item-row {
    display: flex;
    align-items: center;
    gap: 16px;
    padding: 16px 0;
    border-bottom: 1px solid var(--clr-border);
}

.item-row:last-child {
    border-bottom: none;
}

.item-thumb {
    width: 60px;
    height: 60px;
    border-radius: var(--radius-sm);
    object-fit: cover;
    background: var(--clr-surface-2);
}

.item-thumb.placeholder {
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 24px;
}

.item-info {
    flex: 1;
    min-width: 0;
}

.item-info h3 {
    font-size: 15px;
    font-weight: 600;
    margin: 0 0 4px;
    color: var(--clr-text);
}

.item-qty {
    font-size: 13px;
    color: var(--clr-text-muted);
}

.form-error {
    background: color-mix(in srgb, var(--clr-error) 10%, var(--clr-white));
    color: var(--clr-error);
    padding: 12px 16px;
    border-radius: var(--radius-sm);
    margin-bottom: 20px;
    font-size: 14px;
}

.pay-btn {
    width: 100%;
    background: var(--clr-gradient);
    color: var(--clr-white);
    border: none;
    padding: 18px;
    border-radius: var(--radius-sm);
    font-size: 16px;
    font-weight: 700;
    cursor: pointer;
    transition: transform 0.2s var(--ease);
}

.pay-btn:hover:not(:disabled) {
    transform: scale(1.02);
}

.pay-btn:disabled {
    opacity: 0.7;
    cursor: not-allowed;
}

.pay-note {
    text-align: center;
    font-size: 13px;
    color: var(--clr-text-muted);
    margin: 16px 0 0;
}

.order-summary h2 {
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
}

.summary-row.total span {
    color: var(--clr-text);
    font-size: 16px;
}
</style>
